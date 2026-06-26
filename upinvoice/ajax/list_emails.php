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

$langs->loadLangs(array('upinvoice@upinvoice'));

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

dol_include_once('/upinvoice/class/upinvoicemailbox.class.php');

$collectorId = UpInvoiceMailbox::findCollectorId($db);
if ($collectorId <= 0) {
    echo json_encode(array('status' => 'error', 'code' => 'no_collector'));
    exit;
}

$force = GETPOSTINT('force');
$cacheKey = 'upinvoice_emails_cache';
$cacheEntityKey = (int) $conf->entity;

// Serve from session cache unless forced
if (!$force
    && isset($_SESSION[$cacheKey][$cacheEntityKey])
    && is_array($_SESSION[$cacheKey][$cacheEntityKey])
    && !empty($_SESSION[$cacheKey][$cacheEntityKey]['ts'])
    && (time() - (int) $_SESSION[$cacheKey][$cacheEntityKey]['ts']) < 60
) {
    $cached = $_SESSION[$cacheKey][$cacheEntityKey];
    echo json_encode(array(
        'status' => 'success',
        'html'   => $cached['html'],
        'count'  => $cached['count'],
        'cached' => true,
        'ts'     => (int) $cached['ts'],
    ));
    exit;
}

// Load and connect
$mb = new UpInvoiceMailbox($db);
if ($mb->load() < 0) {
    echo json_encode(array('status' => 'error', 'message' => $mb->error));
    exit;
}

$emails = $mb->listEmails(50);
if ($emails === -2) {
    // OAuth not supported
    echo json_encode(array('status' => 'error', 'code' => 'oauth_not_supported', 'message' => $langs->trans('EmailOauthNotSupported')));
    exit;
}
if (!is_array($emails)) {
    $errMsg = $mb->error ?: 'Unknown error listing emails';
    if ($errMsg === 'imap_extension_missing') {
        $errMsg = $langs->trans('EmailImapExtensionMissing') ?: 'PHP IMAP extension is not installed. Ask your hosting provider to enable php-imap.';
    }
    echo json_encode(array('status' => 'error', 'message' => $errMsg));
    exit;
}

// Load active rules + stored labels of already-imported attachments (to show which rule applies)
dol_include_once('/upinvoice/class/upinvoicefiles.class.php');
$rules = UpInvoiceFiles::loadEmailRules($db, (int) $conf->entity);

// Hide blacklisted emails from the listing (display-only; never touches the collector)
$blRules = UpInvoiceFiles::loadBlacklistRules($db, (int) $conf->entity);
if (!empty($blRules) && is_array($emails) && !empty($emails)) {
    $emails = array_values(array_filter($emails, function ($em) use ($blRules) {
        return !UpInvoiceFiles::isEmailBlacklisted($blRules, $em);
    }));
}

$importedLabels = array();
$importedStatus = array(); // rowid => status (0 pending, 1 processed, -1 error)
if (is_array($emails) && !empty($emails)) {
    $allRowids = array();
    foreach ($emails as $email) {
        foreach ($email['attachments'] as $att) {
            $rid = isset($att['imported_rowid']) ? (int) $att['imported_rowid'] : 0;
            if ($rid > 0) {
                $allRowids[$rid] = $rid;
            }
        }
    }
    if (!empty($allRowids)) {
        $sqlLbl = "SELECT rowid, status, import_rule_label FROM ".MAIN_DB_PREFIX."upinvoice_files WHERE rowid IN (".implode(',', $allRowids).")";
        $resLbl = $db->query($sqlLbl);
        if ($resLbl) {
            while ($o = $db->fetch_object($resLbl)) {
                $importedLabels[(int) $o->rowid] = $o->import_rule_label;
                $importedStatus[(int) $o->rowid] = (int) $o->status;
            }
            $db->free($resLbl);
        }
    }
}

// Render HTML table
ob_start();

if (empty($emails)) {
    echo '<div class="upinvoice-empty-state"><i class="fas fa-inbox"></i><p>'.$langs->trans('NoEmailsFound').'</p></div>';
} else {
    // Pre-count emails that still have at least one attachment not yet imported,
    // so the toolbar can offer a one-click "import all pending".
    $nPendingEmails = 0;
    foreach ($emails as $e) {
        $t = count($e['attachments']);
        $imp = 0;
        foreach ($e['attachments'] as $a) {
            if ((isset($a['imported_rowid']) ? (int) $a['imported_rowid'] : 0) > 0 || !empty($a['imported'])) {
                $imp++;
            }
        }
        if ($t > 0 && $imp < $t) {
            $nPendingEmails++;
        }
    }

    // Summary toolbar (count + bulk action)
    echo '<div class="upinvoice-emails-toolbar">';
    echo '<span class="upinvoice-emails-summary">'.dol_escape_htmltag($langs->trans('EmailsSummary', count($emails), $nPendingEmails)).'</span>';
    if ($nPendingEmails > 0) {
        echo '<button class="button upinvoice-import-all-btn">'
            .'<i class="fas fa-file-import"></i> '
            .dol_escape_htmltag($langs->trans('ImportAllPending', $nPendingEmails))
            .'</button>';
    }
    echo '</div>';

    echo '<table class="noborder centpercent upinvoice-emails-table">';
    echo '<tr class="liste_titre">';
    echo '<th>'.$langs->trans('EmailFrom').'</th>';
    echo '<th>'.$langs->trans('EmailSubject').'</th>';
    echo '<th class="center">'.$langs->trans('EmailDate').'</th>';
    echo '<th>'.$langs->trans('EmailAttachments').'</th>';
    echo '<th class="center">'.$langs->trans('Status').'</th>';
    echo '<th class="center">'.$langs->trans('Actions').'</th>';
    echo '</tr>';

    $i = 0;
    foreach ($emails as $email) {
        // Per-attachment flags. Blacklisted and "ignored" attachments are excluded
        // from counts and actions: they show as disabled and never count as pending.
        //  - blacklist: matches an active blacklist rule
        //  - ignored:   a non-PDF attachment when the email also carries a PDF
        //               (logos, signatures, ... — only the PDF is the invoice)
        $attBlacklisted      = array();
        $attIgnored          = array();
        $importableFilenames = array(); // not imported, not blacklisted, not ignored

        // Pre-pass: blacklist flag + does the email carry a (non-blacklisted) PDF?
        $hasPdf = false;
        foreach ($email['attachments'] as $idx => $att) {
            $isBl = (!empty($blRules) && UpInvoiceFiles::matchBlacklistRule($blRules, $email['from'], $email['subject'], $att['filename'], $att['ext']) !== null);
            $attBlacklisted[$idx] = $isBl;
            if (!$isBl && strtolower($att['ext']) === 'pdf') {
                $hasPdf = true;
            }
        }

        $nTotal     = 0;
        $nImported  = 0;
        $nProcessed = 0;
        foreach ($email['attachments'] as $idx => $att) {
            if ($attBlacklisted[$idx]) {
                continue; // does not count towards total/imported/pending
            }

            $importedRowid = isset($att['imported_rowid']) ? (int) $att['imported_rowid'] : 0;
            $isImported = ($importedRowid > 0 || !empty($att['imported']));

            // When the email has a PDF, ignore the remaining non-PDF attachments that
            // are not already imported (already-imported ones keep showing as imported).
            if (!$isImported && $hasPdf && strtolower($att['ext']) !== 'pdf') {
                $attIgnored[$idx] = true;
                continue;
            }

            $nTotal++;
            if ($isImported) {
                $nImported++;
            } else {
                $importableFilenames[] = $att['filename'];
            }
            if ($importedRowid > 0 && isset($importedStatus[$importedRowid]) && $importedStatus[$importedRowid] === 1) {
                $nProcessed++;
            }
        }
        $allImported  = ($nTotal > 0 && $nImported === $nTotal);
        $allProcessed = ($allImported && $nProcessed === $nTotal);
        $someImported = ($nImported > 0 && !$allImported);
        if ($nTotal === 0) {
            // Defensive: fully-blacklisted emails are pre-filtered, but never offer actions here.
            $allImported = true;
            $allProcessed = true;
        }

        // Count attachments that an active rule would auto-import (and not yet imported)
        $nAutoPending = 0;
        $autoRuleLabel = '';

        $rowClass = ($i % 2 == 0) ? 'pair' : 'impair';
        // A fully processed email is shown dimmed (nothing left to do)
        if ($allProcessed) {
            $rowClass .= ' upinvoice-email-processed';
        }

        // Status key + search haystack drive the client-side filters
        if ($allProcessed) {
            $statusKey = 'processed';
        } elseif ($allImported) {
            $statusKey = 'imported';
        } elseif ($someImported) {
            $statusKey = 'partial';
        } else {
            $statusKey = 'new';
        }
        $searchHay = dol_strtolower($email['from'].' '.$email['subject']);

        echo '<tr class="'.$rowClass.'" data-status="'.$statusKey.'" data-search="'.dol_escape_htmltag($searchHay).'">';
        // From
        echo '<td class="tdoverflowmax150" title="'.dol_escape_htmltag($email['from']).'">'.dol_escape_htmltag($email['from']).'</td>';

        // Subject
        echo '<td class="tdoverflowmax200" title="'.dol_escape_htmltag($email['subject']).'">'.dol_escape_htmltag($email['subject']).'</td>';

        // Date
        $dateFormatted = dol_print_date(strtotime($email['date']), 'dayhour');
        echo '<td class="center nowrap">'.dol_escape_htmltag($dateFormatted).'</td>';

        // Attachments chips
        echo '<td>';
        foreach ($email['attachments'] as $idx => $att) {
            // Blacklisted attachment: show disabled, no actions, no auto-import tag
            if (!empty($attBlacklisted[$idx])) {
                echo '<span class="upinvoice-attach-chip upinvoice-chip-blacklisted" title="'.dol_escape_htmltag($att['filename'].' — '.$langs->trans('BlacklistedAttachment')).'">';
                echo '<i class="fas fa-ban"></i> '.dol_escape_htmltag($att['filename']);
                echo '</span>';
                echo '<span class="upinvoice-rule-tag upinvoice-rule-tag-blacklist" title="'.dol_escape_htmltag($langs->trans('BlacklistedAttachment')).'"><i class="fas fa-eye-slash"></i> '.$langs->trans('Blacklisted').'</span>';
                echo ' ';
                continue;
            }

            // Ignored non-PDF attachment (the email carries a PDF): show disabled, no actions
            if (!empty($attIgnored[$idx])) {
                echo '<span class="upinvoice-attach-chip upinvoice-chip-ignored" title="'.dol_escape_htmltag($att['filename'].' — '.$langs->trans('IgnoredNonPdfAttachment')).'">';
                echo '<i class="fas fa-image"></i> '.dol_escape_htmltag($att['filename']);
                echo '</span>';
                echo '<span class="upinvoice-rule-tag upinvoice-rule-tag-ignored" title="'.dol_escape_htmltag($langs->trans('IgnoredNonPdfAttachment')).'"><i class="fas fa-ban"></i> '.$langs->trans('Ignored').'</span>';
                echo ' ';
                continue;
            }

            $importedRowid = isset($att['imported_rowid']) ? (int) $att['imported_rowid'] : 0;
            $importedClass = ($importedRowid > 0) ? ' upinvoice-chip-imported' : '';

            // Which active rule (if any) covers this attachment
            $matchedRule = empty($rules) ? null : UpInvoiceFiles::matchAttachmentRule($rules, $email['from'], $email['subject'], $att['filename'], $att['ext']);

            echo '<span class="upinvoice-attach-chip'.$importedClass.'" title="'.dol_escape_htmltag($att['filename']).'">';
            echo dol_escape_htmltag($att['filename']);
            if ($importedRowid > 0) {
                echo ' <a href="#" class="upinvoice-chip-delete-btn" data-file-id="'.$importedRowid.'" title="'.dol_escape_htmltag($langs->trans('Delete')).'" style="color:inherit;opacity:0.7;margin-left:3px;">'."\n";
                echo '<i class="fas fa-times-circle"></i>';
                echo '</a>';
            } else {
                // Per-attachment import: queue only this single attachment
                echo ' <a href="#" class="upinvoice-chip-import-btn"'
                    .' data-uid="'.dol_escape_htmltag($email['uid']).'"'
                    .' data-folder="'.dol_escape_htmltag($email['folder']).'"'
                    .' data-uidvalidity="'.((int) $email['uidvalidity']).'"'
                    .' data-filename="'.dol_escape_htmltag($att['filename']).'"'
                    .' title="'.dol_escape_htmltag($langs->trans('ImportThisAttachment')).'">'
                    .'<i class="fas fa-file-import"></i>'
                    .'</a>';
            }
            echo '</span>';

            // Rule tag rendered as a sibling so it is never clipped by the chip width
            if ($importedRowid > 0) {
                $storedLabel = isset($importedLabels[$importedRowid]) ? $importedLabels[$importedRowid] : '';
                if (!empty($storedLabel)) {
                    echo '<span class="upinvoice-rule-tag" title="'.dol_escape_htmltag($langs->trans('ImportedByRule').': '.$storedLabel).'"><i class="fas fa-robot"></i> '.dol_escape_htmltag(dol_trunc($storedLabel, 24)).'</span>';
                }
            } elseif ($matchedRule !== null) {
                // Not imported yet but a rule will auto-import it
                $lbl = UpInvoiceFiles::ruleLabel($matchedRule);
                $nAutoPending++;
                if ($autoRuleLabel === '') {
                    $autoRuleLabel = $lbl;
                }
                echo '<span class="upinvoice-rule-tag upinvoice-rule-tag-auto" title="'.dol_escape_htmltag($langs->trans('AutoImportRule').': '.$lbl).'"><i class="fas fa-robot"></i> '.dol_escape_htmltag(dol_trunc($lbl, 24)).'</span>';
            }
            echo ' ';
        }
        echo '</td>';

        // Status column
        echo '<td class="center nowrap">';
        if ($allProcessed) {
            echo '<span class="badge badge-processed"><i class="fas fa-circle-check"></i> '.$langs->trans('Processed').'</span>';
        } elseif ($allImported) {
            echo '<span class="badge badge-status-open"><i class="fas fa-check"></i> '.$langs->trans('AllImported').'</span>';
        } elseif ($someImported) {
            echo '<span class="badge badge-status-inprogress"><i class="fas fa-circle-half-stroke"></i> '.$nImported.'/'.$nTotal.' '.$langs->trans('AlreadyImported').'</span>';
        } else {
            echo '<span class="badge badge-status-new"><i class="fas fa-inbox"></i> '.$langs->trans('PendingImport').'</span>';
        }
        echo '</td>';

        // Action column: manual import is always available while something is pending.
        // The "will auto-import" hint already lives on the attachment rule chip (Adjuntos), so we
        // don't repeat it here — just keep the manual button.
        echo '<td class="center nowrap">';
        if ($allImported || empty($importableFilenames)) {
            // Nothing left to import (all imported, or the rest is blacklisted)
        } else {
            // Restrict the import to the non-blacklisted, not-yet-imported attachments
            echo '<button class="button smallpaddingimp import-email-btn"'
                .' data-uid="'.dol_escape_htmltag($email['uid']).'"'
                .' data-folder="'.dol_escape_htmltag($email['folder']).'"'
                .' data-uidvalidity="'.((int) $email['uidvalidity']).'"'
                .' data-filenames="'.dol_escape_htmltag(json_encode(array_values($importableFilenames))).'"'
                .'>'
                .'<i class="fas fa-file-import"></i> '.$langs->trans('ImportToProcessing')
                .'</button>';
        }
        echo '</td>';
        echo '</tr>';
        $i++;
    }

    echo '</table>';
}

$html = ob_get_clean();
$count = count($emails);
$now = time();

// Store in session cache
if (!isset($_SESSION[$cacheKey])) {
    $_SESSION[$cacheKey] = array();
}
$_SESSION[$cacheKey][$cacheEntityKey] = array(
    'ts'    => $now,
    'html'  => $html,
    'count' => $count,
    // Structured list (no attachment contents) so "test rule" can match against
    // exactly what is shown, server-side, reusing attachmentMatchesRule().
    'data'  => $emails,
);

echo json_encode(array(
    'status' => 'success',
    'html'   => $html,
    'count'  => $count,
    'cached' => false,
    'ts'     => $now,
));
