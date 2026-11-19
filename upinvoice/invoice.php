<?php
/* Copyright (C) 2023-2025
 * Licensed under the GNU General Public License version 3
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME']; $tmp2 = realpath(__FILE__); $i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
    $i--;
    $j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
    $res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
    $res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) {
    $res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
    $res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
    $res = @include "../../../main.inc.php";
}
if (!$res) {
    die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/price.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formother.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
// It's good practice to include product class if dealing with product types/data
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once './class/upinvoicefiles.class.php';
require_once './class/upinvoiceinvoice.class.php';
require_once './class/upinvoiceproductmatcher.class.php';
dol_include_once('/upinvoice/lib/upinvoice.lib.php');

// Control access
if (!$user->rights->facture->lire) accessforbidden();

// Load translations (ensure "Product", "Service", "MatchingProduct" are added to upinvoice.lang)
$langs->loadLangs(array("upinvoice@upinvoice", "bills", "companies", "other", "products"));

// Get file ID
$file_id = GETPOST('file_id', 'int');
if (empty($file_id)) {
    setEventMessages($langs->trans("NoFileSelected"), null, 'errors');
    header("Location: ".dol_buildpath('/upinvoice/upload.php', 1));
    exit;
}

// Initialize objects
$upinvoicefiles = new UpInvoiceFiles($db);
$upinvoiceinvoice = new UpInvoiceInvoice($db);
$form = new Form($db);
$formfile = new FormFile($db);
$formother = new FormOther($db);

// Fetch file record
$result = $upinvoicefiles->fetch($file_id);
if ($result <= 0) {
    setEventMessages($langs->trans("FileNotFound"), null, 'errors');
    header("Location: ".dol_buildpath('/upinvoice/upload.php', 1));
    exit;
}

// Orders have their own page
if ($upinvoicefiles->isOrder()) {
    header("Location: ".dol_buildpath('/upinvoice/order.php', 1)."?file_id=".$upinvoicefiles->id);
    exit;
}

// Check if file has API JSON data
if (empty($upinvoicefiles->api_json)) {
    setEventMessages($langs->trans("FileNotProcessed"), null, 'errors');
    header("Location: ".dol_buildpath('/upinvoice/upload.php', 1));
    exit;
}

// Parse JSON data
$invoice_data = json_decode($upinvoicefiles->api_json, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    setEventMessages($langs->trans("InvalidJSONData").': '.json_last_error_msg(), null, 'errors');
    header("Location: ".dol_buildpath('/upinvoice/upload.php', 1));
    exit;
}

// Product Reference Lookup: links each line with a Dolibarr product by its reference
// (supplier codes of the invoice supplier first, then ref > ref_ext > label; see UpInvoiceProductMatcher)
if (!empty($invoice_data['lines']) && is_array($invoice_data['lines'])) {
    $productMatcher = new UpInvoiceProductMatcher($db);
    $invoice_data['lines'] = $productMatcher->linkLines($invoice_data['lines'], (int) ($upinvoicefiles->fk_supplier ?? 0));
}


// Initialize variables
$action = GETPOST('action', 'alpha');
$warnings = array();
$invoice_id = 0;
$validate_invoice = false; // Variable to control if the invoice should be validated

// Actions handling
if ($action == 'change_supplier') {
    // Remove reference to the current supplier
    if (is_array($invoice_data) && isset($invoice_data['supplier'])) {
        // Keep supplier data but remove its ID
        if (isset($invoice_data['supplier']['id'])) {
            unset($invoice_data['supplier']['id']);
        }
        $upinvoicefiles->api_json = json_encode($invoice_data);
    }

    // Update file record
    $upinvoicefiles->fk_supplier = 0; // Remove supplier relationship
    $upinvoicefiles->import_step = 2; // Go back to supplier selection step
    $result = $upinvoicefiles->update($user);

    if ($result > 0) {
        // Redirect to supplier selection page
        header("Location: ".dol_buildpath('/upinvoice/supplier.php', 1)."?file_id=".$upinvoicefiles->id.'&action=change_supplier_from_invoice');
        exit;
    } else {
        setEventMessages($langs->trans("ErrorUpdatingFile"), null, 'errors');
    }
}

// Check if file has supplier ID
if (empty($upinvoicefiles->fk_supplier)) {
    setEventMessages($langs->trans("NoSupplierSelected"), null, 'errors');
    header("Location: ".dol_buildpath('/upinvoice/supplier.php', 1)."?file_id=".$upinvoicefiles->id);
    exit;
}

// Get supplier data
$supplier = new Fournisseur($db);
$supplier->fetch($upinvoicefiles->fk_supplier);
if ($supplier->id <= 0) {
    setEventMessages($langs->trans("SupplierNotFound"), null, 'errors');
    header("Location: ".dol_buildpath('/upinvoice/supplier.php', 1)."?file_id=".$upinvoicefiles->id);
    exit;
}

// Get payment options from recent invoices
$payment_options = $upinvoiceinvoice->getSupplierPaymentOptions($supplier->id);

// Actions handling
if ($action == 'create_invoice') {
    // Check if validation was requested
    $validate_invoice = (GETPOST('validate_invoice', 'int') == 1);
    // Paying the invoice right away (Dolibarr only accepts payments on validated invoices)
    $register_payment = (GETPOST('register_payment', 'int') == 1);
    if ($register_payment) {
        $validate_invoice = true;
    }

    $date = dol_mktime(0, 0, 0, GETPOST('datemonth', 'none'), GETPOST('dateday', 'none'), GETPOST('dateyear', 'none'));
    if(!$date){
        $date = dol_mktime(0, 0, 0, GETPOST('date_month', 'none'), GETPOST('date_day', 'none'), GETPOST('date_year', 'none'));
    }
    // VAT calculation method chosen in the UI (1=total of round, 2=round of total)
    $calc_method = GETPOSTINT('calc_method');

    // Collect form data
    $form_invoice_data = array(
        'ref_supplier' => GETPOST('ref_supplier', 'alpha'),
        'date' => $date,
        'cond_reglement_id' => GETPOST('cond_reglement_id', 'none'),
        'mode_reglement_id' => GETPOST('mode_reglement_id', 'none'),
        'fk_account' => GETPOST('fk_account', 'int'),
        'note_public' => GETPOST('note_public', 'alpha'),
        'note_private' => GETPOST('note_private', 'alpha'),
        'total_ht' => price2num(GETPOST('total_ht', 'alpha')),
        'total_tva' => price2num(GETPOST('total_tva', 'alpha')),
        'total_ttc' => price2num(GETPOST('total_ttc', 'alpha')),
        'localtax2' => price2num(GETPOST('localtax2', 'alpha')), // IRPF / Retención
        'calc_method' => $calc_method,
        // Extracted (document) totals from the AI JSON — authoritative reference for reconciliation
        'extracted_total_ht'  => isset($invoice_data['total_ht'])  ? $invoice_data['total_ht']  : null,
        'extracted_total_tva' => isset($invoice_data['total_tva']) ? $invoice_data['total_tva'] : null,
        'extracted_total_ttc' => isset($invoice_data['total_ttc']) ? $invoice_data['total_ttc'] : null,
        'lines' => array(),
        'validate' => $validate_invoice, // Pass validation flag to creation function
        'payment' => $register_payment ? array(
            'date' => dol_mktime(12, 0, 0, GETPOSTINT('paydatemonth'), GETPOSTINT('paydateday'), GETPOSTINT('paydateyear')),
            'num_payment' => GETPOST('payment_num', 'alphanohtml'),
        ) : null
    );

    // Process invoice lines
    $line_count = GETPOST('line_count', 'int');
    for ($i = 0; $i < $line_count; $i++) {
        if (empty(GETPOST('line_qty_'.$i, 'alpha')) && empty(GETPOST('line_desc_'.$i, 'alpha'))) {
            $warnings[] = array(
                'message' => 'MissingData',
                'detected' => $langs->trans("Line").' '.($i + 1)
            );
            continue;
        }
        $line = array(
            'product_desc' => GETPOST('line_desc_'.$i, 'alpha'),
            'qty' => price2num(GETPOST('line_qty_'.$i, 'alpha')),
            'pu_ht' => price2num(GETPOST('line_pu_ht_'.$i, 'alpha')),
            'tva_tx' => price2num(GETPOST('line_tva_tx_'.$i, 'alpha')),
            'remise_percent' => price2num(GETPOST('line_remise_percent_'.$i, 'alpha')),
            'total_ht' => price2num(GETPOST('line_total_ht_'.$i, 'alpha')),
            'total_tva' => price2num(GETPOST('line_total_tva_'.$i, 'alpha')),
            'total_ttc' => price2num(GETPOST('line_total_ttc_'.$i, 'alpha')),
            'product_type' => GETPOST('line_type_'.$i, 'none'), // Get product type (0=product, 1=service)
            'fk_product' => GETPOST('line_fk_product_'.$i, 'none') // Get product ID if matched/set
        );
        $form_invoice_data['lines'][] = $line;
    }

    // Create invoice
    $result = $upinvoiceinvoice->createInvoice(
        $form_invoice_data,
        $supplier->id,
        $user,
        $upinvoicefiles->file_path
    );

    if ($result['status'] == 'success') {
        // Update file record with invoice ID
        $upinvoicefiles->fk_invoice = $result['id'];
        $upinvoicefiles->status = 1; // Processed
        $upinvoicefiles->import_step = 4; // Completed
        // Clear import errors
        $upinvoicefiles->import_error = '';
        // Remember the VAT calculation method used to create the invoice
        if (!empty($calc_method)) {
            $upinvoicefiles->calc_method = $calc_method;
        }
        $update_result = $upinvoicefiles->update($user);

        if ($update_result > 0) {
            setEventMessages($result['message'], null);
            // Redirect to upload page (or invoice list)
            header("Location: ".dol_buildpath('/upinvoice/upload.php', 1)."?invoice_created=1");
            exit;
        } else {
            setEventMessages($langs->trans("ErrorUpdatingFile"), null, 'errors');
        }
    } else {
        setEventMessages($result['message'], null, 'errors');
    }
}

// Define page title
$page_name = "InvoiceValidation";
$help_url = '';
$morejs = array(
    '/upinvoice/js/upinvoiceimport.js',
    '/upinvoice/js/upinvoice-product-selector.js', // Add the new product selector JS
    '/upinvoice/js/upinvoice-lines.js', // Lines table: totals, VAT method, reconciliation, add/delete line
);
$morecss = array(
    '/upinvoice/css/upinvoiceimport.css.php'
);

// Page header — hide the left menu to give the document viewer + form full width
$conf->dol_hide_leftmenu = 1;
llxHeader('', $langs->trans($page_name), $help_url, '', 0, 0, $morejs, $morecss);

print load_fiche_titre($langs->trans($page_name), '', 'title_accountancy');

// Display current file and supplier info
print '<div class="upinvoiceimport-container">';

$previewUrl = upinvoice_file_preview_url($upinvoicefiles);
$isPdfDoc = (strpos((string) $upinvoicefiles->file_type, 'pdf') !== false);

// Two-column split: document viewer (left) + form (right)
print '<div class="upinv-split">';

// ---- LEFT 50%: live document viewer (replaces the old "View document" button) ----
print '<div class="upinv-split-left">';
if ($isPdfDoc) {
    print '<iframe src="'.$previewUrl.'" class="upinv-doc-frame" title="'.dol_escape_htmltag($upinvoicefiles->original_filename).'"></iframe>';
} else {
    print '<div class="upinv-doc-imgwrap"><img src="'.$previewUrl.'" class="upinv-doc-img" alt="'.dol_escape_htmltag($upinvoicefiles->original_filename).'"></div>';
}
print '<div class="upinv-doc-foot opacitymedium small">'.dol_escape_htmltag($upinvoicefiles->original_filename).' &middot; '.dol_print_date($upinvoicefiles->date_creation, 'dayhour').'</div>';
print '</div>'; // Close upinv-split-left

// ---- RIGHT 50%: compact form ----
print '<div class="upinv-split-right">';

// Compact supplier strip
print '<div class="upinv-supplier-strip">';
print '<a href="'.$_SERVER['PHP_SELF'].'?action=change_supplier&file_id='.$upinvoicefiles->id.'" class="butAction butActionDelete butActionSmall" style="float:right">';
print '<i class="fas fa-exchange-alt"></i> '.$langs->trans("ChangeSupplier").'</a>';
print '<span class="upinv-supplier-name">'.dol_escape_htmltag($supplier->name).'</span>';
if (!empty($supplier->idprof1)) print ' <span class="opacitymedium">&middot; '.dol_escape_htmltag($supplier->idprof1).'</span>';
if (!empty($supplier->tva_intra)) print ' <span class="opacitymedium">&middot; '.dol_escape_htmltag($supplier->tva_intra).'</span>';
if ($supplier->id > 0) {
    print ' <a href="'.DOL_URL_ROOT.'/fourn/facture/list.php?socid='.((int) $supplier->id).'" target="_blank" class="upinv-link" title="'.dol_escape_htmltag($langs->trans("ViewSupplierInvoices")).'">&middot; <i class="fas fa-file-invoice"></i> '.$langs->trans("ViewSupplierInvoices").'</a>';
}
$supAddr = trim($supplier->address.', '.$supplier->zip.' '.$supplier->town, ', ');
if (!empty($supAddr)) print '<div class="opacitymedium small">'.dol_escape_htmltag($supAddr).'</div>';
print '</div>';

// Start invoice form
print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '" name="invoice_form" id="invoice_form">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="create_invoice">';
print '<input type="hidden" name="file_id" value="' . $upinvoicefiles->id . '">';

// Header + totals (stacked, compact) inside the right column
print '<div class="upinv-form">';

// Display warnings if any
if (!empty($warnings)) {
    print '<div class="upinvoiceimport-warnings">';
    print '<h3>' . $langs->trans("WarningsDetected") . '</h3>';

    foreach ($warnings as $warning) {
        print '<div class="upinvoiceimport-warning">';
        print '<i class="fas fa-exclamation-triangle"></i> ';
        print $langs->trans($warning['message']) . ': ';
        print $langs->trans("Detected") . ': <strong>' . $warning['detected'] . '</strong>, ';
        print $langs->trans("Entered") . ': <strong>' . $warning['entered'] . '</strong>';
        print '</div>';
    }

    print '</div>';
}

// Invoice header, with the payment box in the free space on its right
print '<div class="upinv-header-row">';
print '<table class="border upinv-header-table">';

// Invoice reference
print '<tr>';
print '<td class="titlefieldcreate fieldrequired">' . $langs->trans("RefSupplier") . '</td>';
print '<td>';
print '<input type="text" name="ref_supplier" value="' . dol_escape_htmltag($invoice_data['ref_supplier'] ?? '') . '" required>';
print '</td>';
print '</tr>';

// Invoice date
print '<tr>';
print '<td class="fieldrequired">' . $langs->trans("InvoiceDate") . '</td>';
print '<td>';
$invoiceDate = !empty($invoice_data['date']) ? dol_stringtotime($invoice_data['date']) : dol_now();
print $form->selectDate($invoiceDate, 'date', '', '', '', "invoice_form", 1, 1);
print '</td>';
print '</tr>';

// Payment terms
print '<tr>';
print '<td>' . $langs->trans("PaymentConditionsShort") . '</td>';
print '<td>';
// Use proper payment terms selection function with preselection based on most common value
$form->select_conditions_paiements($payment_options['cond_reglement_id'], 'cond_reglement_id', -1, 1);
print '</td>';
print '</tr>';

// Payment mode
print '<tr>';
print '<td>' . $langs->trans("PaymentMode") . '</td>';
print '<td>';
// Use proper payment mode selection function with preselection based on most common value
$form->select_types_paiements($payment_options['mode_reglement_id'], 'mode_reglement_id', '', 0, 1, 0, 0, 1);
print '</td>';
print '</tr>';

// Bank account
print '<tr>';
print '<td>' . $langs->trans("BankAccount") . '</td>';
print '<td>';
// Use proper bank account selection function with preselection based on most common value
$form->select_comptes($payment_options['fk_account'], 'fk_account', 0, '', 1);
print '</td>';
print '</tr>';

// Notes (collapsed by default to save vertical space)
print '<tr>';
print '<td><a href="#" id="upinv-notes-toggle" class="upinv-link">' . $langs->trans("Notes") . ' <i class="fas fa-chevron-down"></i></a></td>';
print '<td>';
print '<div id="upinv-notes-row" style="display:none">';
print '<textarea name="note_public" rows="2" class="flat quatrevingtpercent" placeholder="' . $langs->trans("PublicNotes") . '"></textarea>';
print '<textarea name="note_private" rows="2" class="flat quatrevingtpercent" placeholder="' . $langs->trans("PrivateNotes") . '" style="margin-top:4px"></textarea>';
print '</div>';
print '</td>';
print '</tr>';

print '</table>';

// Register the payment together with the invoice (handy when entering invoices while reconciling the bank)
if (!empty($user->rights->fournisseur->facture->creer)) {
    $register_payment_checked = ($action == 'create_invoice' && GETPOST('register_payment', 'int') == 1);
    $paydate = dol_mktime(12, 0, 0, GETPOSTINT('paydatemonth'), GETPOSTINT('paydateday'), GETPOSTINT('paydateyear'));
    print '<div class="upinv-payment-box'.($register_payment_checked ? ' upinv-payment-on' : '').'">';
    print '<label class="upinv-payment-toggle"><input type="checkbox" name="register_payment" id="register_payment" value="1"'.($register_payment_checked ? ' checked' : '').'> ';
    print $langs->trans("UpInvoiceRegisterPayment").'</label>';
    print '<div class="upinv-payment-fields"'.($register_payment_checked ? '' : ' style="display:none"').'>';
    print '<div class="upinv-payment-field"><span class="fieldrequired">'.$langs->trans("UpInvoicePaymentDate").'</span>';
    print '<span class="upinv-payment-date">';
    print $form->selectDate($paydate ? $paydate : dol_now(), 'paydate', 0, 0, 0, "invoice_form", 1, 1);
    print '<span class="opacitymedium">|</span>';
    print '<button type="button" id="upinv-paydate-from-invoice" class="dpInvisibleButtons datenowlink">'.$langs->trans("UpInvoiceInvoiceDateShort").'</button>';
    print '</span>';
    print '</div>';
    print '<div class="upinv-payment-field"><span>'.$langs->trans("UpInvoicePaymentNum").'</span>';
    print '<input type="text" name="payment_num" value="'.dol_escape_htmltag(GETPOST('payment_num', 'alphanohtml')).'" class="flat">';
    print '</div>';
    print '<div class="upinv-payment-field"><span>'.$langs->trans("Amount").'</span><strong id="upinv-payment-amount"></strong></div>';
    print '<div class="opacitymedium small">'.$langs->trans("UpInvoicePaymentHelp").'</div>';
    print '</div>';
    print '</div>';
}
print '</div>'; // Close upinv-header-row

// Invoice totals (stacked under the header)
print '<table class="border centpercent upinv-totals">';

// Total HT
print '<tr class="liste_titre">';
print '<td class="titlefieldcreate">' . $langs->trans("TotalHT") . '</td>';
print '<td class="right">';
print '<input type="text" name="total_ht" id="total_ht" class="flat right" value="'.price($invoice_data['total_ht'] ?? 0).'" readonly>';
print '</td>';
print '</tr>';

// Total VAT (with recalculation method toggle, like Dolibarr's Mode1/Mode2)
print '<tr>';
print '<td>' . $langs->trans("TotalVAT");
print ' <span class="upinv-recalc" title="' . dol_escape_htmltag($langs->trans("UpInvoiceMethodHelp")) . '">';
print '<span class="opacitymedium small">' . $langs->trans("UpInvoiceRecalc") . ':</span> ';
print '<a href="#" class="upinv-method-btn" data-method="1">' . $langs->trans("UpInvoiceMethod1") . '</a> / ';
print '<a href="#" class="upinv-method-btn" data-method="2">' . $langs->trans("UpInvoiceMethod2") . '</a>';
print '</span>';
print '</td>';
print '<td class="right">';
print '<input type="text" name="total_tva" id="total_tva" class="flat right" value="'.price($invoice_data['total_tva'] ?? 0).'">';
print '</td>';
print '</tr>';

// --- START: LocalTax2 (IRPF/Retención) ---
// Se muestra si viene en el JSON o si el usuario quiere añadirlo manualmente
$val_localtax2 = isset($invoice_data['localtax2']) ? $invoice_data['localtax2'] : 0;
if ($val_localtax2 !== 0) {
    print '<tr>';
    // Usamos TotalLT2 que suele corresponder a IRPF en configuraciones ES, o un texto genérico
    print '<td>' . $langs->trans("Retencion") . '</td>';
    print '<td class="right">';
    // Importante: onchange para recalcular totales
    print '<input type="text" name="localtax2" id="localtax2" class="flat right" value="'.price($val_localtax2).'" onchange="updateInvoiceTotals()">';
    print '</td>';
    print '</tr>';
}
// --- END: LocalTax2 ---

// Total TTC
print '<tr class="liste_titre">';
print '<td>' . $langs->trans("TotalTTC") . '</td>';
print '<td class="right">';
print '<input type="text" name="total_ttc" id="total_ttc" class="flat right" value="'.price($invoice_data['total_ttc'] ?? 0).'">';
print '</td>';
print '</tr>';

print '</table>';

// VAT method chosen + reconciliation warning vs the extracted document totals
print '<input type="hidden" name="calc_method" id="calc_method" value="">';
print '<div id="upinv-reconcile-warning" class="upinv-reconcile" style="display:none"></div>';

print '</div>'; // Close upinv-form

// Invoice lines card (shared with orders)
$upinv_lines = $invoice_data['lines'] ?? array();
include __DIR__.'/tpl/lines_form.tpl.php';


// Submit buttons: Cancel on the left, create actions on the right (validate = far right with check)
print '<div class="upinv-form-actions">';
print '<a href="'.dol_buildpath('/upinvoice/upload.php',1).'" class="button buttonRefused upinv-cancel">' . $langs->trans("Cancel") . '</a>';
print '<input type="submit" class="button upinv-btn-amber" name="create_only" value="' . $langs->trans("CreateInvoice") . '">';
print '<button type="submit" class="button upinv-btn-green" name="create_validate" value="1" onclick="document.getElementById(\'validate_invoice\').value=\'1\';"><i class="fas fa-check"></i> ' . $langs->trans("CreateAndValidateInvoice") . '</button>';
print '</div>';

// Hidden field to indicate whether to validate or not
print '<input type="hidden" id="validate_invoice" name="validate_invoice" value="0">';

print '</form>';

print '<input type="hidden" id="max_input_vars" value="'.ini_get('max_input_vars').'">';

print '</div>'; // Close upinv-split-right
print '</div>'; // Close upinv-split

// Close container
print '</div>';

// Add JavaScript for managing invoice lines and calculations
?>
<script type="text/javascript">
    // Make sure PHP variables used by JS are defined *before* the script that uses them
    var upinvoiceimport_root = '<?php echo dirname($_SERVER['PHP_SELF']); ?>';
    var upinvoiceimport_token = '<?php echo newToken(); ?>';
    var upinvoiceimport_socid = <?php echo (int) $supplier->id; ?>;
    var upinvoiceimport_decimal_separator = '<?php echo dol_escape_js($langs->transnoentitiesnoconv("SeparatorDecimal")); ?>';
    var upinvoiceimport_langs = {
        'Searching': '<?php echo dol_escape_js($langs->trans("Searching")); ?>',
        'FoundSuppliers': '<?php echo dol_escape_js($langs->trans("FoundSuppliers")); ?>',
        'Name': '<?php echo dol_escape_js($langs->trans("Name")); ?>',
        'TaxIDs': '<?php echo dol_escape_js($langs->trans("TaxIDs")); ?>',
        'Address': '<?php echo dol_escape_js($langs->trans("Address")); ?>',
        'Actions': '<?php echo dol_escape_js($langs->trans("Actions")); ?>',
        'SelectThisSupplier': '<?php echo dol_escape_js($langs->trans("SelectThisSupplier")); ?>',
        'NoSuppliersFound': '<?php echo dol_escape_js($langs->trans("NoSuppliersFound")); ?>',
        'ErrorProcessingResponse': '<?php echo dol_escape_js($langs->trans("ErrorProcessingResponse")); ?>',
        'SearchFailed': '<?php echo dol_escape_js($langs->trans("SearchFailed")); ?>',
        'ErrorConfirmingSupplier': '<?php echo dol_escape_js($langs->trans("ErrorConfirmingSupplier")); ?>',
        'CannotDeleteLastLine': '<?php echo dol_escape_js($langs->trans("CannotDeleteLastLine")); ?>',
        'Product': '<?php echo dol_escape_js($langs->trans("Product")); ?>',
        'Service': '<?php echo dol_escape_js($langs->trans("Service")); ?>',
        'SelectProduct': '<?php echo dol_escape_js($langs->trans("SelectProduct")); ?>',
        'SearchProduct': '<?php echo dol_escape_js($langs->trans("SearchProduct")); ?>',
        'NoProductsFound': '<?php echo dol_escape_js($langs->trans("NoProductsFound")); ?>',
        'EnterSearchTerm': '<?php echo dol_escape_js($langs->trans("EnterSearchTerm")); ?>',
        'SearchTermTooShort': '<?php echo dol_escape_js($langs->trans("SearchTermTooShort")); ?>',
        'All': '<?php echo dol_escape_js($langs->trans("All")); ?>',
        'Search': '<?php echo dol_escape_js($langs->trans("Search")); ?>',
        'Select': '<?php echo dol_escape_js($langs->trans("Select")); ?>',
        'MatchingProduct': '<?php echo dol_escape_js($langs->trans("MatchingProduct")); ?>',
        'Reference': '<?php echo dol_escape_js($langs->trans("Reference")); ?>',
        'Label': '<?php echo dol_escape_js($langs->trans("Label")); ?>',
        'Price': '<?php echo dol_escape_js($langs->trans("Price")); ?>',
        'VATRate': '<?php echo dol_escape_js($langs->trans("VATRate")); ?>',
        'PaymentNeedsValidation': '<?php echo dol_escape_js($langs->transnoentitiesnoconv("UpInvoicePaymentNeedsValidation")); ?>',
        'SupplierBuyPrice': '<?php echo dol_escape_js($langs->trans("UpInvoiceSupplierBuyPrice")); ?>',
        'NoSupplierPrice': '<?php echo dol_escape_js($langs->trans("UpInvoiceNoSupplierPrice")); ?>',
        'CostPrice': '<?php echo dol_escape_js($langs->trans("UpInvoiceCostPrice")); ?>',
        'ConfirmDeleteFile': '<?php echo $langs->trans("ConfirmDeleteFile"); ?>',
        'ConfirmReplacePrice': '<?php echo dol_escape_js($langs->transnoentitiesnoconv("ConfirmReplacePrice")); ?>',
        'ConfirmReplaceVAT': '<?php echo dol_escape_js($langs->transnoentitiesnoconv("ConfirmReplaceVAT")); ?>',
        'Current': '<?php echo dol_escape_js($langs->trans("Current")); ?>',
        'New': '<?php echo dol_escape_js($langs->trans("New")); ?>'
    };

    // Extracted (document) totals — reference for auto-picking the VAT method and reconciling
    var upinvoiceExtracted = {
        total_ht:  <?php echo (float) ($invoice_data['total_ht'] ?? 0); ?>,
        total_tva: <?php echo (float) ($invoice_data['total_tva'] ?? 0); ?>,
        total_ttc: <?php echo (float) ($invoice_data['total_ttc'] ?? 0); ?>,
        localtax2: <?php echo (float) ($invoice_data['localtax2'] ?? 0); ?>
    };
    var upinvoiceMethodLangs = {
        match:     '<?php echo dol_escape_js($langs->trans("UpInvoiceAmountsMatch")); ?>',
        adjusted:  '<?php echo dol_escape_js($langs->trans("UpInvoiceAmountAdjusted")); ?>',
        mismatch:  '<?php echo dol_escape_js($langs->trans("UpInvoiceAmountMismatch")); ?>',
        extracted: '<?php echo dol_escape_js($langs->trans("UpInvoiceExtracted")); ?>',
        computed:  '<?php echo dol_escape_js($langs->trans("UpInvoiceComputed")); ?>',
        diff:      '<?php echo dol_escape_js($langs->trans("UpInvoiceDifference")); ?>',
        base:      '<?php echo dol_escape_js($langs->trans("TaxBaseShort")); ?>',
        vat:       '<?php echo dol_escape_js($langs->trans("VAT")); ?>',
        total:     '<?php echo dol_escape_js($langs->trans("TotalTTC")); ?>'
    };
</script>

<script type="text/javascript">
$(document).ready(function() {
    // Collapsible notes
    $('#upinv-notes-toggle').on('click', function(e) {
        e.preventDefault();
        $('#upinv-notes-row').slideToggle(120);
        $(this).find('i').toggleClass('fa-chevron-down fa-chevron-up');
    });

    // Registering the payment needs a validated invoice: the draft button is disabled meanwhile
    $('#register_payment').on('change', function() {
        var on = $(this).is(':checked');
        $('.upinv-payment-fields').toggle(on);
        $('.upinv-payment-box').toggleClass('upinv-payment-on', on);
        $("input[name='create_only']").prop('disabled', on).attr('title', on ? upinvoiceimport_langs.PaymentNeedsValidation : '');
        upinvSyncPaymentAmount();
    }).trigger('change');
    $('#total_ttc').on('change input', upinvSyncPaymentAmount);
    // Payment date = invoice date (the "Now" link next to it gives today)
    $('#upinv-paydate-from-invoice').on('click', function(e) {
        e.preventDefault();
        $('#paydate').val($('#date').val());
        $.each(['day', 'month', 'year'], function(i, part) {
            $('#paydate' + part).val($('#date' + part).val());
        });
    });

    // Manage "Create Invoice" button (without validating)
    $("input[name='create_only']").click(function() {
        // Ensure validate_invoice field is 0
        $('#validate_invoice').val('0');
        // No return false needed, let the form submit
        max_input_vars_check();
    });
});

// Amount of the payment = invoice total (shown in the payment box)
function upinvSyncPaymentAmount() {
    $('#upinv-payment-amount').text($('#total_ttc').val());
}


</script>
<?php
// Footer
llxFooter();
$db->close();
?>