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

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
require_once './class/upinvoicefiles.class.php';
dol_include_once('/upinvoice/class/upinvoicemailbox.class.php');

// Control access
if (!$user->rights->facture->lire) accessforbidden();

// Load translations
$langs->loadLangs(array("upinvoice@upinvoice", "bills", "other"));

// Define temp directory
$upload_dir = DOL_DATA_ROOT . '/upinvoice/temp';

// Create temp directory if it doesn't exist
if (!dol_is_dir($upload_dir)) {
    dol_mkdir($upload_dir);
}

// Initialize objects
$form = new Form($db);
$formfile = new FormFile($db);
$upinvoicefiles = new UpInvoiceFiles($db);

// Define page title and other vars
$page_name = "FileUploadTitle";
$help_url = '';
$morejs = array(
    '/upinvoice/js/upinvoiceimport.js'
);
$morecss = array(
    '/upinvoice/css/upinvoiceimport.css.php'
);

// Detect if the Emails tab should be available
$showEmailsTab = false;
if (getDolGlobalString('UPINVOICE_EMAILCOLLECTOR_ENABLED') && isModEnabled('emailcollector')) {
    $collectorId = UpInvoiceMailbox::findCollectorId($db);
    if ($collectorId > 0) {
        $showEmailsTab = true;
    }
}

// Get active tab
$active_tab = GETPOST('tab', 'alpha') ? GETPOST('tab', 'alpha') : 'pending';
// Fall back to pending if emails tab is requested but not available
if ($active_tab === 'emails' && !$showEmailsTab) {
    $active_tab = 'pending';
}

// Header
llxHeader('', $langs->trans($page_name), $help_url, '', 0, 0, $morejs, $morecss);

// Check if UpInvoice API key is configured
$apiKey = getDolGlobalString('UPINVOICE_API_KEY');

// Header bar: UpInvoice logo (left) + live credits/plan bar (right)
print '<div class="upinv-topbar">';
print '<a href="https://upinvoice.eu" target="_blank" rel="noopener"><img src="'.dol_buildpath('/upinvoice/img/upinvoice.png', 1).'" alt="UpInvoice" class="upinv-logo"></a>';
if (!empty($apiKey)) {
    print '<div id="upinvoice-credits-bar" class="upinv-credits">';
    print '<span class="fa fa-spinner fa-spin" id="upinvoice-credits-loading"></span>';
    print '<span id="upinvoice-credits-content" style="display:none"></span>';
    print '</div>';
}
print '</div>';

// Warn if API key not configured
if (empty($apiKey)) {
    print '<div class="warning">';
    print $langs->trans("WarningUpInvoiceAPIKeyNotConfigured");
    if ($user->admin) {
        print ' <a href="' . dol_buildpath('/upinvoice/admin/setup.php',1) . '">' . $langs->trans("GoToModuleSetup") . '</a>';
    }
    print '</div>';
}

// Start container
print '<div class="upinvoiceimport-container">';

// AJAX response container for upload results
print '<div id="upload-results" class="upinvoiceimport-messages"></div>';

// Eliminado: Botones de procesamiento masivo
// print '<div class="upinvoiceimport-action-buttons">';
// print '<button class="btn btn-primary" id="process-all-files-btn"><i class="fas fa-cogs"></i> ' . $langs->trans('ProcessAllFiles') . '</button>';
// print '<button class="btn btn-secondary" id="toggle-queue-btn"><i class="fas fa-pause"></i> ' . $langs->trans('PauseProcessing') . '</button>';
// print '</div>';

// Drag and drop upload zone with file previews
print '<div class="upinvoiceimport-dropzone-container">';
print '<div class="upinvoiceimport-dropzone" id="dropzone">';
print '<div class="dropzone-content">';
print '<i class="fas fa-upload fa-4x"></i>';
print '<h3>' . $langs->trans('DragDropFiles') . '</h3>';
print '<p>' . $langs->trans('OrClickToSelect') . '</p>';
print '<input type="file" id="fileupload" name="fileupload[]" multiple style="display:none;">';
print '<button class="btn btn-primary" id="select-files">' . $langs->trans('SelectFiles') . '</button>';
print '</div>';

// Preview zone for files being uploaded - Integrated into dropzone
print '<div id="upload-previews" class="upload-previews-container" style="display: none;"></div>';
print '</div>';
print '</div>';

// Tabs navigation
print '<div class="tabs" data-role="tabs">';
print '<ul class="tab-nav">';
print '<li class="tab-element' . ($active_tab == 'pending' ? ' active' : '') . '">';
print '<a href="'.dol_buildpath('/upinvoice/upload.php', 1).'?tab=pending" class="tab-link" data-target="pending-files">' . $langs->trans('PendingFiles') . ' <span class="upinvoice-count-badge" id="pending-count-badge"></span></a>';
print '</li>';
if ($showEmailsTab) {
    print '<li class="tab-element' . ($active_tab == 'emails' ? ' active' : '') . '">';
    print '<a href="'.dol_buildpath('/upinvoice/upload.php', 1).'?tab=emails" class="tab-link" data-target="emails-tab">' . $langs->trans('EmailsTab') . '</a>';
    print '</li>';
}
print '<li class="tab-element' . ($active_tab == 'finished' ? ' active' : '') . '">';
print '<a href="'.dol_buildpath('/upinvoice/upload.php', 1).'?tab=finished" class="tab-link" data-target="finished-files">' . $langs->trans('FinishedFiles') . '</a>';
print '</li>';
print '</ul>';

// Tab content
print '<div class="tab-content-container">';

// Pending Files tab
print '<div id="pending-files" class="tab-content' . ($active_tab == 'pending' ? ' active' : '') . '">';
print '<div class="upinvoiceimport-files-container">';
print '<h3>' . $langs->trans('PendingProcessing') . '</h3>';

// Toolbar: search + status filters + sort (left) and queue / bulk controls (right).
print '<div class="upinvoice-files-toolbar" id="pending-toolbar">';

print '<span class="upinvoice-search-wrap"><i class="fas fa-search"></i>';
print '<input type="text" id="files-search" class="flat" placeholder="' . dol_escape_htmltag($langs->trans('SearchFilesPlaceholder')) . '">';
print '</span>';

print '<span class="upinvoice-status-filters" id="files-status-filters">';
print '<button type="button" class="button button-statusfilter active" data-status="all">' . $langs->trans('FilterAll') . '</button>';
print '<button type="button" class="button button-statusfilter" data-status="pending">' . $langs->trans('FilterPending') . '</button>';
print '<button type="button" class="button button-statusfilter" data-status="processed">' . $langs->trans('FilterProcessed') . '</button>';
print '<button type="button" class="button button-statusfilter" data-status="error">' . $langs->trans('FilterError') . '</button>';
print '</span>';

print '<label class="upinvoice-sort-wrap small opacitymedium">' . $langs->trans('SortBy') . ' ';
print '<select id="files-sort" class="flat">';
print '<option value="date_desc">' . $langs->trans('SortNewest') . '</option>';
print '<option value="date_asc">' . $langs->trans('SortOldest') . '</option>';
print '<option value="name_asc">' . $langs->trans('SortName') . '</option>';
print '<option value="size_desc">' . $langs->trans('SortSize') . '</option>';
print '<option value="status_asc">' . $langs->trans('SortStatus') . '</option>';
print '</select>';
print '</label>';

print '<span class="upinvoice-files-toolbar-right">';
print '<button type="button" class="button" id="queue-toggle-btn"><i class="fas fa-pause"></i> <span class="label">' . $langs->trans('PauseQueue') . '</span></button>';
print '<button type="button" class="button" id="retry-errors-btn"><i class="fas fa-redo"></i> ' . $langs->trans('RetryErrors') . '</button>';
print '<button type="button" class="button" id="refresh-files-btn"><i class="fas fa-sync-alt"></i> ' . $langs->trans('RefreshList') . '</button>';
print '</span>';
print '</div>';

print '<div id="pending-files-list" class="upinvoiceimport-files-list"></div>';
print '<div class="upinvoice-empty-state" id="pending-no-match" style="display:none"><i class="fas fa-filter"></i><p>' . $langs->trans('NoFilesMatchFilter') . '</p></div>';
print '</div>';
print '</div>'; // End pending files tab

// Emails tab (visible only when EmailCollector + UpInvoice collector are configured)
if ($showEmailsTab) {
    print '<div id="emails-tab" class="tab-content' . ($active_tab == 'emails' ? ' active' : '') . '">';
    print '<div class="upinvoiceimport-files-container">';
    print '<h3>' . $langs->trans('EmailsTab') . '</h3>';

    // Single toolbar: search + status filters (left) and refresh + last-updated
    // hint + auto-refresh (right), all on one line.
    print '<div class="upinvoice-emails-filters" id="emails-filters">';
    print '<span class="upinvoice-search-wrap"><i class="fas fa-search"></i>';
    print '<input type="text" id="emails-search" class="flat" placeholder="' . dol_escape_htmltag($langs->trans('SearchSenderSubject')) . '">';
    print '</span>';
    print '<span class="upinvoice-status-filters">';
    print '<button type="button" class="button button-statusfilter active" data-status="all">' . $langs->trans('FilterAll') . '</button>';
    print '<button type="button" class="button button-statusfilter" data-status="pending">' . $langs->trans('FilterPending') . '</button>';
    print '<button type="button" class="button button-statusfilter" data-status="imported">' . $langs->trans('FilterImported') . '</button>';
    print '<button type="button" class="button button-statusfilter" data-status="processed">' . $langs->trans('FilterProcessed') . '</button>';
    print '</span>';
    print '<span class="upinvoice-filters-right">';
    print '<button class="button" id="refresh-emails-btn"><i class="fas fa-sync-alt"></i> ' . $langs->trans('RefreshEmails') . '</button>';
    print '<span class="opacitymedium small" id="emails-cache-hint"></span>';
    print '<label class="opacitymedium small" style="cursor:pointer">';
    print '<input type="checkbox" id="emails-autorefresh"> ' . $langs->trans('EmailAutoRefresh');
    print '</label>';
    print '</span>';
    print '</div>';

    // Email list container
    print '<div id="emails-list"></div>';

    // Email import rules section (collapsible — keeps the email list as the focus)
    print '<div class="upinvoice-rules-section" style="margin-top:24px">';
    print '<h4 class="upinvoice-rules-toggle" id="rules-toggle" role="button" tabindex="0">';
    print '<i class="fas fa-chevron-right toggle-caret"></i> ' . $langs->trans('EmailRulesTitle');
    print ' <span class="opacitymedium small" id="rules-count-badge"></span>';
    print '</h4>';

    print '<div class="upinvoice-rules-body" id="rules-body" style="display:none">';
    print '<p class="opacitymedium">' . $langs->trans('EmailRulesHelp') . '</p>';

    // Add rule form
    print '<div class="upinvoice-rule-form" style="background:#f8f8f8;border:1px solid #ddd;padding:10px;border-radius:4px;margin-bottom:12px;display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end">';
    print '<div><label>' . $langs->trans('SenderContains') . '</label><br>';
    print '<input type="text" id="rule-sender" class="flat" style="width:180px" placeholder="*">';
    print '</div>';
    print '<div><label>' . $langs->trans('SubjectContains') . '</label><br>';
    print '<input type="text" id="rule-subject" class="flat" style="width:180px" placeholder="*">';
    print '</div>';
    print '<div><label title="' . dol_escape_htmltag($langs->trans('FilenamePatternHelp')) . '">' . $langs->trans('FilenamePattern') . '</label><br>';
    print '<input type="text" id="rule-filename" class="flat" style="width:180px" placeholder="fact*.pdf">';
    print '</div>';
    print '<div><label>' . $langs->trans('AllowedFormats') . '</label><br>';
    print '<label><input type="radio" name="rule-format" class="rule-format-chk" value="pdf" checked> PDF</label> ';
    print '<label><input type="radio" name="rule-format" class="rule-format-chk" value="png"> PNG</label> ';
    print '<label><input type="radio" name="rule-format" class="rule-format-chk" value="jpg"> JPG</label>';
    print '</div>';
    print '<div><button class="button" id="add-rule-btn"><i class="fas fa-plus"></i> ' . $langs->trans('AddRule') . '</button>';
    print ' <button type="button" class="button" id="test-rule-btn" title="' . dol_escape_htmltag($langs->trans('TestRuleHelp')) . '"><i class="fas fa-flask"></i> ' . $langs->trans('TestRule') . '</button>';
    print ' <a href="#" id="cancel-rule-edit" style="display:none;margin-left:6px">' . $langs->trans('Cancel') . '</a></div>';
    print '</div>';

    // Dry-run result of "Test rule"
    print '<div id="rule-test-result" class="upinvoice-rule-test-result" style="display:none"></div>';

    // Rules list
    print '<div id="email-rules-list"></div>';
    print '</div>'; // end rules body
    print '</div>'; // end rules section

    // Blacklist section (collapsible) — rules here only HIDE matching emails from
    // the listing; they never affect the collector / auto-import.
    print '<div class="upinvoice-rules-section" style="margin-top:18px">';
    print '<h4 class="upinvoice-rules-toggle" id="blacklist-toggle" role="button" tabindex="0">';
    print '<i class="fas fa-chevron-right toggle-caret"></i> ' . $langs->trans('EmailBlacklistTitle');
    print ' <span class="opacitymedium small" id="blacklist-count-badge"></span>';
    print '</h4>';

    print '<div class="upinvoice-rules-body" id="blacklist-body" style="display:none">';
    print '<p class="opacitymedium">' . $langs->trans('EmailBlacklistHelp') . '</p>';

    // Add blacklist rule form
    print '<div class="upinvoice-rule-form" style="background:#f8f8f8;border:1px solid #ddd;padding:10px;border-radius:4px;margin-bottom:12px;display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end">';
    print '<div><label>' . $langs->trans('SenderContains') . '</label><br>';
    print '<input type="text" id="bl-sender" class="flat" style="width:180px" placeholder="*">';
    print '</div>';
    print '<div><label>' . $langs->trans('SubjectContains') . '</label><br>';
    print '<input type="text" id="bl-subject" class="flat" style="width:180px" placeholder="*">';
    print '</div>';
    print '<div><label title="' . dol_escape_htmltag($langs->trans('FilenamePatternHelp')) . '">' . $langs->trans('FilenamePattern') . '</label><br>';
    print '<input type="text" id="bl-filename" class="flat" style="width:180px" placeholder="*.pdf">';
    print '</div>';
    print '<div><label>' . $langs->trans('AllowedFormats') . '</label><br>';
    print '<label><input type="radio" name="bl-format" class="bl-format-chk" value="pdf" checked> PDF</label> ';
    print '<label><input type="radio" name="bl-format" class="bl-format-chk" value="png"> PNG</label> ';
    print '<label><input type="radio" name="bl-format" class="bl-format-chk" value="jpg"> JPG</label>';
    print '</div>';
    print '<div><button class="button" id="add-bl-btn"><i class="fas fa-plus"></i> ' . $langs->trans('AddRule') . '</button>';
    print ' <button type="button" class="button" id="test-bl-btn" title="' . dol_escape_htmltag($langs->trans('TestBlacklistHelp')) . '"><i class="fas fa-flask"></i> ' . $langs->trans('TestRule') . '</button>';
    print ' <a href="#" id="cancel-bl-edit" style="display:none;margin-left:6px">' . $langs->trans('Cancel') . '</a></div>';
    print '</div>';

    // Dry-run result of "Test rule"
    print '<div id="bl-test-result" class="upinvoice-rule-test-result" style="display:none"></div>';

    // Blacklist rules list
    print '<div id="blacklist-list"></div>';
    print '</div>'; // end blacklist body
    print '</div>'; // end blacklist section

    print '</div>'; // end upinvoiceimport-files-container
    print '</div>'; // end emails-tab
}

// Finished Files tab
print '<div id="finished-files" class="tab-content' . ($active_tab == 'finished' ? ' active' : '') . '">';
print '<div class="upinvoiceimport-files-container">';
print '<h3>' . $langs->trans('ProcessedInvoices') . '</h3>';

// Tabla de Dolibarr para archivos finalizados - Estructura modificada según los requisitos
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>' . $langs->trans("FileName") . '</th>';
print '<th class="right">' . $langs->trans("Size") . '</th>';
print '<th class="center">' . $langs->trans("UploadDate") . '</th>';
print '<th class="center">' . $langs->trans("CompletionDate") . '</th>';
print '<th class="center">' . $langs->trans("InvoiceDate") . '</th>';
print '<th class="right">' . $langs->trans("TotalTTC") . '</th>';
print '<th>' . $langs->trans("Supplier") . '</th>';
print '<th class="center">' . $langs->trans("Actions") . '</th>';
print '</tr>';

// El contenido de la tabla será cargado dinámicamente por AJAX
print '<tbody id="finished-files-list"></tbody>';
print '</table>';

// Paginación (renderizada por JS con los datos que devuelve list_files.php)
print '<div id="finished-files-pagination" class="center" style="margin-top:10px;"></div>';

print '</div>';
print '</div>'; // End finished files tab

print '</div>'; // End tab content

print '</div>'; // End tabs

print '</div>'; // Close container

// Add JavaScript for the page
?>
<script type="text/javascript">
    var upinvoiceimport_root = '<?php echo dirname($_SERVER['PHP_SELF']); ?>';
    var upinvoiceimport_token = '<?php echo newToken(); ?>';
    var upinvoiceimport_active_tab = '<?php echo $active_tab; ?>';
    var upinvoiceimport_langs = {
        'ConfirmDeleteFile': '<?php echo html_entity_decode($langs->trans("ConfirmDeleteFile")); ?>',
        'ErrorProcessingResponse': '<?php echo html_entity_decode($langs->trans("ErrorProcessingResponse")); ?>',
        'DeleteFailed': '<?php echo html_entity_decode($langs->trans("DeleteFailed")); ?>',
        'Processing': '<?php echo html_entity_decode($langs->trans("Processing")); ?>',
        'Processed': '<?php echo html_entity_decode($langs->trans("Processed")); ?>',
        'FileProcessedSuccessfully': '<?php echo html_entity_decode($langs->trans("FileProcessedSuccessfully")); ?>',
        'ProcessingFailed': '<?php echo html_entity_decode($langs->trans("ProcessingFailed")); ?>',
        'NextStep': '<?php echo html_entity_decode($langs->trans("NextStep")); ?>',
        'Retry': '<?php echo html_entity_decode($langs->trans("Retry")); ?>',
        'PreviewNotAvailable': '<?php echo html_entity_decode($langs->trans("PreviewNotAvailable")); ?>',
        'ProcessingInProgress': '<?php echo html_entity_decode($langs->trans("ProcessingInProgress")); ?>',
        'FilePreview': '<?php echo html_entity_decode($langs->trans("FilePreview")); ?>',
        'ValidateInvoice': '<?php echo html_entity_decode($langs->trans("ValidateInvoice")); ?>',
        'ViewInvoice': '<?php echo html_entity_decode($langs->trans("ViewInvoice")); ?>',
        'Loading': '<?php echo html_entity_decode($langs->trans("Loading")); ?>',
        'NoPendingFiles': '<?php echo html_entity_decode($langs->trans("NoPendingFiles")); ?>',
        'NoFinishedFiles': '<?php echo html_entity_decode($langs->trans("NoFinishedFiles")); ?>',
        'ProcessingWithAI': '<?php echo html_entity_decode($langs->trans("ProcessingWithAI")); ?>',
        'QueueRunning': '<?php echo html_entity_decode($langs->trans("QueueRunning")); ?>',
        'QueuePausedNotice': '<?php echo html_entity_decode($langs->trans("QueuePausedNotice")); ?>',
        'PauseQueue': '<?php echo html_entity_decode($langs->trans("PauseQueue")); ?>',
        'ResumeQueue': '<?php echo html_entity_decode($langs->trans("ResumeQueue")); ?>',
        'NoFilesToProcess': '<?php echo html_entity_decode($langs->trans("NoFilesToProcess")); ?>',
        'DeleteProcessedCreditWarning': '<?php echo html_entity_decode($langs->trans("DeleteProcessedCreditWarning")); ?>',
        'ConfirmTitle': '<?php echo html_entity_decode($langs->trans("ConfirmTitle")); ?>',
        'ConfirmDelete': '<?php echo html_entity_decode($langs->trans("ConfirmDelete")); ?>',
        'Cancel': '<?php echo html_entity_decode($langs->trans("Cancel")); ?>',
        'UpInvoiceCreditsRemaining': '<?php echo html_entity_decode($langs->trans("UpInvoiceCreditsRemaining")); ?>',
        'UpInvoiceCreditsOf': '<?php echo html_entity_decode($langs->trans("UpInvoiceCreditsOf")); ?>',
        'UpInvoiceNoCredits': '<?php echo html_entity_decode($langs->trans("UpInvoiceNoCredits")); ?>',
        'UpInvoiceLoadingAccount': '<?php echo html_entity_decode($langs->trans("UpInvoiceLoadingAccount")); ?>',
        'EmailListCachedHint': '<?php echo html_entity_decode($langs->trans("EmailListCachedHint")); ?>',
        'EmailUpdatedJustNow': '<?php echo html_entity_decode($langs->trans("EmailUpdatedJustNow")); ?>',
        'EmailUpdatedMinAgo': '<?php echo html_entity_decode($langs->trans("EmailUpdatedMinAgo")); ?>',
        'EmailUpdatedHAgo': '<?php echo html_entity_decode($langs->trans("EmailUpdatedHAgo")); ?>',
        'NoEmailsFound': '<?php echo html_entity_decode($langs->trans("NoEmailsFound")); ?>',
        'EmailListError': '<?php echo html_entity_decode($langs->trans("EmailListError")); ?>',
        'ImportedNAttachments': '<?php echo html_entity_decode($langs->trans("ImportedNAttachments")); ?>',
        'ImportThisAttachment': '<?php echo html_entity_decode($langs->trans("ImportThisAttachment")); ?>',
        'BulkImportQueued': '<?php echo html_entity_decode($langs->trans("BulkImportQueued")); ?>',
        'NoEmailsMatchFilter': '<?php echo html_entity_decode($langs->trans("NoEmailsMatchFilter")); ?>',
        'TestRuleResult': '<?php echo html_entity_decode($langs->trans("TestRuleResult")); ?>',
        'TestBlacklistResult': '<?php echo html_entity_decode($langs->trans("TestBlacklistResult")); ?>',
        'TestRuleNoList': '<?php echo html_entity_decode($langs->trans("TestRuleNoList")); ?>',
        'StaleListRefresh': '<?php echo html_entity_decode($langs->trans("StaleListRefresh")); ?>',
        'ConfirmDeleteRule': '<?php echo html_entity_decode($langs->trans("ConfirmDeleteRule")); ?>',
        'ConfirmDeleteFile': '<?php echo html_entity_decode($langs->trans("ConfirmDeleteFile")); ?>',
        'LoadingEmails': '<?php echo html_entity_decode($langs->trans("LoadingEmails")); ?>',
        'ImportToProcessing': '<?php echo html_entity_decode($langs->trans("ImportToProcessing")); ?>',
        'RuleAdded': '<?php echo html_entity_decode($langs->trans("RuleAdded")); ?>',
        'RuleDeleted': '<?php echo html_entity_decode($langs->trans("RuleDeleted")); ?>',
        'RuleUpdated': '<?php echo html_entity_decode($langs->trans("RuleUpdated")); ?>',
        'AddRule': '<?php echo html_entity_decode($langs->trans("AddRule")); ?>',
        'SaveRule': '<?php echo html_entity_decode($langs->trans("SaveRule")); ?>'
    };
    var upinvoiceCheckUrl = '<?php echo dol_escape_js(dol_buildpath("/upinvoice/ajax/check_account.php", 1)); ?>';
    var upinvoiceHasKey = <?php echo !empty($apiKey) ? 'true' : 'false'; ?>;
    var upinvoiceShowEmailsTab = <?php echo $showEmailsTab ? 'true' : 'false'; ?>;
    var upinvoiceEmailsUrl = '<?php echo dol_escape_js(dol_buildpath("/upinvoice/ajax/list_emails.php", 1)); ?>';
    var upinvoiceImportEmailUrl = '<?php echo dol_escape_js(dol_buildpath("/upinvoice/ajax/import_email.php", 1)); ?>';
    var upinvoiceEmailRulesUrl = '<?php echo dol_escape_js(dol_buildpath("/upinvoice/ajax/email_rules.php", 1)); ?>';
    var upinvoiceDeleteFileUrl = '<?php echo dol_escape_js(dol_buildpath("/upinvoice/ajax/delete_file.php", 1)); ?>';
</script>
<script type="text/javascript">
$(document).ready(function() {
    // Initialize the uploader
    var dropzone = document.getElementById('dropzone');
    var fileInput = document.getElementById('fileupload');
    var selectButton = document.getElementById('select-files');
    var uploadPreviews = document.getElementById('upload-previews');
    
    // Handle file selection button
    selectButton.addEventListener('click', function(e) {
        e.preventDefault();
        fileInput.click();
    });
    
    // Handle file input change
    fileInput.addEventListener('change', function() {
        if (this.files.length) {
            handleFiles(this.files);
        }
    });
    
    // Handle drag and drop events
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        dropzone.addEventListener(eventName, preventDefaults, false);
    });
    
    function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }
    
    ['dragenter', 'dragover'].forEach(eventName => {
        dropzone.addEventListener(eventName, highlight, false);
    });
    
    ['dragleave', 'drop'].forEach(eventName => {
        dropzone.addEventListener(eventName, unhighlight, false);
    });
    
    function highlight() {
        dropzone.classList.add('highlight');
    }
    
    function unhighlight() {
        dropzone.classList.remove('highlight');
    }
    
    // Handle dropped files
    dropzone.addEventListener('drop', function(e) {
        var dt = e.dataTransfer;
        var files = dt.files;
        
        handleFiles(files);
    });
    
    // Tabs functionality
    $('.tab-link').on('click', function(e) {
        e.preventDefault();
        var targetTab = $(this).data('target');

        // Map data-target to tab param
        var tabParam = 'pending';
        if (targetTab === 'finished-files') {
            tabParam = 'finished';
        } else if (targetTab === 'emails-tab') {
            tabParam = 'emails';
        }

        // Update URL with tab parameter without reloading
        var url = new URL(window.location.href);
        url.searchParams.set('tab', tabParam);
        window.history.pushState({}, '', url);

        // Activate tab
        $('.tab-element').removeClass('active');
        $(this).parent().addClass('active');

        // Show tab content
        $('.tab-content').removeClass('active');
        $('#' + targetTab).addClass('active');

        // Update active tab variable
        upinvoiceimport_active_tab = tabParam;

        // Load appropriate content
        if (tabParam === 'emails') {
            loadEmailsList(false);
            loadEmailRules();
        } else {
            loadFilesList();
        }
    });

    // Email search + status filters (client-side over the loaded list)
    (function initEmailFilters() {
        var $s = $('#emails-search');
        if (!$s.length) return;

        // Restore persisted filter
        try {
            var saved = JSON.parse(localStorage.getItem('upinvoice_email_filter') || '{}');
            if (saved.q) { $s.val(saved.q); }
            if (saved.status) {
                $('#emails-filters .button-statusfilter').removeClass('active');
                $('#emails-filters .button-statusfilter[data-status="' + saved.status + '"]').addClass('active');
            }
        } catch (e) {}

        function persist() {
            try {
                localStorage.setItem('upinvoice_email_filter', JSON.stringify({
                    q: $s.val(),
                    status: $('#emails-filters .button-statusfilter.active').data('status') || 'all'
                }));
            } catch (e) {}
        }

        $s.on('input', function() { applyEmailFilters(); persist(); });
        $('#emails-filters').on('click', '.button-statusfilter', function() {
            $('#emails-filters .button-statusfilter').removeClass('active');
            $(this).addClass('active');
            applyEmailFilters();
            persist();
        });
    })();

    // Collapsible sections (collapsed by default, state persisted per section)
    function initCollapsible(toggleSel, bodySel, storageKey) {
        var $toggle = $(toggleSel);
        if (!$toggle.length) return;

        function setOpen(open) {
            $(bodySel).toggle(open);
            $toggle.find('.toggle-caret')
                .toggleClass('fa-chevron-down', open)
                .toggleClass('fa-chevron-right', !open);
            try { localStorage.setItem(storageKey, open ? '1' : '0'); } catch (e) {}
        }

        var startOpen = false;
        try { startOpen = localStorage.getItem(storageKey) === '1'; } catch (e) {}
        setOpen(startOpen);

        $toggle.on('click', function() { setOpen($(bodySel).is(':hidden')); });
        $toggle.on('keydown', function(e) {
            if (e.which === 13 || e.which === 32) { e.preventDefault(); setOpen($(bodySel).is(':hidden')); }
        });
    }
    initCollapsible('#rules-toggle', '#rules-body', 'upinvoice_rules_open');
    initCollapsible('#blacklist-toggle', '#blacklist-body', 'upinvoice_blacklist_open');

    // "Updated X ago" ticker + optional auto-refresh of the email list
    (function initEmailsAutoRefresh() {
        var $chk = $('#emails-autorefresh');
        if (!$chk.length) return;

        var AUTO_REFRESH_SECONDS = 120;

        // Restore persisted preference
        try { $chk.prop('checked', localStorage.getItem('upinvoice_emails_autorefresh') === '1'); } catch (e) {}
        $chk.on('change', function() {
            try { localStorage.setItem('upinvoice_emails_autorefresh', $chk.prop('checked') ? '1' : '0'); } catch (e) {}
        });

        // Single ticker: keeps the relative-time hint fresh and triggers auto-refresh.
        setInterval(function() {
            if (upinvoiceimport_active_tab !== 'emails') return;
            if (typeof updateEmailsHint === 'function') updateEmailsHint();

            if ($chk.prop('checked') && _emailsLastTs) {
                var age = Math.floor(Date.now() / 1000) - _emailsLastTs;
                if (age >= AUTO_REFRESH_SECONDS) loadEmailsList(true);
            }
        }, 20000);
    })();
    
    // Process files
    function handleFiles(files) {
        if (files.length === 0) return;
        
        // First show file previews
        showFilePreviews(files);
        
        // Check for duplicates
        checkForDuplicates(files, function(nonDuplicateFiles) {
            if (nonDuplicateFiles.length === 0) {
                // All files were duplicates
                showNotification('<i class="fas fa-info-circle"></i> ' + '<?php echo $langs->trans('AllFilesAreDuplicates'); ?>', 'warning');
                return;
            }
            
            uploadFiles(nonDuplicateFiles);
        });
    }
    
    // Show file previews before upload
    function showFilePreviews(files) {
        uploadPreviews.innerHTML = '';
        
        for (var i = 0; i < files.length; i++) {
            var file = files[i];
            var previewItem = document.createElement('div');
            previewItem.className = 'upload-preview-item-compact';
            previewItem.id = 'upload-preview-' + i;
            
            // Add appropriate icon based on file type
            var iconClass = 'fas fa-file';
            if (file.type.indexOf('pdf') !== -1) {
                iconClass = 'fas fa-file-pdf';
            } else if (file.type.indexOf('image') !== -1) {
                iconClass = 'fas fa-file-image';
            }
            
            previewItem.innerHTML = `
                <div class="preview-icon"><i class="${iconClass}"></i></div>
                <div class="preview-info-compact">
                    <div class="preview-name" title="${file.name}">${file.name}</div>
                    <div class="progress-pizza">
                        <svg width="20" height="20">
                            <circle class="bg" cx="10" cy="10" r="9"></circle>
                            <circle class="bar" cx="10" cy="10" r="9" style="stroke-dashoffset: 56.5;"></circle>
                        </svg>
                    </div>
                    <i class="fas fa-check-circle status-icon status-icon-success"></i>
                    <i class="fas fa-exclamation-triangle status-icon status-icon-warning"></i>
                    <i class="fas fa-times-circle status-icon status-icon-error"></i>
                    <span class="preview-status-text"></span>
                </div>
                <i class="fas fa-times remove-preview" title="Dismiss"></i>
            `;
            
            // Handle remove button
            previewItem.querySelector('.remove-preview').addEventListener('click', function(e) {
                e.stopPropagation();
                var item = this.closest('.upload-preview-item-compact');
                $(item).fadeOut(300, function() {
                    $(this).remove();
                    if ($('#upload-previews').children().length === 0) {
                        $('.dropzone-content').fadeIn(300);
                        $('#upload-previews').hide();
                    }
                });
            });
            
            uploadPreviews.appendChild(previewItem);
        }
        
        // Show upload previews container and hide dropzone text
        uploadPreviews.style.display = 'flex';
        $(dropzone).find('.dropzone-content').fadeOut(300);
    }
    
    // Update preview progress
    function updatePreviewProgress(index, percentage) {
        var $preview = $('#upload-preview-' + index);
        if ($preview.length) {
            var circle = $preview.find('.progress-pizza circle.bar');
            if (circle.length) {
                var offset = 56.5 * (1 - (percentage / 100));
                circle.css('stroke-dashoffset', offset);
            }
        }
    }
    
    // Check for duplicate files - this checks both pending and finished files
    function checkForDuplicates(files, callback) {
        var nonDuplicateFiles = [];
        var checkCount = 0;
        var duplicateCount = 0;
        
        for (var i = 0; i < files.length; i++) {
            (function(file, index) {
                // Check if this file already exists
                $.ajax({
                    url: '<?php echo dol_buildpath('/upinvoice/ajax/check_duplicate.php', 1); ?>',
                    type: 'POST',
                    data: {
                        token: '<?php echo newToken(); ?>',
                        filename: file.name,
                        filesize: file.size
                    },
                    success: function(response) {
                        checkCount++;
                        
                        try {
                            var result = JSON.parse(response);
                            if (result.status === 'success') {
                                if (result.isDuplicate) {
                                    duplicateCount++;
                                    // Update preview to show duplicate
                                    updatePreviewToDuplicate(index, '<?php echo $langs->trans('FileAlreadyUploaded'); ?>');
                                } else {
                                    nonDuplicateFiles.push({file: file, index: index});
                                }
                            } else {
                                nonDuplicateFiles.push({file: file, index: index});
                            }
                        } catch (e) {
                            nonDuplicateFiles.push({file: file, index: index});
                        }
                        
                        if (checkCount === files.length) {
                            callback(nonDuplicateFiles);
                        }
                    },
                    error: function() {
                        checkCount++;
                        nonDuplicateFiles.push({file: file, index: index});
                        if (checkCount === files.length) {
                            callback(nonDuplicateFiles);
                        }
                    }
                });
            })(files[i], i);
        }
    }
    
    // Update preview to show file is duplicate
    function updatePreviewToDuplicate(index, message) {
        var $preview = $('#upload-preview-' + index);
        if ($preview.length) {
            $preview.addClass('duplicate');
            $preview.find('.preview-status-text').text(message);
        }
    }
    
    // Upload non-duplicate files
    function uploadFiles(fileInfos) {
        if (fileInfos.length === 0) return;
        
        var formData = new FormData();
        
        for (var i = 0; i < fileInfos.length; i++) {
            var fileInfo = fileInfos[i];
            formData.append('userfile[]', fileInfo.file);
            updatePreviewProgress(fileInfo.index, 5);
        }
        
        formData.append('token', '<?php echo newToken(); ?>');
        
        $.ajax({
            url: '<?php echo dol_buildpath('/upinvoice/ajax/upload.php', 1); ?>',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            xhr: function() {
                var xhr = new XMLHttpRequest();
                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        var percentComplete = Math.round((e.loaded / e.total) * 100);
                        for (var i = 0; i < fileInfos.length; i++) {
                            updatePreviewProgress(fileInfos[i].index, percentComplete);
                            
                            // Option 1: Show "Saving/Processing" message when upload is technically complete
                            if (percentComplete >= 100) {
                                var $preview = $('#upload-preview-' + fileInfos[i].index);
                                if ($preview.length) {
                                    $preview.find('.preview-status-text').text('<?php echo $langs->trans("Saving"); ?>...');
                                    $preview.find('.progress-pizza').addClass('processing-pulse');
                                }
                            }
                        }
                    }
                }, false);
                return xhr;
            },
            success: function(response) {
                try {
                    if (response.charAt(0) !== '{') {
                        showNotification('<i class="fas fa-exclamation-circle"></i> ' + response, 'error');
                        return;
                    }
                    var result = JSON.parse(response);
                    if (result.status === 'success') {
                        // Update preview status for each file
                        for (var i = 0; i < result.files.length; i++) {
                            var fileResult = result.files[i];
                            var fileIndex = -1;
                            
                            for (var j = 0; j < fileInfos.length; j++) {
                                if (fileInfos[j].file.name === fileResult.name) {
                                    fileIndex = fileInfos[j].index;
                                    break;
                                }
                            }
                            
                            if (fileIndex !== -1) {
                                var $preview = $('#upload-preview-' + fileIndex);
                                if (fileResult.status === 'success') {
                                    updatePreviewProgress(fileIndex, 100);
                                    $preview.addClass('success');
                                    
                                    // Auto-dismiss successful uploads after 4 seconds
                                    (function($el) {
                                        setTimeout(function() {
                                            $el.fadeOut(500, function() { 
                                                $(this).remove(); 
                                                // If no more previews, show dropzone content again
                                                if ($('#upload-previews').children().length === 0) {
                                                    $('.dropzone-content').fadeIn(300);
                                                    $('#upload-previews').hide();
                                                }
                                            });
                                        }, 4000);
                                    })($preview);
                                } else {
                                    $preview.addClass('error');
                                    $preview.find('.preview-status-text').text(fileResult.message);
                                }
                            }
                        }
                        
                        setTimeout(function() {
                            loadFilesList();
                        }, 1000);
                    } else {
                        showNotification('<i class="fas fa-exclamation-circle"></i> ' + result.message, 'error');
                    }
                } catch (e) {
                    showNotification('<i class="fas fa-exclamation-circle"></i> ' + '<?php echo $langs->trans('ErrorProcessingResponse'); ?>', 'error');
                }
            },
            error: function(xhr, status, error) {
                showNotification('<i class="fas fa-exclamation-circle"></i> ' + '<?php echo $langs->trans('UploadFailed'); ?>: ' + error, 'error');
            }
        });
    }
    
    // Register the loadFilesList function globally so it can be called from other scripts
    upinvoiceLoadFilesListFunction = loadFilesList;

    // Initial load of files list or email tab content
    if (upinvoiceimport_active_tab === 'emails') {
        loadEmailsList(false);
        loadEmailRules();
    } else {
        loadFilesList();
    }
});
</script>
<?php
// Footer
llxFooter();
$db->close();