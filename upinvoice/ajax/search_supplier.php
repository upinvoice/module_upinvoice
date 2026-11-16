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

require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.class.php';
require_once '../class/upinvoicesupplier.class.php';

// Control access
if (!$user->rights->facture->lire) {
    $result = array('status' => 'error', 'message' => $langs->trans('NotAllowed'));
    echo json_encode($result);
    exit;
}

// Initialize objects
$upinvoicesupplier = new UpInvoiceSupplier($db);
// Customer orders search customers (role of the file being reviewed)
$roleFileId = GETPOSTINT('file_id');
if ($roleFileId > 0) {
    dol_include_once('/upinvoice/lib/upinvoice.lib.php');
    $roleFile = new UpInvoiceFiles($db);
    if (!UpInvoiceFiles::getMissingColumns($db) && $roleFile->fetch($roleFileId) > 0) {
        $upinvoicesupplier->role = upinvoice_doc_type_role($roleFile->doc_type);
    }
}

// Get search parameter
$search_term = GETPOST('search_term', 'alpha');

$suppliers = array();

// If search term is provided, search by combined term (name and tax ID)
if (!empty($search_term)) {
    $suppliers = $upinvoicesupplier->searchByCombinedTerm($search_term);
}

// Prepare result array
$result = array(
    'status' => 'success',
    'suppliers' => array()
);

// Format supplier data
foreach ($suppliers as $supplier) {
    $taxInfo = array();
    if (!empty($supplier->idprof1)) $taxInfo[] = $langs->trans("ProfId1") . ': ' . $supplier->idprof1;
    if (!empty($supplier->tva_intra)) $taxInfo[] = $langs->trans("VATIntra") . ': ' . $supplier->tva_intra;
    
    $address = array();
    if (!empty($supplier->address)) $address[] = $supplier->address;
    if (!empty($supplier->zip) || !empty($supplier->town)) $address[] = trim($supplier->zip . ' ' . $supplier->town);
    if (!empty($supplier->country_code)) {
        // Get country name
        $sql = "SELECT label FROM ".MAIN_DB_PREFIX."c_country WHERE code = '".$db->escape($supplier->country_code)."'";
        $resql = $db->query($sql);
        if ($resql && $db->num_rows($resql) > 0) {
            $obj = $db->fetch_object($resql);
            $address[] = $obj->label;
        } else {
            $address[] = $supplier->country_code;
        }
    }
    
    $supplierData = array(
        'id' => $supplier->id,
        'name' => $supplier->name,
        'name_alias' => $supplier->name_alias,
        'tax_info' => implode('<br>', $taxInfo),
        'address' => implode('<br>', $address)
    );
    
    $result['suppliers'][] = $supplierData;
}

// Return JSON response
echo json_encode($result);
exit;