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

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once './class/upinvoicefiles.class.php';

// Access control
if (!$user->rights->facture->lire) accessforbidden();

// Load translations
$langs->loadLangs(array("upinvoice@upinvoice", "admin", "bills"));

// Initialize objects
$upinvoicefiles = new UpInvoiceFiles($db);

// Parameters
$action = GETPOST('action', 'aZ09');

// Define page title
$page_name = "UpInvoiceImportArea";
$help_url = '';
$morejs = array(
    '/upinvoice/js/upinvoiceimport.js'
);
$morecss = array(
    '/upinvoice/css/upinvoiceimport.css.php'
);

// Page header
llxHeader('', $langs->trans($page_name), $help_url, '', 0, 0, $morejs, $morecss);

print load_fiche_titre($langs->trans($page_name), '', 'title_setup');

// Check if UpInvoice API key is configured
$apiKey = $conf->global->UPINVOICE_API_KEY;
if (empty($apiKey)) {
    print '<div class="warning">';
    print $langs->trans("WarningUpInvoiceAPIKeyNotConfigured");
    if ($user->admin) {
        print ' <a href="' . dol_buildpath('/upinvoice/admin/setup.php',1) . '">' . $langs->trans("GoToModuleSetup") . '</a>';
    }
    print '</div>';
}

// Module description
print '<div class="upinvoiceimport-description">';
print '<p>' . $langs->trans("UpInvoiceImportDescription") . '</p>';
print '</div>';

// Main navigation cards
print '<div class="upinvoiceimport-cards">';

// Card 1: Upload Files
print '<div class="upinvoiceimport-card">';
print '<div class="upinvoiceimport-card-header">';
print '<i class="fas fa-upload fa-2x"></i>';
print '<h2>' . $langs->trans("UploadFiles") . '</h2>';
print '</div>';
print '<div class="upinvoiceimport-card-body">';
print '<p>' . $langs->trans("UploadFilesDescription") . '</p>';
print '<a href="' . dol_buildpath('/upinvoice/upload.php',1) .'" class="butAction">' . $langs->trans("GoToUpload") . '</a>';
print '</div>';
print '</div>';

// Card 2: Files Status
print '<div class="upinvoiceimport-card">';
print '<div class="upinvoiceimport-card-header">';
print '<i class="fas fa-tasks fa-2x"></i>';
print '<h2>' . $langs->trans("FilesStatus") . '</h2>';
print '</div>';
print '<div class="upinvoiceimport-card-body">';

// Count files in different statuses
$sql = "SELECT 
            SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as processed,
            SUM(CASE WHEN status = -1 THEN 1 ELSE 0 END) as error,
            COUNT(*) as total
        FROM " . MAIN_DB_PREFIX . "upinvoice_files
        WHERE entity = " . $conf->entity;

$resql = $db->query($sql);
if ($resql) {
    $obj = $db->fetch_object($resql);
    
    print '<div class="upinvoiceimport-stats">';
    
    // Pending files
    print '<div class="upinvoiceimport-stat">';
    print '<span class="stat-value">' . $obj->pending . '</span>';
    print '<span class="stat-label">' . $langs->trans("PendingFiles") . '</span>';
    print '</div>';
    
    // Processed files
    print '<div class="upinvoiceimport-stat">';
    print '<span class="stat-value">' . $obj->processed . '</span>';
    print '<span class="stat-label">' . $langs->trans("ProcessedFiles") . '</span>';
    print '</div>';
    
    // Error files
    print '<div class="upinvoiceimport-stat">';
    print '<span class="stat-value">' . $obj->error . '</span>';
    print '<span class="stat-label">' . $langs->trans("ErrorFiles") . '</span>';
    print '</div>';
    
    // Total files
    print '<div class="upinvoiceimport-stat">';
    print '<span class="stat-value">' . $obj->total . '</span>';
    print '<span class="stat-label">' . $langs->trans("TotalFiles") . '</span>';
    print '</div>';
    
    print '</div>';
} else {
    print '<p class="error">' . $langs->trans("ErrorFetchingStats") . '</p>';
}

print '<a href="' . dol_buildpath('/upinvoice/upload.php',1) .'" class="butAction">' . $langs->trans("ViewDetails") . '</a>';
print '</div>';
print '</div>';

print '</div>'; // Close cards container

// Add JS variables for use in JavaScript
?>
<script type="text/javascript">
    var upinvoiceimport_root = '<?php echo dirname($_SERVER['PHP_SELF']); ?>';
    var upinvoiceimport_token = '<?php echo newToken(); ?>';
    var upinvoiceimport_langs = {
        'ConfirmDeleteFile': '<?php echo $langs->trans("ConfirmDeleteFile"); ?>',
        'ErrorProcessingResponse': '<?php echo $langs->trans("ErrorProcessingResponse"); ?>',
        'DeleteFailed': '<?php echo $langs->trans("DeleteFailed"); ?>'
    };
</script>
<?php
// Footer
llxFooter();
$db->close();