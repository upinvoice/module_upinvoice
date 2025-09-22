<?php
/* Copyright (C) 2023
 * Licensed under the GNU General Public License version 3
 */
if (!defined('NOCSRFCHECK')) {
    define('NOCSRFCHECK', '1');
}
if (!defined('NOTOKENRENEWAL')) {
    define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
    define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREAJAX')) {
    define('NOREQUIREAJAX', '1');
}
if (!defined('NOREQUIRESOC')) {
    define('NOREQUIRESOC', '1');
}

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME']; $tmp2 = realpath(__FILE__); $i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
    $i--; $j--;
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

// Load translation files required by the page
$langs->loadLangs(array('upinvoice@upinvoice'));

require_once '../class/upinvoicefiles.class.php';

// Control access
if (!$user->rights->facture->lire) {
    $result = array('status' => 'error', 'message' => $langs->trans('NotAllowed'));
    echo json_encode($result);
    exit;
}

// Security check
if (empty($_POST['token'])) {
    $result = array('status' => 'error', 'message' => $langs->trans('InvalidRequest'));
    echo json_encode($result);
    exit;
}


// Get parameters
$token = GETPOST('token', 'alpha');
$fileId = GETPOST('file_id', 'int');
$supplierId = GETPOST('supplier_id', 'int');

// Validate parameters
if (empty($fileId) || empty($supplierId)) {
    $result = array('status' => 'error', 'message' => $langs->trans('MissingParameters'));
    echo json_encode($result);
    exit;
}

// Initialize objects
$upinvoicefiles = new UpInvoiceFiles($db);

// Fetch file record
$result = $upinvoicefiles->fetch($fileId);
if ($result <= 0) {
    $response = array('status' => 'error', 'message' => $langs->trans('FileNotFound'));
    echo json_encode($response);
    exit;
}

// Check if file has API JSON data
if (empty($upinvoicefiles->api_json)) {
    $response = array('status' => 'error', 'message' => $langs->trans('FileNotProcessed'));
    echo json_encode($response);
    exit;
}

// Parse JSON data
$invoice_data = json_decode($upinvoicefiles->api_json, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    $response = array('status' => 'error', 'message' => $langs->trans('InvalidJSONData').': '.json_last_error_msg());
    echo json_encode($response);
    exit;
}

// Update JSON data to include supplier ID
if (is_array($invoice_data) && isset($invoice_data['supplier'])) {
    $invoice_data['supplier']['id'] = $supplierId;
    $upinvoicefiles->api_json = json_encode($invoice_data);
}

// Update file record with supplier ID
$upinvoicefiles->fk_supplier = $supplierId;
$upinvoicefiles->import_step = 3; // Next step: invoice validation
$updateResult = $upinvoicefiles->update($user);

if ($updateResult > 0) {
    $response = array(
        'status' => 'success',
        'message' => $langs->trans('SupplierConfirmed'),
        'file_id' => $fileId
    );
} else {
    $response = array(
        'status' => 'error',
        'message' => $langs->trans('ErrorUpdatingFile')
    );
}

echo json_encode($response);
exit;