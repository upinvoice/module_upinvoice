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
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
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

require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

// Check security and permissions
if (empty($user->rights->facture->lire)) {
    http_response_code(403);
    echo json_encode(array('status' => 'error', 'message' => $langs->trans("NotAllowed")));
    exit;
}

// Load translations
$langs->loadLangs(array("upinvoice@upinvoice", "products"));

// Get parameters
$token = GETPOST('token', 'alpha');
$search = GETPOST('search', 'alpha');
$product_type = GETPOST('type', 'int'); // 0 = product, 1 = service, -1 = both
$limit = GETPOST('limit', 'int') ?: 10;
$socid = GETPOST('socid', 'int'); // Supplier of the invoice being imported

// CSRF protection
if (!verifyCsrf()) {
    http_response_code(403);
    echo json_encode(array('status' => 'error', 'message' => $langs->trans("InvalidCSRFToken")));
    exit;
}

// Ensure search term is provided
if (empty($search)) {
    echo json_encode(array('status' => 'error', 'message' => $langs->trans("SearchTermRequired")));
    exit;
}

// Search for products
$result = searchProducts($search, $product_type, $limit, $socid);

// Return JSON response
header('Content-Type: application/json');
echo json_encode($result);

/**
 * Search for products in the database
 * 
 * @param string $search Search term
 * @param int $product_type Product type (-1 for both, 0 for product, 1 for service)
 * @param int $limit Maximum number of results
 * @param int $socid Supplier whose purchase prices are returned (0 = none)
 * @return array Search results
 */
function searchProducts($search, $product_type = -1, $limit = 10, $socid = 0)
{
    global $db, $conf, $langs;
    
    $result = array(
        'status' => 'success',
        'message' => '',
        'results' => array()
    );
    
    // Sanitize inputs
    $search = trim($db->escape($search));
    $product_type = (int)$product_type;
    $limit = (int)$limit;
    $socid = (int)$socid;
    
    // Prepare search terms for SQL
    $searchTerms = preg_split('/\s+/', $search);
    $searchClauses = array();
    
    foreach ($searchTerms as $term) {
        if (strlen($term) < 2) continue; // Skip very short terms
        
        $term = $db->escape($term);
        $searchClauses[] = "(p.ref LIKE '%".$term."%' OR p.label LIKE '%".$term."%' OR p.description LIKE '%".$term."%')";
    }
    
    if (empty($searchClauses)) {
        $result['message'] = $langs->trans("SearchTermTooShort");
        return $result;
    }
    
    // Build the SQL query
    $sql = "SELECT p.rowid, p.ref, p.label, p.description, p.price, p.price_ttc, p.tva_tx, p.fk_product_type, p.tosell, p.cost_price, p.pmp";
    $sql .= " FROM ".MAIN_DB_PREFIX."product as p";
    $sql .= " WHERE (".implode(' AND ', $searchClauses).")";
    
    // Filter by product type if specified
    /* if ($product_type >= 0) {
        $sql .= " AND p.fk_product_type = ".$product_type;
    } */
    
    // Filter by entity
    $sql .= " AND p.entity IN (".getEntity('product').")";
    
    // Order by relevance
    $sql .= " ORDER BY p.ref ASC";
    
    // Limit results
    $sql .= " LIMIT ".$limit;
    
    $resql = $db->query($sql);
    if (!$resql) {
        $result['status'] = 'error';
        $result['message'] = $db->lasterror();
        return $result;
    }
    
    $num = $db->num_rows($resql);
    if ($num <= 0) {
        $result['message'] = $langs->trans("NoProductsFound");
        return $result;
    }
    
    $rows = array();
    while ($obj = $db->fetch_object($resql)) {
        $rows[(int) $obj->rowid] = $obj;
    }
    $db->free($resql);

    // This is a supplier invoice: prices and VAT must come from what this supplier charges us
    // (product_fournisseur_price), never from the sale price of the product
    $supplierPrices = getSupplierPrices(array_keys($rows), $socid);

    // Parse results
    foreach ($rows as $id => $obj) {
        $buy = isset($supplierPrices[$id]) ? $supplierPrices[$id] : null;
        // Informative purchase cost when this supplier has no price for the product
        $cost = (float) $obj->cost_price > 0 ? $obj->cost_price : ((float) $obj->pmp > 0 ? $obj->pmp : null);
        $product = array(
            'id' => $obj->rowid,
            'ref' => $obj->ref,
            'label' => $obj->label,
            'description' => $obj->description,
            'buy_price' => $buy ? (float) price2num($buy->unitprice, 'MU') : null,
            'buy_price_label' => $buy ? price($buy->unitprice) : '',
            'buy_tva_tx' => $buy ? (float) $buy->tva_tx : null,
            'ref_fourn' => $buy ? $buy->ref_fourn : '',
            'cost_price_label' => $cost !== null ? price($cost) : '',
            'type' => $obj->fk_product_type,
            'type_label' => $obj->fk_product_type == 1 ? $langs->trans("Service") : $langs->trans("Product"),
            'status' => $obj->tosell
        );

        $result['results'][] = $product;
    }

    $result['message'] = sprintf($langs->trans("ProductsFound"), $num);
    
    return $result;
}

/**
 * Purchase price of each product for a supplier. When the supplier has several prices for a
 * product (by quantity), the one for the smallest quantity is used, the most recent on a tie.
 *
 * @param int[] $productIds Product IDs
 * @param int $socid Supplier ID
 * @return array Supplier price row (unitprice, tva_tx, ref_fourn) indexed by product ID
 */
function getSupplierPrices($productIds, $socid)
{
    global $db;

    $prices = array();
    if (empty($productIds) || $socid <= 0) {
        return $prices;
    }

    $sql = "SELECT pfp.fk_product, pfp.unitprice, pfp.tva_tx, pfp.ref_fourn";
    $sql .= " FROM ".MAIN_DB_PREFIX."product_fournisseur_price as pfp";
    $sql .= " WHERE pfp.fk_soc = ".((int) $socid);
    $sql .= " AND pfp.fk_product IN (".implode(',', array_map('intval', $productIds)).")";
    $sql .= " AND pfp.entity IN (".getEntity('productsupplierprice').")";
    $sql .= " ORDER BY pfp.fk_product, pfp.quantity ASC, pfp.rowid DESC";

    $resql = $db->query($sql);
    if (!$resql) {
        return $prices;
    }
    while ($obj = $db->fetch_object($resql)) {
        if (!isset($prices[(int) $obj->fk_product])) {
            $prices[(int) $obj->fk_product] = $obj;
        }
    }
    $db->free($resql);

    return $prices;
}

/**
 * Verify CSRF token
 * 
 * @return bool True if valid, false otherwise
 */
function verifyCsrf()
{
    $token = GETPOST('token', 'alpha');
    
    // Check if token is valid
    if (empty($token) || $token != $_SESSION['token']) {
        return false;
    }
    
    return true;
}