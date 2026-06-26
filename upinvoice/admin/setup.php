<?php
/* Copyright (C) 2023
 * Licensed under the GNU General Public License version 3
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
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

global $langs, $user;

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/ajax.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formadmin.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formother.class.php';

// Load translation files
$langs->loadLangs(array("admin", "upinvoice@upinvoice"));

// Access control
if (!$user->admin) accessforbidden();

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

$value = GETPOST('value', 'alpha');
$label = GETPOST('label', 'alpha');

$error = 0;
$setupNotice = '';

// Initialize technical objects
$form = new Form($db);
$formadmin = new FormAdmin($db);
$formother = new FormOther($db);

// Actions
if ($action == 'update') {
    //$res = dolibarr_set_const($db, "UPINVOICE_API_URL", GETPOST("UPINVOICE_API_URL", 'alpha'), 'chaine', 0, '', $conf->entity);
    //if (!($res > 0)) $error++;
    
    $res = dolibarr_set_const($db, "UPINVOICE_API_KEY", GETPOST("UPINVOICE_API_KEY", 'alpha'), 'chaine', 0, '', $conf->entity);
    if (!($res > 0)) $error++;


    if (!$error) {
        setEventMessages($langs->trans("SetupSaved"), null);
    } else {
        setEventMessages($langs->trans("Error"), null, 'errors');
    }

    $action = '';
}

// Create an EmailCollector preconfigured with the UpInvoice operation
if ($action == 'create_collector' && isModEnabled('emailcollector')) {
    require_once DOL_DOCUMENT_ROOT.'/emailcollector/class/emailcollector.class.php';
    require_once DOL_DOCUMENT_ROOT.'/emailcollector/class/emailcollectoraction.class.php';

    $db->begin();
    $errorcollector = 0;

    $emailcollector = new EmailCollector($db);
    $emailcollector->ref = 'UpInvoiceImport';
    $emailcollector->label = $langs->trans('UpInvoiceEmailCollectorLabel');
    $emailcollector->host = '';
    $emailcollector->port = '993';
    $emailcollector->source_directory = 'INBOX';
    $emailcollector->target_directory = 'UpInvoiceDone';
    $emailcollector->status = 0; // User must fill host/login before enabling

    $collectorid = $emailcollector->create($user);
    if ($collectorid > 0) {
        $emailcollectoraction = new EmailCollectorAction($db);
        $emailcollectoraction->fk_emailcollector = $collectorid;
        $emailcollectoraction->type = 'hook_upinvoice_import';
        $emailcollectoraction->actionparam = '';
        $emailcollectoraction->position = 50;
        $emailcollectoraction->status = 1;
        if ($emailcollectoraction->create($user) <= 0) {
            $errorcollector++;
            setEventMessages($emailcollectoraction->error, $emailcollectoraction->errors, 'errors');
        }
    } else {
        $errorcollector++;
        setEventMessages($emailcollector->error, $emailcollector->errors, 'errors');
    }

    if (!$errorcollector) {
        $db->commit();
        header("Location: ".DOL_URL_ROOT.'/admin/emailcollector_card.php?id='.$collectorid);
        exit;
    } else {
        $db->rollback();
    }

    $action = '';
}

/*
 * View
 */
$page_name = "UpInvoiceSetup";
$help_url = '';

llxHeader('', $langs->trans($page_name), $help_url);

// Subheader
$linkback = '<a href="'.($backtopage ?: DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.$langs->trans("BackToModuleList").'</a>';

print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

// Configuration header
$head = upinvoiceimport_admin_prepare_head();
print dol_get_fiche_head($head, 'settings', $langs->trans("Module500000Name"), -1, "bill");

// Module description
print '<div class="upinvoiceimport-description">';
print '<p>' . $langs->trans("UpInvoiceImportDescription") . '</p>';
print '</div>';

if ($setupNotice) print info_admin($setupNotice);

// Formulario de configuración
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td class="titlefield">' . $langs->trans("Parameter") . '</td>';
print '<td>' . $langs->trans("Value") . '</td>';
print '<td>' . $langs->trans("Comment") . '</td>';
print '</tr>';

// API URL
print '<tr class="oddeven">';
print '<td>' . $langs->trans("APIUrl") . '</td>';
print '<td>';
print '<input type="text" name="UPINVOICE_API_URL" value="https://upinvoice.eu/api/process-invoice" size="50" class="flat" disabled>';
print '</td>';
print '<td>' . $langs->trans("APIUrlHelp") . '</td>';
print '</tr>';

// API Key
print '<tr class="oddeven">';
print '<td>' . $langs->trans("APIKey") . '</td>';
print '<td>';
print '<input type="text" name="UPINVOICE_API_KEY" value="' . $conf->global->UPINVOICE_API_KEY . '" size="50" class="flat">';
print '</td>';
print '<td>' . $langs->trans("APIKeyHelp") . '</td>';
print '</tr>';

// Cómo conseguir una clave API (genera una clave autenticándote en upinvoice.eu y accediendo a https://upinvoice.eu/api/tokens con link)
print '<tr class="oddeven">';
print '<td colspan="3">';
print '<p>' . $langs->trans("HowToGetAPIKey") . '</p>';
print '</td>';
print '</tr>';

print '</table>';

print '<div class="tabsAction">';
print '<input type="submit" class="button" value="' . $langs->trans("Save") . '">';
print '</div>';

print '</form>';

// Account status block (loaded via AJAX if API key is configured)
$hasApiKey = !empty(getDolGlobalString('UPINVOICE_API_KEY'));
print '<div id="upinvoice-account-block" class="'.($hasApiKey ? '' : 'hidden').'">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td colspan="2">';
print $langs->trans("UpInvoiceAccountStatus");
print ' &nbsp; <button type="button" id="upinvoice-refresh-btn" class="button smallpaddingimp">';
print '<span class="fa fa-sync-alt"></span> ' . $langs->trans("UpInvoiceRefreshStatus");
print '</button>';
print '</td>';
print '</tr>';
print '<tr class="oddeven">';
print '<td colspan="2" id="upinvoice-account-content">';
if ($hasApiKey) {
    print '<span class="fa fa-spinner fa-spin"></span> ' . $langs->trans("UpInvoiceLoadingAccount");
}
print '</td>';
print '</tr>';
print '</table>';
print '</div>';

print '<br>';

// Email intake & automatic AI processing (toggles use ajax_constantonoff — no form needed)
print '<table class="noborder centpercent">';

print '<tr class="liste_titre">';
print '<td colspan="3">' . $langs->trans("UpInvoiceEmailIntakeSection") . '</td>';
print '</tr>';

// Enable EmailCollector operation
print '<tr class="oddeven">';
print '<td>' . $langs->trans("UpInvoiceEmailCollectorEnabled") . '</td>';
print '<td>' . ajax_constantonoff('UPINVOICE_EMAILCOLLECTOR_ENABLED', array(), $conf->entity) . '</td>';
print '<td>' . $langs->trans("UpInvoiceEmailCollectorEnabledHelp") . '</td>';
print '</tr>';

// Enable automatic AI processing by cron (only applies to source='email' files)
print '<tr class="oddeven">';
print '<td>' . $langs->trans("UpInvoiceAutoAiProcessing") . '</td>';
print '<td>' . ajax_constantonoff('UPINVOICE_AUTO_AI_PROCESSING', array(), $conf->entity) . '</td>';
print '<td>' . $langs->trans("UpInvoiceAutoAiProcessingHelp") . ' <a href="' . DOL_URL_ROOT . '/cron/list.php">' . $langs->trans("UpInvoiceSeeCronJobs") . '</a></td>';
print '</tr>';

print '</table>';

// EmailCollector status block
if (getDolGlobalString('UPINVOICE_EMAILCOLLECTOR_ENABLED')) {
    print '<br>';
    if (isModEnabled('emailcollector')) {
        // Look for a collector already using our operation
        $collectorid = 0;
        $collectorref = '';
        $sql = "SELECT ec.rowid, ec.ref FROM " . MAIN_DB_PREFIX . "emailcollector_emailcollector as ec";
        $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "emailcollector_emailcollectoraction as eca ON eca.fk_emailcollector = ec.rowid";
        $sql .= " WHERE eca.type = 'hook_upinvoice_import'";
        $sql .= " AND ec.entity IN (" . getEntity('emailcollector') . ")";
        $sql .= " ORDER BY ec.rowid ASC " . $db->plimit(1);
        $resql = $db->query($sql);
        if ($resql && $db->num_rows($resql) > 0) {
            $obj = $db->fetch_object($resql);
            $collectorid = $obj->rowid;
            $collectorref = $obj->ref;
        }

        if ($collectorid > 0) {
            print info_admin($langs->trans("UpInvoiceCollectorConfigured", '<a href="' . DOL_URL_ROOT . '/admin/emailcollector_card.php?id=' . ((int) $collectorid) . '">' . dol_escape_htmltag($collectorref) . '</a>'));
        } else {
            print info_admin($langs->trans("UpInvoiceNoCollectorYet"));
            print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
            print '<input type="hidden" name="token" value="' . newToken() . '">';
            print '<input type="hidden" name="action" value="create_collector">';
            print '<div class="tabsAction">';
            print '<input type="submit" class="button" value="' . $langs->trans("UpInvoiceCreateCollector") . '">';
            print '</div>';
            print '</form>';
        }
    } else {
        print info_admin($langs->trans("UpInvoiceEmailCollectorModuleDisabled", '<a href="' . DOL_URL_ROOT . '/admin/modules.php?search_keyword=emailcollector">' . $langs->trans("Modules") . '</a>'));
    }
}

// Test API Connection button
/*print '<br>';
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="test">';
print '<div class="center">';
print '<input type="submit" class="button" value="' . $langs->trans("TestAPIConnection") . '">';
print '</div>';
print '</form>';*/

print dol_get_fiche_end();

// JS for account status block (inline — setup.php does not load upinvoiceimport.js)
$checkAccountUrl = dol_buildpath('/upinvoice/ajax/check_account.php', 1);
$hasApiKeyJs = getDolGlobalString('UPINVOICE_API_KEY') ? 'true' : 'false';
print '<script type="text/javascript">
var upinvoiceHasKey = '.$hasApiKeyJs.';
var upinvoiceCheckUrl = "'.dol_escape_js($checkAccountUrl).'";
var upinvoiceAccountLabels = {
    loading:    "'.dol_escape_js($langs->trans("UpInvoiceLoadingAccount")).'",
    noKey:      "'.dol_escape_js($langs->trans("UpInvoiceAccountStatus")).'",
    planActive: "'.dol_escape_js($langs->trans("UpInvoicePlanActive")).'",
    planExpired:"'.dol_escape_js($langs->trans("UpInvoicePlanExpired")).'",
    creditsOf:  "'.dol_escape_js($langs->trans("UpInvoiceCreditsOf")).'",
    noCredits:  "'.dol_escape_js($langs->trans("UpInvoiceNoCredits")).'",
    expires:    "'.dol_escape_js($langs->trans("UpInvoicePlanExpires")).'",
    errInvalidKey:  "'.dol_escape_js($langs->trans("UpInvoiceErrInvalidKey")).'",
    errNoPlan:      "'.dol_escape_js($langs->trans("UpInvoiceErrNoPlan")).'",
    errRateLimit:   "'.dol_escape_js($langs->trans("UpInvoiceErrRateLimit")).'",
    errUnreachable: "'.dol_escape_js($langs->trans("UpInvoiceErrUnreachable")).'",
    errApi:         "'.dol_escape_js($langs->trans("UpInvoiceErrApi")).'"
};

function upinvoiceRenderAccountData(data) {
    var user = data.user || {};
    var plan = data.plan || {};
    var credits = data.credits || {};
    var remaining = parseInt(credits.remaining) || 0;
    var total = parseInt(credits.total) || 0;
    var used = parseInt(credits.used) || 0;
    var pct = total > 0 ? Math.round((remaining / total) * 100) : 0;
    var barPct = Math.min(100, pct);

    var planBadgeClass = (plan.status === "active") ? "badge badge-status-open" : "badge badge-status-closed";
    var planLabel = (plan.status === "active") ? upinvoiceAccountLabels.planActive : upinvoiceAccountLabels.planExpired;

    var barColor = barPct < 20 ? "#e05353" : (barPct < 40 ? "#e09253" : "#47a966");
    var wrapClass = barPct < 20 ? "upinvoice-credits-low" : "";

    var html = "<div class=\'upinvoice-account-info " + wrapClass + "\'>";
    html += "<strong>" + (user.name ? user.name + " &lt;" + user.email + "&gt;" : user.email) + "</strong>";
    html += " &nbsp;|&nbsp; " + (plan.name || "") + " <span class=\'" + planBadgeClass + "\'>" + planLabel + "</span>";

    html += "<div style=\'margin-top:6px\'>";
    html += "<div style=\'background:#e0e0e0;border-radius:4px;height:14px;width:260px;display:inline-block;vertical-align:middle\'>";
    html += "<div style=\'background:" + barColor + ";width:" + barPct + "%;height:14px;border-radius:4px\'></div>";
    html += "</div>";
    var creditsText = " <span id=\'upinvoice-credits-remaining\'>" + remaining + "</span>";
    if (total > 0 && remaining <= total) {
        creditsText += " / " + total + " " + upinvoiceAccountLabels.creditsOf + " (" + pct + "%)";
    } else if (total > 0) {
        creditsText += " " + upinvoiceAccountLabels.creditsOf;
    }
    html += creditsText;
    if (remaining === 0) {
        html += " &nbsp;<span style=\'color:#e05353;font-weight:bold\'>" + upinvoiceAccountLabels.noCredits + "</span>";
    }
    html += "</div>";

    if (plan.expires_at) {
        html += "<div style=\'margin-top:4px;font-size:0.9em;color:#666\'>" + upinvoiceAccountLabels.expires + ": " + plan.expires_at + "</div>";
    }
    html += "</div>";
    return html;
}

function upinvoiceRenderAccountError(code, http) {
    var msg;
    if (code === "invalid_key")    msg = upinvoiceAccountLabels.errInvalidKey;
    else if (code === "no_plan")   msg = upinvoiceAccountLabels.errNoPlan;
    else if (code === "rate_limit")msg = upinvoiceAccountLabels.errRateLimit;
    else if (code === "unreachable" || code === "timeout") msg = upinvoiceAccountLabels.errUnreachable;
    else msg = upinvoiceAccountLabels.errApi + (http ? " (HTTP " + http + ")" : "");
    return "<span style=\'color:#e05353\'><span class=\'fa fa-exclamation-triangle\'></span> " + msg + "</span>";
}

function loadAccountStatus(force) {
    var url = upinvoiceCheckUrl + (force ? "?force=1" : "");
    $("#upinvoice-account-content").html("<span class=\'fa fa-spinner fa-spin\'></span> " + upinvoiceAccountLabels.loading);
    $.ajax({
        url: url,
        type: "GET",
        dataType: "json",
        success: function(resp) {
            if (resp && resp.status === "success" && resp.data) {
                $("#upinvoice-account-content").html(upinvoiceRenderAccountData(resp.data));
                $("#upinvoice-account-block").show();
            } else {
                var code = resp ? resp.code : "api_error";
                var http = resp ? resp.http : null;
                $("#upinvoice-account-content").html(upinvoiceRenderAccountError(code, http));
                $("#upinvoice-account-block").show();
            }
        },
        error: function() {
            $("#upinvoice-account-content").html(upinvoiceRenderAccountError("unreachable", null));
            $("#upinvoice-account-block").show();
        }
    });
}

$(document).ready(function() {
    if (upinvoiceHasKey) {
        loadAccountStatus(false);
    }
    $("#upinvoice-refresh-btn").on("click", function() {
        loadAccountStatus(true);
    });
});
</script>';

// Page end
llxFooter();
$db->close();

/**
 * Prepare admin pages header
 *
 * @return array
 */
function upinvoiceimport_admin_prepare_head()
{
    global $langs, $conf;
    
    $langs->load("upinvoice@upinvoice");
    
    $h = 0;
    $head = array();
    
    $head[$h][0] = dol_buildpath("/upinvoice/admin/setup.php", 1);
    $head[$h][1] = $langs->trans("Settings");
    $head[$h][2] = 'settings';
    $h++;
    
    // Show more tabs from modules
    // Entries must be declared in modules descriptor with line
    // $this->tabs = array('entity:+tabname:Title:@mymodule:/mymodule/mypage.php?id=__ID__');   to add new tab
    // $this->tabs = array('entity:-tabname:Title:@mymodule:/mymodule/mypage.php?id=__ID__');   to remove a tab
    
    return $head;
}