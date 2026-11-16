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
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once './class/upinvoicefiles.class.php';
require_once './class/upinvoiceproductmatcher.class.php';
require_once './class/upinvoiceorder.class.php';
require_once './lib/upinvoice.lib.php';

/*
 * Last step of an order file (supplier order or customer order): review the extracted
 * header and lines and create the Dolibarr order, as a draft or validated, with the PDF.
 */

$langs->loadLangs(array("upinvoice@upinvoice", "orders", "bills", "companies", "other", "products", "sendings"));

$file_id = GETPOSTINT('file_id');
$action = GETPOST('action', 'aZ09');

if (empty($file_id)) {
    setEventMessages($langs->trans("NoFileSelected"), null, 'errors');
    header("Location: ".dol_buildpath('/upinvoice/upload.php', 1));
    exit;
}

$upinvoicefiles = new UpInvoiceFiles($db);
if (UpInvoiceFiles::getMissingColumns($db) || $upinvoicefiles->fetch($file_id) <= 0) {
    setEventMessages($langs->trans("FileNotFound"), null, 'errors');
    header("Location: ".dol_buildpath('/upinvoice/upload.php', 1));
    exit;
}
// Invoices have their own page
if (!$upinvoicefiles->isOrder()) {
    header("Location: ".dol_buildpath('/upinvoice/invoice.php', 1)."?file_id=".$upinvoicefiles->id);
    exit;
}
$docType = $upinvoicefiles->doc_type;
$isCustomer = ($docType === UpInvoiceFiles::DOC_CUSTOMER_ORDER);
$uploadUrl = dol_buildpath('/upinvoice/upload.php', 1).'?doc_type='.urlencode($docType);

// Access: the user must be able to create this kind of order and the module must be on
if (!upinvoice_doc_type_module_enabled($docType) || !upinvoice_user_can_create($user, $docType)) {
    accessforbidden();
}

if (!empty($upinvoicefiles->fk_invoice)) {
    // Order already created from this file
    header("Location: ".upinvoice_object_card_url($docType, $upinvoicefiles->fk_invoice));
    exit;
}

$order_data = !empty($upinvoicefiles->api_json) ? json_decode($upinvoicefiles->api_json, true) : null;
if (!is_array($order_data)) {
    setEventMessages($langs->trans("FileNotProcessed"), null, 'errors');
    header("Location: ".$uploadUrl);
    exit;
}
$tpKey = UpInvoiceFiles::thirdpartyKey($order_data);
$apiWarnings = (isset($order_data['warnings']) && is_array($order_data['warnings'])) ? $order_data['warnings'] : array();

// Change the third party: back to the third party step
if ($action == 'change_supplier') {
    if (isset($order_data[$tpKey]['id'])) {
        unset($order_data[$tpKey]['id']);
        $upinvoicefiles->api_json = json_encode($order_data);
    }
    $upinvoicefiles->fk_supplier = 0;
    $upinvoicefiles->import_step = 2;
    if ($upinvoicefiles->update($user) > 0) {
        header("Location: ".dol_buildpath('/upinvoice/supplier.php', 1)."?file_id=".$upinvoicefiles->id.'&action=change_supplier_from_invoice');
        exit;
    }
    setEventMessages($langs->trans("ErrorUpdatingFile"), null, 'errors');
}

if (empty($upinvoicefiles->fk_supplier)) {
    setEventMessages($langs->trans("NoSupplierSelected"), null, 'errors');
    header("Location: ".dol_buildpath('/upinvoice/supplier.php', 1)."?file_id=".$upinvoicefiles->id);
    exit;
}
$thirdparty = new Societe($db);
if ($thirdparty->fetch($upinvoicefiles->fk_supplier) <= 0) {
    setEventMessages($langs->trans("SupplierNotFound"), null, 'errors');
    header("Location: ".dol_buildpath('/upinvoice/supplier.php', 1)."?file_id=".$upinvoicefiles->id);
    exit;
}

// Lines linked with products: supplier codes of the supplier first (supplier orders), then ref > ref_ext > label
if (!empty($order_data['lines']) && is_array($order_data['lines'])) {
    $productMatcher = new UpInvoiceProductMatcher($db);
    $order_data['lines'] = $productMatcher->linkLines($order_data['lines'], $isCustomer ? 0 : (int) $thirdparty->id);
}

$upinvoiceorder = new UpInvoiceOrder($db);
$payment_options = $upinvoiceorder->getPaymentOptions($docType, $thirdparty);
$canValidate = UpInvoiceOrder::canValidateOnCreate($docType, $user);

// Reference of the order for Dolibarr: the third party's own reference (ref_supplier / ref_client).
// The document number is it when the third party issued the document; when our company issued
// it, the third party's reference (if printed) is "ref_thirdparty" and our number goes to the notes.
$companyRole = isset($order_data['company_role']) ? $order_data['company_role'] : 'unknown';
$refDocument = trim((string) ($order_data['ref_document'] ?? ''));
$refThirdparty = trim((string) ($order_data['ref_thirdparty'] ?? ''));
if ($companyRole === 'issuer') {
    $defaultRef = $refThirdparty;
    $defaultNotePrivate = ($refDocument !== '') ? $langs->transnoentitiesnoconv('UpInvoiceOurDocumentRef', $refDocument) : '';
} else {
    $defaultRef = ($refDocument !== '') ? $refDocument : $refThirdparty;
    $defaultNotePrivate = '';
}

// Supplier orders get their order date in Dolibarr when they are sent to the supplier ("Order"
// action), so the date printed on the document is kept in the private note
if (!$isCustomer && !empty($order_data['date_order'])) {
    $docDateNote = $langs->transnoentitiesnoconv('UpInvoiceDocumentDate', dol_print_date(dol_stringtotime($order_data['date_order']), 'day'));
    $defaultNotePrivate = trim($defaultNotePrivate."\n".$docDateNote);
}

// Document that may not be an order, and amounts in another currency: confirmed by the user
$detectedType = isset($order_data['detected_document_type']) ? $order_data['detected_document_type'] : 'order';
$needsTypeConfirm = in_array('document_type_mismatch', $apiWarnings, true);
$docCurrency = strtoupper(trim((string) ($order_data['currency'] ?? '')));
$needsCurrencyConfirm = ($docCurrency !== '' && $docCurrency !== strtoupper($conf->currency));

/*
 * Actions
 */
$duplicates = array();
if ($action == 'create_order') {
    $validate = (GETPOSTINT('validate_order') == 1) && $canValidate;
    $ref = GETPOST('ref_order', 'alphanohtml');
    $date = dol_mktime(12, 0, 0, GETPOSTINT('date_ordermonth'), GETPOSTINT('date_orderday'), GETPOSTINT('date_orderyear'));
    $deliveryDate = dol_mktime(12, 0, 0, GETPOSTINT('date_deliverymonth'), GETPOSTINT('date_deliveryday'), GETPOSTINT('date_deliveryyear'));

    $errors = array();
    if ($needsTypeConfirm && !GETPOSTINT('confirm_doc_type')) {
        $errors[] = $langs->trans('UpInvoiceConfirmIsOrderRequired');
    }
    if ($needsCurrencyConfirm && !GETPOSTINT('confirm_currency')) {
        $errors[] = $langs->trans('UpInvoiceConfirmCurrencyRequired', $docCurrency, $conf->currency);
    }
    $duplicates = $upinvoiceorder->findSameReference($docType, $thirdparty->id, $ref);
    if ($duplicates && !GETPOSTINT('confirm_duplicate')) {
        $errors[] = $langs->trans('UpInvoiceOrderSameRefConfirm');
    }

    $form_order_data = array(
        'ref' => $ref,
        'date' => $date,
        'delivery_date' => $deliveryDate ? $deliveryDate : null,
        'cond_reglement_id' => GETPOSTINT('cond_reglement_id'),
        'mode_reglement_id' => GETPOSTINT('mode_reglement_id'),
        'note_public' => GETPOST('note_public', 'restricthtml'),
        'note_private' => GETPOST('note_private', 'restricthtml'),
        'calc_method' => GETPOSTINT('calc_method'),
        'validate' => $validate,
        'extracted_total_ttc' => isset($order_data['total_ttc']) ? $order_data['total_ttc'] : null,
        'lines' => array(),
    );
    $line_count = GETPOSTINT('line_count');
    for ($i = 0; $i < $line_count; $i++) {
        // Deleted rows are not posted
        if (GETPOST('line_qty_'.$i, 'alpha') === '' && GETPOST('line_desc_'.$i, 'restricthtml') === '') {
            continue;
        }
        $form_order_data['lines'][] = array(
            'product_desc' => GETPOST('line_desc_'.$i, 'restricthtml'),
            'qty' => price2num(GETPOST('line_qty_'.$i, 'alpha')),
            'pu_ht' => price2num(GETPOST('line_pu_ht_'.$i, 'alpha')),
            'tva_tx' => price2num(GETPOST('line_tva_tx_'.$i, 'alpha')),
            'remise_percent' => price2num(GETPOST('line_remise_percent_'.$i, 'alpha')),
            'product_type' => GETPOSTINT('line_type_'.$i),
            'fk_product' => GETPOSTINT('line_fk_product_'.$i),
        );
    }

    if ($errors) {
        setEventMessages(null, $errors, 'errors');
    } else {
        $result = $upinvoiceorder->createOrder($docType, $form_order_data, $thirdparty->id, $user, $upinvoicefiles->file_path);
        if ($result['status'] == 'success') {
            $upinvoicefiles->fk_invoice = $result['id'];
            $upinvoicefiles->status = 1;
            $upinvoicefiles->import_step = 4;
            $upinvoicefiles->import_error = '';
            if (!empty($form_order_data['calc_method'])) {
                $upinvoicefiles->calc_method = $form_order_data['calc_method'];
            }
            if ($upinvoicefiles->update($user) > 0) {
                setEventMessages($result['message'], null, !empty($result['warning']) ? 'warnings' : 'mesgs');
                header("Location: ".$uploadUrl."&order_created=1");
                exit;
            }
            setEventMessages($langs->trans("ErrorUpdatingFile"), null, 'errors');
        } else {
            setEventMessages($result['message'], null, 'errors');
        }
    }
} else {
    $duplicates = $upinvoiceorder->findSameReference($docType, $thirdparty->id, $defaultRef);
}

/*
 * View
 */
$page_name = $isCustomer ? "UpInvoiceCustomerOrderValidation" : "UpInvoiceSupplierOrderValidation";
$morejs = array(
    '/upinvoice/js/upinvoiceimport.js',
    '/upinvoice/js/upinvoice-product-selector.js',
    '/upinvoice/js/upinvoice-lines.js',
);
$morecss = array('/upinvoice/css/upinvoiceimport.css.php');

$form = new Form($db);
$conf->dol_hide_leftmenu = 1;
llxHeader('', $langs->trans($page_name), '', '', 0, 0, $morejs, $morecss);
print load_fiche_titre($langs->trans($page_name), '', $isCustomer ? 'order' : 'supplier_order');

// Values posted back after an error, else the extracted ones
$posted = ($action == 'create_order');
$valRef = $posted ? GETPOST('ref_order', 'alphanohtml') : $defaultRef;
$valDate = $posted ? dol_mktime(12, 0, 0, GETPOSTINT('date_ordermonth'), GETPOSTINT('date_orderday'), GETPOSTINT('date_orderyear')) : (!empty($order_data['date_order']) ? dol_stringtotime($order_data['date_order']) : dol_now());
$valDelivery = $posted ? dol_mktime(12, 0, 0, GETPOSTINT('date_deliverymonth'), GETPOSTINT('date_deliveryday'), GETPOSTINT('date_deliveryyear')) : (!empty($order_data['date_delivery']) ? dol_stringtotime($order_data['date_delivery']) : '');
$valNotePublic = $posted ? GETPOST('note_public', 'restricthtml') : (string) ($order_data['notes'] ?? '');
$valNotePrivate = $posted ? GETPOST('note_private', 'restricthtml') : $defaultNotePrivate;

print '<div class="upinvoiceimport-container">';
$previewUrl = dol_buildpath('/viewimage.php', 1).'?modulepart=upinvoice&file=temp/'.urlencode(basename($upinvoicefiles->file_path)).'&cache=0';
$isPdfDoc = (strpos((string) $upinvoicefiles->file_type, 'pdf') !== false);

print '<div class="upinv-split">';

// Left: the document
print '<div class="upinv-split-left">';
if ($isPdfDoc) {
    print '<iframe src="'.$previewUrl.'" class="upinv-doc-frame" title="'.dol_escape_htmltag($upinvoicefiles->original_filename).'"></iframe>';
} else {
    print '<div class="upinv-doc-imgwrap"><img src="'.$previewUrl.'" class="upinv-doc-img" alt="'.dol_escape_htmltag($upinvoicefiles->original_filename).'"></div>';
}
print '<div class="upinv-doc-foot opacitymedium small">'.dol_escape_htmltag($upinvoicefiles->original_filename).' &middot; '.dol_print_date($upinvoicefiles->date_creation, 'dayhour').'</div>';
print '</div>';

// Right: the form
print '<div class="upinv-split-right">';

// Third party strip
print '<div class="upinv-supplier-strip">';
print '<a href="'.$_SERVER['PHP_SELF'].'?action=change_supplier&file_id='.$upinvoicefiles->id.'&token='.newToken().'" class="butAction butActionDelete butActionSmall" style="float:right">';
print '<i class="fas fa-exchange-alt"></i> '.$langs->trans($isCustomer ? "UpInvoiceChangeCustomer" : "ChangeSupplier").'</a>';
print '<span class="upinv-supplier-name">'.dol_escape_htmltag($thirdparty->name).'</span>';
if (!empty($thirdparty->idprof1)) print ' <span class="opacitymedium">&middot; '.dol_escape_htmltag($thirdparty->idprof1).'</span>';
if (!empty($thirdparty->tva_intra)) print ' <span class="opacitymedium">&middot; '.dol_escape_htmltag($thirdparty->tva_intra).'</span>';
$ordersListUrl = $isCustomer ? DOL_URL_ROOT.'/commande/list.php?socid='.((int) $thirdparty->id) : DOL_URL_ROOT.'/fourn/commande/list.php?socid='.((int) $thirdparty->id);
print ' <a href="'.$ordersListUrl.'" target="_blank" class="upinv-link">&middot; <i class="fas fa-list"></i> '.$langs->trans("UpInvoiceViewThirdpartyOrders").'</a>';
$tpAddr = trim($thirdparty->address.', '.$thirdparty->zip.' '.$thirdparty->town, ', ');
if (!empty($tpAddr)) print '<div class="opacitymedium small">'.dol_escape_htmltag($tpAddr).'</div>';
print '</div>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" name="order_form" id="order_form">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="create_order">';
print '<input type="hidden" name="file_id" value="'.$upinvoicefiles->id.'">';

print '<div class="upinv-form">';

// Warnings that need a confirmation before creating the order
if ($needsTypeConfirm || $needsCurrencyConfirm || $duplicates) {
    print '<div class="upinvoiceimport-warnings">';
    if ($needsTypeConfirm) {
        print '<div class="upinvoiceimport-warning"><i class="fas fa-exclamation-triangle"></i> ';
        print $langs->trans('UpInvoiceNotAnOrderWarning', $langs->transnoentitiesnoconv('UpInvoiceDetected_'.$detectedType));
        print '<br><label><input type="checkbox" name="confirm_doc_type" value="1"'.(GETPOSTINT('confirm_doc_type') ? ' checked' : '').'> '.$langs->trans('UpInvoiceConfirmIsOrder').'</label>';
        print '</div>';
    }
    if ($needsCurrencyConfirm) {
        print '<div class="upinvoiceimport-warning"><i class="fas fa-exclamation-triangle"></i> ';
        print $langs->trans('UpInvoiceCurrencyWarning', $docCurrency, $conf->currency);
        print '<br><label><input type="checkbox" name="confirm_currency" value="1"'.(GETPOSTINT('confirm_currency') ? ' checked' : '').'> '.$langs->trans('UpInvoiceConfirmCurrency', $conf->currency).'</label>';
        print '</div>';
    }
    if ($duplicates) {
        print '<div class="upinvoiceimport-warning"><i class="fas fa-exclamation-triangle"></i> '.$langs->trans('UpInvoiceOrderSameRef').' ';
        $links = array();
        foreach ($duplicates as $dup) {
            $links[] = '<a href="'.$dup['url'].'" target="_blank">'.dol_escape_htmltag($dup['ref']).'</a>';
        }
        print implode(', ', $links);
        print '<br><label><input type="checkbox" name="confirm_duplicate" value="1"'.(GETPOSTINT('confirm_duplicate') ? ' checked' : '').'> '.$langs->trans('UpInvoiceOrderCreateAnyway').'</label>';
        print '</div>';
    }
    print '</div>';
}

// Header
print '<div class="upinv-header-row">';
print '<table class="border upinv-header-table">';
print '<tr><td class="titlefieldcreate">'.$langs->trans($isCustomer ? "RefCustomer" : "RefSupplier").'</td><td>';
print '<input type="text" name="ref_order" value="'.dol_escape_htmltag($valRef).'" class="minwidth200">';
print '</td></tr>';
print '<tr><td class="fieldrequired">'.$langs->trans("OrderDate").'</td><td>';
print $form->selectDate($valDate, 'date_order', 0, 0, 0, "order_form", 1, 1);
print '</td></tr>';
print '<tr><td>'.$langs->trans("DateDeliveryPlanned").'</td><td>';
print $form->selectDate($valDelivery, 'date_delivery', 0, 0, 1, "order_form", 1, 0);
print '</td></tr>';
print '<tr><td>'.$langs->trans("PaymentConditionsShort").'</td><td>';
$form->select_conditions_paiements($posted ? GETPOSTINT('cond_reglement_id') : $payment_options['cond_reglement_id'], 'cond_reglement_id', -1, 1);
print '</td></tr>';
print '<tr><td>'.$langs->trans("PaymentMode").'</td><td>';
$form->select_types_paiements($posted ? GETPOSTINT('mode_reglement_id') : $payment_options['mode_reglement_id'], 'mode_reglement_id', '', 0, 1, 0, 0, 1);
print '</td></tr>';
print '<tr><td class="tdtop">'.$langs->trans("NotePublic").'</td><td>';
print '<textarea name="note_public" rows="2" class="flat quatrevingtpercent">'.dol_escape_htmltag($valNotePublic).'</textarea>';
print '</td></tr>';
print '<tr><td class="tdtop">'.$langs->trans("NotePrivate").'</td><td>';
print '<textarea name="note_private" rows="2" class="flat quatrevingtpercent">'.dol_escape_htmltag($valNotePrivate).'</textarea>';
print '</td></tr>';
print '</table>';
print '</div>';

// Totals (computed from the lines, same VAT methods as invoices)
print '<table class="border centpercent upinv-totals">';
print '<tr class="liste_titre"><td class="titlefieldcreate">'.$langs->trans("TotalHT").'</td><td class="right">';
print '<input type="text" name="total_ht" id="total_ht" class="flat right" value="'.price($order_data['total_ht'] ?? 0).'" readonly></td></tr>';
print '<tr><td>'.$langs->trans("TotalVAT");
print ' <span class="upinv-recalc" title="'.dol_escape_htmltag($langs->trans("UpInvoiceMethodHelp")).'">';
print '<span class="opacitymedium small">'.$langs->trans("UpInvoiceRecalc").':</span> ';
print '<a href="#" class="upinv-method-btn" data-method="1">'.$langs->trans("UpInvoiceMethod1").'</a> / ';
print '<a href="#" class="upinv-method-btn" data-method="2">'.$langs->trans("UpInvoiceMethod2").'</a>';
print '</span></td><td class="right">';
print '<input type="text" name="total_tva" id="total_tva" class="flat right" value="'.price($order_data['total_tva'] ?? 0).'"></td></tr>';
print '<tr class="liste_titre"><td>'.$langs->trans("TotalTTC").'</td><td class="right">';
print '<input type="text" name="total_ttc" id="total_ttc" class="flat right" value="'.price($order_data['total_ttc'] ?? 0).'"></td></tr>';
print '</table>';
print '<input type="hidden" name="calc_method" id="calc_method" value="">';
print '<div id="upinv-reconcile-warning" class="upinv-reconcile" style="display:none"></div>';

print '</div>'; // upinv-form

// Lines (shared with invoices)
$upinv_lines = $order_data['lines'] ?? array();
include __DIR__.'/tpl/lines_form.tpl.php';

// Buttons: Cancel, create as draft, create and validate
print '<div class="upinv-form-actions">';
print '<a href="'.$uploadUrl.'" class="button buttonRefused upinv-cancel">'.$langs->trans("Cancel").'</a>';
print '<input type="submit" class="button upinv-btn-amber" name="create_only" value="'.dol_escape_htmltag($langs->trans("UpInvoiceCreateOrderDraft")).'" onclick="document.getElementById(\'validate_order\').value=\'0\';">';
if ($canValidate) {
    print '<button type="submit" class="button upinv-btn-green" name="create_validate" value="1" onclick="document.getElementById(\'validate_order\').value=\'1\';"><i class="fas fa-check"></i> '.$langs->trans("UpInvoiceCreateOrderValidated").'</button>';
} else {
    print '<span class="button upinv-btn-green butActionRefused" title="'.dol_escape_htmltag($langs->trans($isCustomer ? 'UpInvoiceOrderValidateFromCard' : 'NotEnoughPermissions')).'"><i class="fas fa-check"></i> '.$langs->trans("UpInvoiceCreateOrderValidated").'</span>';
}
print '</div>';
print '<input type="hidden" id="validate_order" name="validate_order" value="0">';
print '</form>';

print '<input type="hidden" id="max_input_vars" value="'.ini_get('max_input_vars').'">';
print '</div>'; // upinv-split-right
print '</div>'; // upinv-split
print '</div>'; // container
?>
<script type="text/javascript">
    var upinvoiceimport_root = '<?php echo dirname($_SERVER['PHP_SELF']); ?>';
    var upinvoiceimport_token = '<?php echo newToken(); ?>';
    // Product search shows purchase prices of this supplier (supplier orders); 0 for customer orders
    var upinvoiceimport_socid = <?php echo $isCustomer ? 0 : (int) $thirdparty->id; ?>;
    var upinvoiceimport_decimal_separator = '<?php echo dol_escape_js($langs->transnoentitiesnoconv("SeparatorDecimal")); ?>';
    var upinvoiceimport_langs = {
<?php
$jsLangKeys = array('Searching', 'CannotDeleteLastLine', 'Product', 'Service', 'SelectProduct', 'SearchProduct', 'NoProductsFound',
    'EnterSearchTerm', 'SearchTermTooShort', 'All', 'Search', 'Select', 'MatchingProduct', 'Reference', 'Label', 'Price', 'VATRate',
    'Current', 'New', 'ErrorProcessingResponse');
$jsLangs = array();
foreach ($jsLangKeys as $k) {
    $jsLangs[] = "        '".$k."': '".dol_escape_js($langs->trans($k))."'";
}
$jsLangs[] = "        'SupplierBuyPrice': '".dol_escape_js($langs->trans("UpInvoiceSupplierBuyPrice"))."'";
$jsLangs[] = "        'NoSupplierPrice': '".dol_escape_js($langs->trans("UpInvoiceNoSupplierPrice"))."'";
$jsLangs[] = "        'CostPrice': '".dol_escape_js($langs->trans("UpInvoiceCostPrice"))."'";
$jsLangs[] = "        'ConfirmReplacePrice': '".dol_escape_js($langs->transnoentitiesnoconv("ConfirmReplacePrice"))."'";
$jsLangs[] = "        'ConfirmReplaceVAT': '".dol_escape_js($langs->transnoentitiesnoconv("ConfirmReplaceVAT"))."'";
print implode(",\n", $jsLangs)."\n";
?>
    };
    // Extracted (document) totals: VAT method auto-pick and reconciliation (no warning when the order has no amounts)
    var upinvoiceExtracted = {
        total_ht:  <?php echo (float) ($order_data['total_ht'] ?? 0); ?>,
        total_tva: <?php echo (float) ($order_data['total_tva'] ?? 0); ?>,
        total_ttc: <?php echo (float) ($order_data['total_ttc'] ?? 0); ?>,
        localtax2: 0
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
<?php
llxFooter();
$db->close();
