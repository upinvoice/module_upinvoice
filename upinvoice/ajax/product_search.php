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
$result = searchProducts($search, $product_type, $limit);

// Return JSON response
header('Content-Type: application/json');
echo json_encode($result);

/**
 * Search for products in the database
 * 
 * @param string $search Search term
 * @param int $product_type Product type (-1 for both, 0 for product, 1 for service)
 * @param int $limit Maximum number of results
 * @return array Search results
 */
function searchProducts($search, $product_type = -1, $limit = 10)
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
    $sql = "SELECT p.rowid, p.ref, p.label, p.description, p.price, p.price_ttc, p.tva_tx, p.fk_product_type, p.tosell";
    $sql .= " FROM ".MAIN_DB_PREFIX."product as p";
    $sql .= " WHERE (".implode(' AND ', $searchClauses).")";
    
    // Filter by product type if specified
    // if ($product_type >= 0) {
    //    $sql .= " AND p.fk_product_type = ".$product_type;
    // }
    
    // $sql .= " AND p.tosell = 1"; // Only active products
    
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
    
    // Parse results
    while ($obj = $db->fetch_object($resql)) {
        $product = array(
            'id' => $obj->rowid,
            'ref' => $obj->ref,
            'label' => $obj->label,
            'description' => $obj->description,
            'price' => price($obj->price),
            'price_ttc' => price($obj->price_ttc),
            'tva_tx' => $obj->tva_tx,
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