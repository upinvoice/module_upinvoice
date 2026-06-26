<?php
/* Copyright (C) 2026
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
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
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
if (!$res && file_exists("../../main.inc.php")) {
    $res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
    $res = @include "../../../main.inc.php";
}
if (!$res) {
    die("Include of main fails");
}

global $conf, $user, $langs;

header('Content-Type: application/json');

// Permission check: same requirement as reading supplier invoices
if (empty($user->rights->facture->lire)) {
    echo json_encode(array('status' => 'error', 'code' => 'forbidden'));
    exit;
}

$apiKey = getDolGlobalString('UPINVOICE_API_KEY');
if (empty($apiKey)) {
    echo json_encode(array('status' => 'error', 'code' => 'no_key'));
    exit;
}

// Build the /api/check URL from the configured process URL (avoids an extra constant)
$processUrl = getDolGlobalString('UPINVOICE_API_URL', 'https://upinvoice.eu/api/process-invoice');
$checkUrl = str_replace('/api/process-invoice', '/api/check', $processUrl);

$force = GETPOSTINT('force');
$cacheKey = 'upinvoice_account_cache';
$cacheEntityKey = (int) $conf->entity;

// Serve from PHP session cache unless forced refresh
if (!$force
    && isset($_SESSION[$cacheKey][$cacheEntityKey])
    && is_array($_SESSION[$cacheKey][$cacheEntityKey])
    && !empty($_SESSION[$cacheKey][$cacheEntityKey]['ts'])
    && (time() - (int) $_SESSION[$cacheKey][$cacheEntityKey]['ts']) < 60
) {
    echo json_encode($_SESSION[$cacheKey][$cacheEntityKey]['payload']);
    exit;
}

// Call upinvoice.eu/api/check
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $checkUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPGET, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Authorization: Bearer '.$apiKey,
    'Accept: application/json',
));

$response = curl_exec($ch);
$curlErrno = curl_errno($ch);
$curlError = curl_error($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlErrno) {
    // Distinguish timeout (28) from host unreachable (7) for useful feedback
    $code = ($curlErrno == 28) ? 'timeout' : 'unreachable';
    $result = array('status' => 'error', 'code' => $code, 'message' => $curlError);
    echo json_encode($result);
    exit;
}

if ($httpCode === 200) {
    $json = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE || empty($json['success']) || empty($json['data'])) {
        $result = array('status' => 'error', 'code' => 'invalid_response');
    } else {
        $result = array('status' => 'success', 'data' => $json['data']);
    }
} elseif ($httpCode === 401) {
    $result = array('status' => 'error', 'code' => 'invalid_key');
} elseif ($httpCode === 403) {
    $result = array('status' => 'error', 'code' => 'no_plan');
} elseif ($httpCode === 429) {
    $result = array('status' => 'error', 'code' => 'rate_limit');
} else {
    $result = array('status' => 'error', 'code' => 'api_error', 'http' => $httpCode);
}

// Cache successful responses (and rate-limit errors to avoid hammering)
if ($result['status'] === 'success' || $result['code'] === 'rate_limit') {
    if (!isset($_SESSION[$cacheKey])) {
        $_SESSION[$cacheKey] = array();
    }
    $_SESSION[$cacheKey][$cacheEntityKey] = array(
        'ts' => time(),
        'payload' => $result,
    );
}

echo json_encode($result);
exit;
