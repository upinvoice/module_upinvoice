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

// Read permission required for list; write required for mutations
if (empty($user->rights->facture->lire)) {
    echo json_encode(array('status' => 'error', 'code' => 'forbidden'));
    exit;
}

$op     = GETPOST('op', 'alpha');
$entity = (int) $conf->entity;

// Which rule set this request targets. Whitelisted -> physical table name.
// 'import'    => auto-import rules (affect the collector)
// 'blacklist' => listing-only hide rules (never affect the collector)
$target = GETPOST('target', 'aZ09');
$tableByTarget = array(
    'import'    => MAIN_DB_PREFIX.'upinvoice_email_rules',
    'blacklist' => MAIN_DB_PREFIX.'upinvoice_email_blacklist',
);
if (!isset($tableByTarget[$target])) {
    $target = 'import';
}
$table = $tableByTarget[$target];

// Mutation ops require create permission
$mutationOps = array('add', 'update', 'delete', 'toggle');
if (in_array($op, $mutationOps) && empty($user->rights->facture->creer)) {
    echo json_encode(array('status' => 'error', 'code' => 'forbidden'));
    exit;
}

// ---------------------------------------------------------------------------
// Helper: render rules table HTML
// ---------------------------------------------------------------------------
function renderRulesHtml(DoliDB $db, Translate $langs, int $entity, string $table): string
{
    $sql = "SELECT rowid, sender_contains, subject_contains, filename_pattern, formats, status";
    $sql .= " FROM ".$table;
    $sql .= " WHERE entity = ".$entity;
    $sql .= " ORDER BY rowid ASC";
    $resql = $db->query($sql);

    if (!$resql) {
        return '<p class="error">'.$langs->trans('DatabaseError').'</p>';
    }

    $rows = array();
    while ($obj = $db->fetch_object($resql)) {
        $rows[] = $obj;
    }
    $db->free($resql);

    if (empty($rows)) {
        return '<p class="opacitymedium">'.$langs->trans('NoEmailRules').'</p>';
    }

    $html  = '<table class="noborder centpercent">';
    $html .= '<tr class="liste_titre">';
    $html .= '<th>'.$langs->trans('SenderContains').'</th>';
    $html .= '<th>'.$langs->trans('SubjectContains').'</th>';
    $html .= '<th>'.$langs->trans('FilenamePattern').'</th>';
    $html .= '<th>'.$langs->trans('AllowedFormats').'</th>';
    $html .= '<th class="center">'.$langs->trans('Status').'</th>';
    $html .= '<th class="center">'.$langs->trans('Actions').'</th>';
    $html .= '</tr>';

    $i = 0;
    foreach ($rows as $row) {
        $rowClass   = ($i % 2 == 0) ? 'pair' : 'impair';
        $activeClass = $row->status ? '' : ' opacitymedium';
        $html .= '<tr class="'.$rowClass.$activeClass.'">';
        $html .= '<td>'.dol_escape_htmltag($row->sender_contains ?: '*').'</td>';
        $html .= '<td>'.dol_escape_htmltag($row->subject_contains ?: '*').'</td>';
        $html .= '<td>'.dol_escape_htmltag($row->filename_pattern ?: '*').'</td>';
        $html .= '<td>'.dol_escape_htmltag($row->formats).'</td>';

        $toggleLabel = $row->status ? $langs->trans('RuleEnabled') : $langs->trans('RuleDisabled');
        $toggleIcon  = $row->status ? 'check' : 'times';
        $html .= '<td class="center">';
        $html .= '<button class="button smallpaddingimp rule-toggle-btn" data-rule-id="'.((int) $row->rowid).'" data-status="'.((int) $row->status).'">'
            .'<i class="fas fa-'.$toggleIcon.'"></i> '.$toggleLabel
            .'</button>';
        $html .= '</td>';

        $html .= '<td class="center nowrap">';
        $html .= '<button class="button smallpaddingimp rule-edit-btn" data-rule-id="'.((int) $row->rowid).'"'
            .' data-sender="'.dol_escape_htmltag((string) $row->sender_contains).'"'
            .' data-subject="'.dol_escape_htmltag((string) $row->subject_contains).'"'
            .' data-filename="'.dol_escape_htmltag((string) $row->filename_pattern).'"'
            .' data-formats="'.dol_escape_htmltag((string) $row->formats).'"'
            .' title="'.dol_escape_htmltag($langs->trans('EditRule')).'">'
            .'<i class="fas fa-pen"></i>'
            .'</button> ';
        $html .= '<button class="button buttonDelete smallpaddingimp rule-delete-btn" data-rule-id="'.((int) $row->rowid).'">'
            .'<i class="fas fa-trash"></i>'
            .'</button>';
        $html .= '</td>';

        $html .= '</tr>';
        $i++;
    }

    $html .= '</table>';
    return $html;
}

/**
 * Parse and sanitize the rule form input (shared by add + update).
 *
 * @return array array('sender'=>, 'subject'=>, 'filename'=>, 'formats'=>)
 */
function upinvoiceParseRuleInput()
{
    $sender  = trim(GETPOST('sender_contains', 'alphanohtml'));
    $subject = trim(GETPOST('subject_contains', 'alphanohtml'));

    // Filename glob pattern (e.g. "fact*.pdf"): whitelist alnum plus . _ - * ? and spaces
    $filename = trim(GETPOST('filename_pattern', 'restricthtml'));
    $filename = preg_replace('/[^A-Za-z0-9_\-\.\*\?\s]/', '', $filename);
    $filename = dol_trunc($filename, 250, 'right', 'UTF-8', 1);

    // A rule targets exactly ONE attachment format (radio in the UI).
    $allowedFmts = array('pdf', 'png', 'jpg');
    $rawFormats  = GETPOST('formats', 'array');
    if (!is_array($rawFormats)) {
        $rawFormats = array();
    }
    $format = '';
    foreach ($rawFormats as $fmt) {
        $fmt = strtolower(trim($fmt));
        if (in_array($fmt, $allowedFmts, true)) {
            $format = $fmt;
            break;
        }
    }
    // A concrete extension in the filename pattern wins (so "fact*.png" just works).
    if (!empty($filename)) {
        $patExt = strtolower(pathinfo(str_replace(array('*', '?'), '', $filename), PATHINFO_EXTENSION));
        if ($patExt === 'jpeg') {
            $patExt = 'jpg';
        }
        if (in_array($patExt, $allowedFmts, true)) {
            $format = $patExt;
        }
    }
    if ($format === '') {
        $format = 'pdf';
    }

    return array(
        'sender'   => $sender,
        'subject'  => $subject,
        'filename' => $filename,
        'formats'  => $format,
    );
}

// ---------------------------------------------------------------------------
// Dispatch
// ---------------------------------------------------------------------------

if ($op === 'list') {
    $html = renderRulesHtml($db, $langs, $entity, $table);
    echo json_encode(array('status' => 'success', 'html' => $html));
    exit;
}

if ($op === 'test') {
    // Dry-run the form values against the currently listed emails (from session),
    // reusing the same matcher the real import uses — single source of truth.
    dol_include_once('/upinvoice/class/upinvoicefiles.class.php');

    $in   = upinvoiceParseRuleInput();
    $rule = (object) array(
        'sender_contains'  => $in['sender'],
        'subject_contains' => $in['subject'],
        'filename_pattern' => $in['filename'],
        'formats'          => $in['formats'],
    );

    $emails = isset($_SESSION['upinvoice_emails_cache'][$entity]['data'])
        ? $_SESSION['upinvoice_emails_cache'][$entity]['data']
        : null;
    if (!is_array($emails)) {
        echo json_encode(array('status' => 'error', 'code' => 'no_list', 'message' => $langs->trans('TestRuleNoList')));
        exit;
    }

    $nAtt = 0;
    $nEmails = 0;
    $samples = array();
    foreach ($emails as $em) {
        $nMatchInEmail = 0;
        $nAttInEmail = count($em['attachments']);
        foreach ($em['attachments'] as $att) {
            $matches = ($target === 'blacklist')
                ? UpInvoiceFiles::attachmentMatchesBlacklistRule($rule, $em['from'], $em['subject'], $att['filename'], $att['ext'])
                : UpInvoiceFiles::attachmentMatchesRule($rule, $em['from'], $em['subject'], $att['filename'], $att['ext']);
            if ($matches) {
                $nAtt++;
                $nMatchInEmail++;
                if (count($samples) < 5) {
                    $samples[] = $att['filename'];
                }
            }
        }
        if ($target === 'blacklist') {
            // An email is hidden only when ALL its attachments match (mirrors isEmailBlacklisted)
            if ($nAttInEmail > 0 && $nMatchInEmail === $nAttInEmail) {
                $nEmails++;
            }
        } elseif ($nMatchInEmail > 0) {
            $nEmails++;
        }
    }

    echo json_encode(array(
        'status'  => 'success',
        'att'     => $nAtt,
        'emails'  => $nEmails,
        'samples' => $samples,
    ));
    exit;
}

if ($op === 'add') {
    $in = upinvoiceParseRuleInput();

    $sql = "INSERT INTO ".$table;
    $sql .= " (entity, sender_contains, subject_contains, filename_pattern, formats, status, date_creation, fk_user_creat)";
    $sql .= " VALUES (";
    $sql .= $entity.",";
    $sql .= (!empty($in['sender'])   ? "'".$db->escape($in['sender'])."'"   : "NULL").",";
    $sql .= (!empty($in['subject'])  ? "'".$db->escape($in['subject'])."'"  : "NULL").",";
    $sql .= (!empty($in['filename']) ? "'".$db->escape($in['filename'])."'" : "NULL").",";
    $sql .= "'".$db->escape($in['formats'])."',";
    $sql .= "1,";
    $sql .= "'".$db->idate(dol_now())."',";
    $sql .= ((int) $user->id);
    $sql .= ")";

    $resql = $db->query($sql);
    if (!$resql) {
        echo json_encode(array('status' => 'error', 'message' => $db->lasterror()));
        exit;
    }

    $html = renderRulesHtml($db, $langs, $entity, $table);
    echo json_encode(array('status' => 'success', 'message' => $langs->trans('RuleAdded'), 'html' => $html));
    exit;
}

if ($op === 'update') {
    $ruleId = GETPOSTINT('rule_id');
    if ($ruleId <= 0) {
        echo json_encode(array('status' => 'error', 'code' => 'invalid_id'));
        exit;
    }
    $in = upinvoiceParseRuleInput();

    $sql = "UPDATE ".MAIN_DB_PREFIX."upinvoice_email_rules SET";
    $sql .= " sender_contains = ".(!empty($in['sender'])   ? "'".$db->escape($in['sender'])."'"   : "NULL").",";
    $sql .= " subject_contains = ".(!empty($in['subject'])  ? "'".$db->escape($in['subject'])."'"  : "NULL").",";
    $sql .= " filename_pattern = ".(!empty($in['filename']) ? "'".$db->escape($in['filename'])."'" : "NULL").",";
    $sql .= " formats = '".$db->escape($in['formats'])."'";
    $sql .= " WHERE rowid = ".$ruleId." AND entity = ".$entity;

    $resql = $db->query($sql);
    if (!$resql) {
        echo json_encode(array('status' => 'error', 'message' => $db->lasterror()));
        exit;
    }

    $html = renderRulesHtml($db, $langs, $entity, $table);
    echo json_encode(array('status' => 'success', 'message' => $langs->trans('RuleUpdated'), 'html' => $html));
    exit;
}

if ($op === 'delete') {
    $ruleId = GETPOSTINT('rule_id');
    if ($ruleId <= 0) {
        echo json_encode(array('status' => 'error', 'code' => 'invalid_id'));
        exit;
    }

    $sql = "DELETE FROM ".$table;
    $sql .= " WHERE rowid = ".$ruleId." AND entity = ".$entity;
    $resql = $db->query($sql);
    if (!$resql) {
        echo json_encode(array('status' => 'error', 'message' => $db->lasterror()));
        exit;
    }

    $html = renderRulesHtml($db, $langs, $entity, $table);
    echo json_encode(array('status' => 'success', 'message' => $langs->trans('RuleDeleted'), 'html' => $html));
    exit;
}

if ($op === 'toggle') {
    $ruleId    = GETPOSTINT('rule_id');
    $newStatus = GETPOSTINT('status') ? 0 : 1; // invert current status
    if ($ruleId <= 0) {
        echo json_encode(array('status' => 'error', 'code' => 'invalid_id'));
        exit;
    }

    $sql = "UPDATE ".$table;
    $sql .= " SET status = ".$newStatus;
    $sql .= " WHERE rowid = ".$ruleId." AND entity = ".$entity;
    $resql = $db->query($sql);
    if (!$resql) {
        echo json_encode(array('status' => 'error', 'message' => $db->lasterror()));
        exit;
    }

    $msg = $newStatus ? $langs->trans('RuleEnabled') : $langs->trans('RuleDisabled');
    $html = renderRulesHtml($db, $langs, $entity, $table);
    echo json_encode(array('status' => 'success', 'message' => $msg, 'html' => $html));
    exit;
}

// Unknown op
echo json_encode(array('status' => 'error', 'code' => 'unknown_op'));
