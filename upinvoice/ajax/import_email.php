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

global $conf, $user, $langs, $db;

header('Content-Type: application/json');

$langs->loadLangs(array('upinvoice@upinvoice'));

// Only POST accepted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(array('status' => 'error', 'code' => 'method_not_allowed'));
    exit;
}

// Permission check
if (empty($user->rights->facture->lire)) {
    echo json_encode(array('status' => 'error', 'code' => 'forbidden'));
    exit;
}

// Feature gate
if (!getDolGlobalString('UPINVOICE_EMAILCOLLECTOR_ENABLED') || !isModEnabled('emailcollector')) {
    echo json_encode(array('status' => 'error', 'code' => 'not_configured'));
    exit;
}

$uid         = GETPOST('uid', 'alphanohtml');
$folder      = GETPOST('folder', 'alphanohtml');
$uidvalidity = GETPOSTINT('uidvalidity');

if (empty($uid) || empty($folder)) {
    echo json_encode(array('status' => 'error', 'code' => 'missing_params'));
    exit;
}

dol_include_once('/upinvoice/class/upinvoicemailbox.class.php');

$mb = new UpInvoiceMailbox($db);
if ($mb->load() < 0) {
    echo json_encode(array('status' => 'error', 'message' => $mb->error));
    exit;
}

$result = $mb->fetchAttachments($uid, $folder, $uidvalidity);

if ($result === -2 || (is_int($result) && $result < 0 && $mb->error === 'stale_list')) {
    echo json_encode(array('status' => 'error', 'code' => 'stale_list', 'message' => $langs->trans('StaleListRefresh')));
    exit;
}
if (!is_array($result)) {
    echo json_encode(array('status' => 'error', 'message' => $mb->error ?: 'Failed to fetch attachments'));
    exit;
}

$attachments = $result['attachments'];
if (empty($attachments)) {
    echo json_encode(array('status' => 'success', 'queued' => 0, 'skipped' => 0, 'reasons' => array()));
    exit;
}

// Optional: restrict the import to specific attachment filenames (per-attachment import).
// When omitted, every attachment of the email is queued (email-level import).
// restricthtml (not the default alphanohtml) so accents/spaces/parentheses in
// filenames survive — the value is only ever compared against the real attachment
// list fetched from IMAP, never used in SQL or echoed unescaped.
$onlyFilenames = GETPOST('filenames', 'array:restricthtml');
if (is_array($onlyFilenames) && !empty($onlyFilenames)) {
    $wanted = array();
    foreach ($onlyFilenames as $fn) {
        $wanted[(string) $fn] = true;
    }
    $attachments = array_values(array_filter($attachments, function ($att) use ($wanted) {
        return isset($wanted[(string) $att['filename']]);
    }));
    if (empty($attachments)) {
        echo json_encode(array('status' => 'success', 'queued' => 0, 'skipped' => 0, 'reasons' => array()));
        exit;
    }
}

// Manual import allows all supported formats regardless of rules
$allowedext = UpInvoiceFiles::EMAIL_ALLOWED_MIMES;

$entity  = (int) $conf->entity;
$nbQueued  = 0;
$nbSkipped = 0;
$reasons   = array();

foreach ($attachments as $att) {
    $reason = '';
    $errMsg = '';
    $fileId = UpInvoiceFiles::queueAttachment($db, $user, $att['filename'], $att['content'], $entity, $allowedext, 'email', '', $reason, $errMsg);

    if ($fileId < 0) {
        // Hard error — abort
        echo json_encode(array('status' => 'error', 'message' => $errMsg));
        exit;
    } elseif ($fileId === 0) {
        $nbSkipped++;
        $reasons[] = $att['filename'].': '.$reason;
    } else {
        $nbQueued++;
    }
}

// Invalidate email list cache so next load shows updated imported badges
$cacheKey = 'upinvoice_emails_cache';
if (isset($_SESSION[$cacheKey][$entity])) {
    unset($_SESSION[$cacheKey][$entity]);
}

echo json_encode(array(
    'status'  => 'success',
    'queued'  => $nbQueued,
    'skipped' => $nbSkipped,
    'reasons' => $reasons,
));
