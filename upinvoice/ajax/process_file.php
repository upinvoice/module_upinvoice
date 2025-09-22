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
if (empty($_POST['token']) || empty($_POST['file_id'])) {
    $result = array('status' => 'error', 'message' => $langs->trans('InvalidRequest'));
    echo json_encode($result);
    exit;
}


$token = GETPOST('token', 'alpha');
$file_id = GETPOST('file_id', 'int');

// Initialize objects
$upinvoicefiles = new UpInvoiceFiles($db);

// Check if file exists
$result = $upinvoicefiles->fetch($file_id);
if ($result <= 0) {
    $result = array('status' => 'error', 'message' => $langs->trans('FileNotFound'));
    echo json_encode($result);
    exit;
}

// Check if file is already being processed
if ($upinvoicefiles->processing == 1) {
    $result = array('status' => 'error', 'message' => $langs->trans('FileAlreadyProcessing'));
    echo json_encode($result);
    exit;
}

// Process the file
$process_result = $upinvoicefiles->processWithApi($user);

if ($process_result > 0) {
    // Success
    $result = array(
        'status' => 'success',
        'message' => $langs->trans('FileProcessedSuccessfully'),
        'file_id' => $file_id,
        'next_step' => $upinvoicefiles->import_step,
        'fk_supplier' => $upinvoicefiles->fk_supplier,
        'fk_invoice' => $upinvoicefiles->fk_invoice
    );
} else {
    // Error
    $result = array(
        'status' => 'error',
        'message' => $upinvoicefiles->import_error ? $upinvoicefiles->import_error : $langs->trans('ProcessingError'),
        'file_id' => $file_id
    );
}

echo json_encode($result);
exit;
