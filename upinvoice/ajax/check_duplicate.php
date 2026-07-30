<?php
/* Copyright (C) 2023
 * Licensed under the GNU General Public License version 3
 */
if (!defined('NOCSRFCHECK')) {
    define('NOCSRFCHECK', '1'); // Disable CSRF check for this script
}
if (!defined('NOTOKENRENEWAL')) {
    define('NOTOKENRENEWAL', '1'); // Disables token renewal
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
if (!isset($_POST['token']) || !isset($_POST['filename']) || !isset($_POST['filesize'])) {
    $result = array('status' => 'error', 'message' => $langs->trans('InvalidRequest'));
    echo json_encode($result);
    exit;
}

// Get POST parameters
$token = GETPOST('token', 'alpha');
// Same normalization as ajax/upload.php, so the comparison matches what is stored
$filename = UpInvoiceFiles::normalizeFilename(GETPOST('filename', 'alpha'));
$filesize = GETPOST('filesize', 'int');

// Check for duplicate file
$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "upinvoice_files";
$sql .= " WHERE entity = " . $conf->entity;
$sql .= " AND original_filename = '" . $db->escape($filename) . "'";
$sql .= " AND file_size = " . intval($filesize);

$resql = $db->query($sql);
if (!$resql) {
    $result = array('status' => 'error', 'message' => $langs->trans('DatabaseError'));
    echo json_encode($result);
    exit;
}

$isDuplicate = false;
$duplicateFileId = 0;

if ($db->num_rows($resql) > 0) {
    $obj = $db->fetch_object($resql);
    $isDuplicate = true;
    $duplicateFileId = $obj->rowid;
}

// Return result
$result = array(
    'status' => 'success',
    'isDuplicate' => $isDuplicate,
    'duplicateFileId' => $duplicateFileId,
    'message' => $isDuplicate ? $langs->trans('DuplicateFileDetected') : ''
);

echo json_encode($result);
exit;