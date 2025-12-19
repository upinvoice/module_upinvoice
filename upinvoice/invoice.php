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

// --- START: Product Reference Lookup ---
// Process lines and search for product matches by reference
if (!empty($invoice_data['lines']) && is_array($invoice_data['lines'])) {
    foreach ($invoice_data['lines'] as $key => $line) {
        // Check if the line has a product reference
        if (!empty($line['product_ref'])) {
            // Search product by reference
            $sql = "SELECT p.rowid, p.ref, p.label, p.description, p.price, p.price_ttc, p.tva_tx, p.fk_product_type";
            $sql .= " FROM ".MAIN_DB_PREFIX."product as p";
            $sql .= " WHERE p.ref = '".$db->escape($line['product_ref'])."'";
            $sql .= " AND p.entity IN (".getEntity('product').")";

            $resql = $db->query($sql);
            if ($resql && $db->num_rows($resql) > 0) {
                $product_obj = $db->fetch_object($resql);

                // Check if product type matches (if provided in JSON)
                $product_type_matches = true;
                // Note: We use fk_product_type from the found product for consistency later
                // If the JSON provides a type, we check if it matches the database product type.
                if (isset($line['product_type']) && $product_obj->fk_product_type != $line['product_type']) {
                    $product_type_matches = false;
                    // Optional: Add a warning if types don't match?
                }

                if ($product_type_matches) {
                    // Save the product ID and label in the line data
                    $invoice_data['lines'][$key]['fk_product'] = $product_obj->rowid;
                    $invoice_data['lines'][$key]['product_label'] = $product_obj->label;
                    // Ensure the line type reflects the matched product's type
                    $invoice_data['lines'][$key]['product_type'] = $product_obj->fk_product_type;
                }
                $db->free($resql);
            }
        }
    }
}
// --- END: Product Reference Lookup ---


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

    $date = dol_mktime(0, 0, 0, GETPOST('datemonth', 'none'), GETPOST('dateday', 'none'), GETPOST('dateyear', 'none'));
    if(!$date){
        $date = dol_mktime(0, 0, 0, GETPOST('date_month', 'none'), GETPOST('date_day', 'none'), GETPOST('date_year', 'none'));
    }
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
        'lines' => array(),
        'validate' => $validate_invoice // Pass validation flag to creation function
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
);
$morecss = array(
    '/upinvoice/css/upinvoiceimport.css'
);

// Page header
llxHeader('', $langs->trans($page_name), $help_url, '', 0, 0, $morejs, $morecss);

print load_fiche_titre($langs->trans($page_name), '', 'title_accountancy');

// Display current file and supplier info
print '<div class="upinvoiceimport-container">';

// File info card
print '<table class="noborder centpercent">';
print '<tr class="oddeven">';
print '<td width="50%">';
$previewUrl = dol_buildpath('/viewimage.php', 1).'?modulepart=upinvoice&file=temp/'.urlencode(basename($upinvoicefiles->file_path)).'&cache=0';
print '<strong>' . $langs->trans("FileName") . ':</strong> ' . dol_escape_htmltag($upinvoicefiles->original_filename);
print '<div style="float:right">';
print '<button id="preview-doc-btn" class="butAction" ';
print 'data-file-path="'.$previewUrl.'" ';
print 'data-file-type="'.$upinvoicefiles->file_type.'" ';
print 'data-file-name="'.$upinvoicefiles->original_filename.'">';
print '<i class="fas fa-eye"></i> ' . $langs->trans("ViewDocument") . '</button>';
print '</div><br>';
print '<strong>' . $langs->trans("UploadDate") . ':</strong> ' . dol_print_date($upinvoicefiles->date_creation, 'dayhour');
print '</td>';
print '<td width="50%">';
print '<strong>' . $langs->trans("Name") . ':</strong> ' . $supplier->name;

// Button to change supplier
print ' <a href="' . $_SERVER['PHP_SELF'] . '?action=change_supplier&file_id=' . $upinvoicefiles->id . '" class="butAction butActionDelete butActionSmall">';
print '<i class="fas fa-exchange-alt"></i> ' . $langs->trans("ChangeSupplier") . '</a><br>';

if (!empty($supplier->idprof1)) print '<strong>' . $langs->trans("ProfId1") . ':</strong> ' . $supplier->idprof1 . '<br>';
if (!empty($supplier->tva_intra)) print '<strong>' . $langs->trans("VATIntra") . ':</strong> ' . $supplier->tva_intra . '<br>';
print '<strong>' . $langs->trans("Address") . ':</strong> ' . $supplier->address . ', ' . $supplier->zip . ' ' . $supplier->town;
print '</td>';
print '</tr>';
print '</table>';

// Document data for preview
$documentPreviewUrl = dol_buildpath('/viewimage.php', 1).'?modulepart=upinvoice&file=temp/'.urlencode(basename($upinvoicefiles->file_path)).'&cache=0';
print '<input type="hidden" id="document-preview-path" value="'.$documentPreviewUrl.'">';
print '<input type="hidden" id="document-preview-type" value="'.$upinvoicefiles->file_type.'">';

// Start invoice form
print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '" name="invoice_form" id="invoice_form">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="create_invoice">';
print '<input type="hidden" name="file_id" value="' . $upinvoicefiles->id . '">';

// Invoice data card
print '<div class="fichecenter">';
print '<div class="fichehalfleft">';

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

// Invoice header
print '<table class="border centpercent">';

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

// Notes
print '<tr>';
print '<td>' . $langs->trans("Notes") . '</td>';
print '<td>';
print '<textarea name="note_public" rows="3" class="flat quatrevingtpercent" placeholder="' . $langs->trans("PublicNotes") . '"></textarea>';
print '<br>';
print '<textarea name="note_private" rows="3" class="flat quatrevingtpercent" placeholder="' . $langs->trans("PrivateNotes") . '"></textarea>';
print '</td>';
print '</tr>';

print '</table>';

print '</div>'; // Close fichehalfleft


print '<div class="fichehalfright">';

// Invoice totals
print '<table class="border centpercent">';

// Total HT
print '<tr class="liste_titre">';
print '<td class="titlefieldcreate">' . $langs->trans("TotalHT") . '</td>';
print '<td class="right">';
print '<input type="text" name="total_ht" id="total_ht" class="flat right" value="'.price($invoice_data['total_ht'] ?? 0).'" readonly>';
print '</td>';
print '</tr>';

// Total VAT
print '<tr>';
print '<td>' . $langs->trans("TotalVAT") . '</td>';
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

print '</div>'; // Close fichehalfright

print '</div>'; // Close fichecenter

print '<div class="clearboth"></div><br>';

// Invoice lines card
print '<div class="upinvoiceimport-invoice-card">';
print '<div class="upinvoiceimport-card-body">';

// Invoice lines table
print '<table class="noborder centpercent" id="invoice_lines_table">';
print '<tr class="liste_titre">';
print '<th class="linecoldescription minwidth300imp">' . $langs->trans("Description") . '</th>';
print '<th class="center">' . $langs->trans("VAT") . '</th>';
print '<th class="center">' . $langs->trans("Qty") . '</th>';
print '<th class="center">' . $langs->trans("PriceUHT") . '</th>';
print '<th class="center">' . $langs->trans("Discount") . '</th>';
print '<th class="right">' . $langs->trans("TotalHT") . '</th>';
print '<th class="right">' . $langs->trans("TotalVAT") . '</th>';
print '<th class="right">' . $langs->trans("TotalTTC") . '</th>';
print '<th class="center">' . $langs->trans("Actions") . '</th>';
print '</tr>';

// Lines from JSON data
$line_count = 0;
if (!empty($invoice_data['lines']) && is_array($invoice_data['lines'])) {
    foreach ($invoice_data['lines'] as $line) {
        $line_product_type = isset($line['product_type']) ? $line['product_type'] : 0; // Default to product=0
        print '<tr class="invoice-line" id="line_row_'.$line_count.'">';

        // Description + Product selection/info
        print '<td>';

        // --- START: Display Matched Product Info or Product Selector ---
        // Check if product matching was found
        if (!empty($line['fk_product'])) {
            // Product type selector - visible incluso con producto seleccionado
            print '<div class="product-type-selector" style="margin-bottom:5px;">';
            print '<select id="line_type_' . $line_count . '" name="line_type_' . $line_count . '" class="flat" style="padding: 6px 5px; height: auto;">';
            print '<option value="0"' . ($line_product_type == 0 ? ' selected' : '') . '>' . $langs->trans("Product") . '</option>';
            print '<option value="1"' . ($line_product_type == 1 ? ' selected' : '') . '>' . $langs->trans("Service") . '</option>';
            print '</select>';
            print '</div>';
            
            // Hidden field for product ID
            print '<input type="hidden" name="line_fk_product_'.$line_count.'" value="'.intval($line['fk_product']).'">';
            
            // Select2 producto con valor preseleccionado
            print '<div class="product-select-container" style="margin-bottom:5px;">';
            print '<select id="line_product_' . $line_count . '" name="line_product_' . $line_count . '" class="product-select2" data-line="' . $line_count . '" style="width: 100%;">';
            // Se añade una opción con el valor preseleccionado para inicializar Select2 con ese valor
            print '<option value="'.intval($line['fk_product']).'" selected="selected">' . dol_escape_htmltag($line['product_ref'] . ' - ' . $line['product_label']) . '</option>';
            print '</select>';
            print '</div>';
            
            // Display product reference and label if found
            print '<div class="product-match-info" style="margin-bottom:5px;">';
            print '<span class="badge badge-info" style="border-radius: .25rem; padding: 5px; display: inline-block; margin-bottom: 5px;">'; // Basic styling
            // Use product type from line data (which should reflect the matched product)
            $product_type_icon = ($line_product_type == 1 ? 'concierge-bell' : 'cube'); // 1=Service, 0=Product
            print ' <i class="fas fa-' . $product_type_icon . '" title="' . ($line_product_type == 1 ? $langs->trans("Service") : $langs->trans("Product")) . '"></i>&nbsp; ';
            print $langs->trans("MatchingProduct") . ': ';
            print '<strong>' . dol_escape_htmltag($line['product_ref']) . '</strong>'; // Display ref used for matching
            if (!empty($line['product_label'])) {
                print ' - ' . dol_escape_htmltag($line['product_label']); // Display fetched label
            }
            print '</span>';
            print '</div>';
        } else {
            // --- START: Product Type Selector and Product Select2 for non-matched/new lines ---
            print '<div class="product-type-selector" style="margin-bottom:5px;">';
            print '<select id="line_type_' . $line_count . '" name="line_type_' . $line_count . '" class="flat" style="padding: 6px 5px; height: auto;">';
            print '<option value="0"' . ($line_product_type == 0 ? ' selected' : '') . '>' . $langs->trans("Product") . '</option>';
            print '<option value="1"' . ($line_product_type == 1 ? ' selected' : '') . '>' . $langs->trans("Service") . '</option>';
            print '</select>';
            print '</div>';
            
            // Select2 for product selection
            print '<div class="product-select-container" style="margin-bottom:5px;">';
            print '<select id="line_product_' . $line_count . '" name="line_product_' . $line_count . '" class="product-select2" data-line="' . $line_count . '" style="width: 100%;">';
            print '<option value=""></option>'; // Empty option for placeholder
            print '</select>';
            print '</div>';
            
            // Hidden field for product ID (0 for non-matched products)
            print '<input type="hidden" name="line_fk_product_'.$line_count.'" value="0">';
        }
         // --- END: Display Matched Product Info or Product Selector ---

        print '<textarea name="line_desc_'.$line_count.'" class="flat width100p" style="margin-top: 5px; width: 98%">'.dol_escape_htmltag($line['product_desc'] ?? '').'</textarea>';
        print '</td>';

        // VAT rate
        print '<td class="center">';
        print '<input type="text" size="5" name="line_tva_tx_'.$line_count.'" value="'.price(isset($line['tva_tx']) ? $line['tva_tx'] : 0).'" class="flat tvaline right" onchange="updateLineTotals('.$line_count.')">';
        print '</td>';

        // Quantity
        print '<td class="center">';
        print '<input type="text" size="5" name="line_qty_'.$line_count.'" value="'.(isset($line['qty']) ? $line['qty'] : 1).'" class="flat qtyline right" onchange="updateLineTotals('.$line_count.')">';
        print '</td>';

        // Unit price
        print '<td class="center">';
        print '<input type="text" size="8" name="line_pu_ht_'.$line_count.'" value="'.price(isset($line['pu_ht']) ? $line['pu_ht'] : 0).'" class="flat puhline right" onchange="updateLineTotals('.$line_count.')">';
        print '</td>';

        // Discount
        print '<td class="center">';
        print '<input type="text" size="5" name="line_remise_percent_'.$line_count.'" value="'.price(isset($line['remise_percent']) ? $line['remise_percent'] : 0).'" class="flat remisepercentline right" onchange="updateLineTotals('.$line_count.')">';
        print '</td>';

        // Total HT
        print '<td class="right">';
        print '<input type="text" size="8" name="line_total_ht_'.$line_count.'" value="'.price(isset($line['total_ht']) ? $line['total_ht'] : 0).'" class="flat totalhtline right" readonly>';
        print '</td>';

        // Total VAT
        print '<td class="right">';
        print '<input type="text" size="8" name="line_total_tva_'.$line_count.'" value="'.price(isset($line['total_tva']) ? $line['total_tva'] : 0).'" class="flat totalvaline right">';
        print '</td>';

        // Total TTC
        print '<td class="right">';
        print '<input type="text" size="8" name="line_total_ttc_'.$line_count.'" value="'.price(isset($line['total_ttc']) ? $line['total_ttc'] : 0).'" class="flat totalttcline right">';
        print '</td>';

        // Actions
        print '<td class="center">';
        print '<a href="#" class="delete-line" data-line="'.$line_count.'"><i class="fas fa-trash"></i></a>';
        print '</td>';

        print '</tr>';
        $line_count++;
    }
}

print '</table>';

// Add line button
print '<div class="invoice-actions">';
print '<a href="#" class="butAction" id="add-line-btn">' . $langs->trans("AddLine") . '</a>';
print '</div>';

// Input to keep track of line count
print '<input type="hidden" name="line_count" id="line_count" value="'.$line_count.'">';

print '</div>'; // Close card body
print '</div>'; // Close card


// Submit buttons - Modified to differentiate buttons
print '<div class="center">';
print '<input type="submit" class="button" name="create_only" value="' . $langs->trans("CreateInvoice") . '">';
print ' &nbsp; ';
print '<input type="submit" class="button" name="create_validate" value="' . $langs->trans("CreateAndValidateInvoice") . '" onclick="document.getElementById(\'validate_invoice\').value=\'1\';">';
print ' &nbsp; ';
print '<a href="'.dol_buildpath('/upinvoice/upload.php',1).'" class="button buttonRefused">' . $langs->trans("Cancel") . '</a>';
print '</div>';

// Hidden field to indicate whether to validate or not
print '<input type="hidden" id="validate_invoice" name="validate_invoice" value="0">';

print '</form>';

print '<input type="hidden" id="max_input_vars" value="'.ini_get('max_input_vars').'">';

// Close container
print '</div>';

// Add JavaScript for managing invoice lines and calculations
?>
<script type="text/javascript">
    // Make sure PHP variables used by JS are defined *before* the script that uses them
    var upinvoiceimport_root = '<?php echo dirname($_SERVER['PHP_SELF']); ?>';
    var upinvoiceimport_token = '<?php echo newToken(); ?>';
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
        'ConfirmDeleteFile': '<?php echo $langs->trans("ConfirmDeleteFile"); ?>',
        'ConfirmReplacePrice': '<?php echo $langs->trans("ConfirmReplacePrice"); ?>',
        'ConfirmReplaceVAT': '<?php echo $langs->trans("ConfirmReplaceVAT"); ?>',
        'Current': '<?php echo dol_escape_js($langs->trans("Current")); ?>',
        'New': '<?php echo dol_escape_js($langs->trans("New")); ?>'
    };
</script>

<script type="text/javascript">
$(document).ready(function() {
    // Add line button
    $("#add-line-btn").click(function(e) {
        e.preventDefault();
        addNewLine();
    });

    // Delete line button
    $(document).on('click', '.delete-line', function(e) {
        e.preventDefault();
        var lineNum = $(this).data('line');
        deleteLine(lineNum);
    });
    
    // Initialize line totals
    for (var i = 0; i < <?php echo $line_count; ?>; i++) {
        updateLineTotals(i);
    }
    
    // Calculate initial invoice totals based on loaded data
    updateInvoiceTotals();
    max_input_vars_check();

    // Manage "Create Invoice" button (without validating)
    $("input[name='create_only']").click(function() {
        // Ensure validate_invoice field is 0
        $('#validate_invoice').val('0');
        // No return false needed, let the form submit
        max_input_vars_check();
    });
});

function max_input_vars_check() {
    var maxInputVars = parseInt($('#max_input_vars').val());
    var inputcount = $('form#invoice_form :input').length;
    // Estimate total input vars: each line has ~9 inputs + other form inputs (~20)
    var estimatedTotalInputs = inputcount;
    if (estimatedTotalInputs >= maxInputVars) {
        alert('Warning: The number of input fields (' + estimatedTotalInputs + ') exceeds or is close to the PHP max_input_vars limit (' + maxInputVars + '). Please reduce the number of lines or increase the max_input_vars setting in your PHP configuration to avoid data loss.');
    }
    return false;
}

function parseLocalizedFloat(value) {
    if (typeof value !== 'string') return parseFloat(value) || 0;

    value = value.trim();

    // Elimina los espacios
    value = value.replace(/\s+/g, '');

    // Detecta si el separador decimal es una coma o un punto
    // (si hay coma y punto, el que va más a la derecha es el decimal)
    const hasComma = value.includes(',');
    const hasDot = value.includes('.');

    if (hasComma && hasDot) {
        if (value.lastIndexOf(',') > value.lastIndexOf('.')) {
            // "3.000,50" → eliminar puntos, reemplazar coma por punto
            value = value.replace(/\./g, '').replace(',', '.');
        } else {
            // "3,000.50" → eliminar comas (miles)
            value = value.replace(/,/g, '');
        }
    } else if (hasComma) {
        // "3000,50" o "3 000,50" → reemplazar coma por punto
        value = value.replace(',', '.');
    } else {
        // "3.000" debe ser interpretado como 3.0 ó 2.550 como 2.55
        if(hasDot) {
            value = value;
        } else {
            // "3000" o "3 000" sin coma decimal → eliminar separadores de miles
            value = value.replace(/\.(?=\d{3}($|[^\d]))/g, '');
        }
    }

    return parseFloat(value) || 0;
}

// Update line totals
function updateLineTotals(lineNum) {
    var qty = parseLocalizedFloat($("input[name='line_qty_" + lineNum + "']").val()) || 0;
    var pu_ht = parseLocalizedFloat($("input[name='line_pu_ht_" + lineNum + "']").val()) || 0;
    var tva_tx = parseLocalizedFloat($("input[name='line_tva_tx_" + lineNum + "']").val()) || 0;
    var remise_percent = parseLocalizedFloat($("input[name='line_remise_percent_" + lineNum + "']").val()) || 0;

    var total_ht = qty * pu_ht * (1 - remise_percent / 100);
    var total_tva = total_ht * (tva_tx / 100);
    var total_ttc = total_ht + total_tva;
    
    $("input[name='line_total_ht_" + lineNum + "']").val(total_ht.toFixed(2));
    $("input[name='line_total_tva_" + lineNum + "']").val(total_tva.toFixed(2));
    $("input[name='line_total_ttc_" + lineNum + "']").val(total_ttc.toFixed(2));
    
    updateInvoiceTotals();
}

// Update invoice totals
function updateInvoiceTotals() {
    var total_ht = 0;
    var total_tva = 0;
    var total_ttc = 0;
    
    // Sumar bases de líneas
    $(".totalhtline").each(function() {
        total_ht += parseFloat($(this).val()) || 0;
    });
    
    // Sumar IVA de líneas
    $(".totalvaline").each(function() {
        total_tva += parseFloat($(this).val()) || 0;
    });
    
    // Obtener valor de Retención (IRPF) global
    // Nota: Si viene negativo (ej: -10.00), al sumarlo algebraicamente restará del total.
    // Si el usuario lo introduce positivo, habría que restarlo, pero asumimos el formato del input original.
    var localtax2 = parseLocalizedFloat($("#localtax2").val()) || 0;

    // Calculamos el TTC sumando las líneas TTC
    // PERO: Las líneas calculan TTC = HT + IVA. No incluyen la retención global.
    // Por tanto, debemos recalcular el TTC global basándonos en los sumatorios + retención.
    
    // Opción A: Sumar los TTC de las líneas y añadir la retención global
    /*
    $(".totalttcline").each(function() {
        total_ttc += parseFloat($(this).val()) || 0;
    });
    total_ttc = total_ttc + localtax2; 
    */

    // Opción B (Más segura para consistencia aritmética): Recalcular desde bases globales
    total_ttc = total_ht + total_tva + localtax2;
    
    $("#total_ht").val(total_ht.toFixed(2));
    $("#total_tva").val(total_tva.toFixed(2));
    $("#total_ttc").val(total_ttc.toFixed(2));
}

// Add a new line with product type selector and product select2
function addNewLine() {
    var lineCount = parseInt($("#line_count").val());
    var tableBody = $("#invoice_lines_table tbody"); // Target tbody for better structure
    // Try to get a default VAT rate from the first line if it exists
    var defaultVatInput = $("input[name='line_tva_tx_0']");
    var defaultVat = defaultVatInput.length ? defaultVatInput.val() : "0";

    var newLine = '<tr class="invoice-line" id="line_row_' + lineCount + '">' +
        '<td>' +
            // --- START: Product Type Selector for new lines ---
            '<div class="product-type-selector" style="margin-bottom:5px;">' +
                '<select id="line_type_' + lineCount + '" name="line_type_' + lineCount + '" class="flat" style="padding: 6px 5px 5px; height: auto;min-width: 100%;">' +
                    '<option value="0">' + upinvoiceimport_langs.Product + '</option>' +
                    '<option value="1">' + upinvoiceimport_langs.Service + '</option>' +
                '</select>' +
            '</div>' +
            // --- END: Product Type Selector ---
            
            // --- START: Select2 Product Selector ---
            '<div class="product-select-container" style="margin-bottom:5px;">' +
                '<select id="line_product_' + lineCount + '" name="line_product_' + lineCount + '" class="product-select2" data-line="' + lineCount + '" style="width: 100%;">' +
                    '<option value=""></option>' +
                '</select>' +
            '</div>' +
            // --- END: Select2 Product Selector ---
            
            '<textarea name="line_desc_' + lineCount + '" class="flat width100p" style="margin-top: 5px; width: 98%"></textarea>' +
            // Hidden product ID for new lines (value 0)
            '<input type="hidden" name="line_fk_product_' + lineCount + '" value="0">' +
        '</td>' +
        '<td class="center"><input type="text" size="5" name="line_tva_tx_' + lineCount + '" value="' + defaultVat + '" class="flat tvaline right" onchange="updateLineTotals(' + lineCount + ')"></td>' +
        '<td class="center"><input type="text" size="5" name="line_qty_' + lineCount + '" value="1" class="flat qtyline right" onchange="updateLineTotals(' + lineCount + ')"></td>' +
        '<td class="center"><input type="text" size="8" name="line_pu_ht_' + lineCount + '" value="0" class="flat puhline right" onchange="updateLineTotals(' + lineCount + ')"></td>' +
        '<td class="center"><input type="text" size="5" name="line_remise_percent_' + lineCount + '" value="" class="flat remisepercentline right" onchange="updateLineTotals(' + lineCount + ')"></td>' +
        '<td class="right"><input type="text" size="8" name="line_total_ht_' + lineCount + '" value="0" class="flat totalhtline right" readonly></td>' +
        '<td class="right"><input type="text" size="8" name="line_total_tva_' + lineCount + '" value="0" class="flat totalvaline right" readonly></td>' +
        '<td class="right"><input type="text" size="8" name="line_total_ttc_' + lineCount + '" value="0" class="flat totalttcline right" readonly></td>' +
        '<td class="center"><a href="#" class="delete-line" data-line="' + lineCount + '"><i class="fas fa-trash"></i></a></td>' +
        '</tr>';

    tableBody.append(newLine);
    $("#line_count").val(lineCount + 1);
    
    // Trigger an event to notify that a new line was added
    $(document).trigger('line_added', [lineCount]);
    
    // Focus on the product selector for the new line
    $("#line_product_" + lineCount).focus();
    
    // Initialize totals for the new line (will also update invoice totals)
    updateLineTotals(lineCount);
}

// Delete a line
function deleteLine(lineNum) {
    // Check if it's the last line
    if ($('.invoice-line').length <= 1) {
        alert(upinvoiceimport_langs.CannotDeleteLastLine);
        return;
    }

    $('#line_row_' + lineNum).remove();
    updateInvoiceTotals(); // Recalculate totals after deletion
    // Optional: Renumber subsequent lines if needed, but usually not necessary if using unique IDs/names
}
</script>
<?php
// Footer
llxFooter();
$db->close();
?>