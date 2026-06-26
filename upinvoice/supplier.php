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

require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formcompany.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formother.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.class.php';
require_once './class/upinvoicefiles.class.php';
require_once './class/upinvoicesupplier.class.php';

// Control access
if (!$user->rights->facture->lire) accessforbidden();

// Load translations
$langs->loadLangs(array("upinvoice@upinvoice", "bills", "companies", "other"));

// Get file ID
$file_id = GETPOST('file_id', 'int');
if (empty($file_id)) {
    setEventMessages($langs->trans("NoFileSelected"), null, 'errors');
    header("Location: ".dol_buildpath("/upinvoice/upload.php",1));
    exit;
}

// Initialize objects
$upinvoicefiles = new UpInvoiceFiles($db);
$upinvoicesupplier = new UpInvoiceSupplier($db);
$form = new Form($db);
$formcompany = new FormCompany($db);
$formother = new FormOther($db);

// Get file ID
$file_id = GETPOST('file_id', 'int');
if (empty($file_id)) {
    setEventMessages($langs->trans("NoFileSelected"), null, 'errors');
    header("Location: ".dol_buildpath('/upinvoice/upload.php', 1));
    exit;
}

// Initialize objects
$upinvoicefiles = new UpInvoiceFiles($db);
$upinvoicesupplier = new UpInvoiceSupplier($db);
$form = new Form($db);
$formcompany = new FormCompany($db);
$formother = new FormOther($db);

// Fetch file record
$result = $upinvoicefiles->fetch($file_id);
if ($result <= 0) {
    setEventMessages($langs->trans("FileNotFound"), null, 'errors');
    header("Location: ".dol_buildpath('/upinvoice/upload.php', 1));
    exit;
}

// Check if file has API JSON data
if (empty($upinvoicefiles->api_json)) {
    setEventMessages($langs->trans("FileNotProcessed"), null, 'errors');
    header("Location: ".dol_buildpath('/upinvoice/upload.php', 1));
    exit;
}

// Parse JSON data
$invoice_data = json_decode($upinvoicefiles->api_json, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    setEventMessages($langs->trans("InvalidJSONData").': '.json_last_error_msg(), null, 'errors');
    header("Location: ".dol_buildpath('/upinvoice/upload.php', 1));
    exit;
}

// Get supplier data from JSON
$supplier_data = isset($invoice_data['supplier']) ? $invoice_data['supplier'] : array();
if (empty($supplier_data) || empty($supplier_data['name'])) {
    setEventMessages($langs->trans("NoSupplierDataInJSON"), null, 'errors');
    header("Location: ".dol_buildpath('/upinvoice/upload.php', 1));
    exit;
}

// Initialize variables
$action = GETPOST('action', 'alpha');
$supplierId = GETPOST('supplier_id', 'int');
$searchResults = array();

// Actions handling
if ($action == 'confirm_supplier' && !empty($supplierId)) {
    // Update JSON data to include supplier ID
    $invoice_data = json_decode($upinvoicefiles->api_json, true);
    if (is_array($invoice_data) && isset($invoice_data['supplier'])) {
        $invoice_data['supplier']['id'] = $supplierId;
        $upinvoicefiles->api_json = json_encode($invoice_data);
    }
    
    // Update file record with supplier ID
    $upinvoicefiles->fk_supplier = $supplierId;
    $upinvoicefiles->import_step = 3; // Next step: invoice validation
    $result = $upinvoicefiles->update($user);
    
    if ($result > 0) {
        // Redirect to invoice validation page
        header("Location: ".dol_buildpath('/upinvoice/invoice.php', 1)."?file_id=".$upinvoicefiles->id);
        exit;
    } else {
        setEventMessages($langs->trans("ErrorUpdatingFile"), null, 'errors');
    }
} elseif ($action == 'create_supplier') {
    // Collect form data
    $newSupplier = array(
        'name' => GETPOST('name', 'alpha'),
        'name_alias' => GETPOST('name_alias', 'alpha'),
        'address' => GETPOST('address', 'alpha'),
        'zip' => GETPOST('zip', 'alpha'),
        'town' => GETPOST('town', 'alpha'),
        'country_code' => GETPOST('country_code', 'alpha'),
        'state_id' => GETPOST('state_id', 'alpha'),
        'idprof1' => GETPOST('idprof1', 'alpha'),
        'tva_intra' => GETPOST('tva_intra', 'alpha'),
        'phone' => GETPOST('phone', 'alpha'),
        'email' => GETPOST('email', 'alpha')
    );
    
    // Create supplier
    $result = $upinvoicesupplier->createSupplier($newSupplier, $user);
    if ($result > 0) {
        // Update JSON data to include supplier ID
        $invoice_data = json_decode($upinvoicefiles->api_json, true);
        if (is_array($invoice_data) && isset($invoice_data['supplier'])) {
            $invoice_data['supplier']['id'] = $result;
            $upinvoicefiles->api_json = json_encode($invoice_data);
        }
        
        // Update file record with new supplier ID
        $upinvoicefiles->fk_supplier = $result;
        $upinvoicefiles->import_step = 3; // Next step: invoice validation
        $updateResult = $upinvoicefiles->update($user);
        
        if ($updateResult > 0) {
            // Redirect to invoice validation page
            header("Location: ".dol_buildpath('/upinvoice/invoice.php', 1)."?file_id=".$upinvoicefiles->id);
            exit;
        } else {
            setEventMessages($langs->trans("ErrorUpdatingFile"), null, 'errors');
        }
    } else {
        setEventMessages($langs->trans("ErrorCreatingSupplier"), null, 'errors');
    }
}

// Automatic supplier search based on JSON data
$autoSearchDone = false;
$foundSupplier = false;

// Buscar proveedor por ID fiscal y nombre en un solo paso
if (!empty($supplier_data['idprof1']) || !empty($supplier_data['tva_intra']) || !empty($supplier_data['name'])) {
    $searchTerms = [];
    
    // Agregar identificadores fiscales si están disponibles
    if (!empty($supplier_data['idprof1'])) {
        $searchTerms['tva'] = preg_replace('/[^0-9]/', '', $supplier_data['idprof1']);
    }
    if (!empty($supplier_data['tva_intra'])) {
        $searchTerms['tva'] = preg_replace('/[^0-9]/', '', $supplier_data['tva_intra']);
    }
    
    // Agregar nombre si está disponible
    if (!empty($supplier_data['name'])) {
        $searchTerms['name'] = $supplier_data['name'];
    }
    
    // Buscar con cada término hasta encontrar un resultado
    foreach ($searchTerms as $key => $term) {
        if (!empty($term)) {
            $searchResults = $upinvoicesupplier->searchByCombinedTerm($term, $key);
            
            if (is_array($searchResults) && count($searchResults) == 1) {
                // Encontramos exactamente un proveedor
                $foundSupplier = true;
                $autoSearchDone = true;
                break;
            } elseif (is_array($searchResults) && count($searchResults) > 1) {
                // Encontramos múltiples proveedores, mostrar para selección
                $foundSupplier = true;
                $autoSearchDone = true;
                break;
            }
        }
    }
}

// Si un solo proveedor fue encontrado y no estamos manejando una acción del formulario, proceder al paso de factura
if (empty($action) && $autoSearchDone && count($searchResults) == 1 && $foundSupplier) {
    // Update JSON data to include supplier ID
    $invoice_data = json_decode($upinvoicefiles->api_json, true);
    if (is_array($invoice_data) && isset($invoice_data['supplier'])) {
        $invoice_data['supplier']['id'] = $searchResults[0]->id;
        $upinvoicefiles->api_json = json_encode($invoice_data);
    }
    
    // Update file record with supplier ID
    $upinvoicefiles->fk_supplier = $searchResults[0]->id;
    $upinvoicefiles->import_step = 3; // Next step: invoice validation
    $upinvoicefiles->update($user);
    
    // Redirect to invoice validation page
    header("Location: ".dol_buildpath('/upinvoice/invoice.php', 1)."?file_id=".$upinvoicefiles->id);
    exit;
}

// Define page title
$page_name = "SupplierValidation";
$help_url = '';
$morejs = array(
    '/upinvoice/js/upinvoiceimport.js', 
    '/upinvoice/js/supplier-search.js'
);
$morecss = array('/upinvoice/css/upinvoiceimport.css');

// Page header — hide the left menu for a cleaner validation screen
$conf->dol_hide_leftmenu = 1;
llxHeader('', $langs->trans($page_name), $help_url, '', 0, 0, $morejs, $morecss);

print load_fiche_titre($langs->trans($page_name), '', 'title_companies');

$previewUrl = dol_buildpath('/viewimage.php', 1).'?modulepart=upinvoice&file=temp/'.urlencode(basename($upinvoicefiles->file_path)).'&cache=0';
$isPdfDoc = (strpos((string) $upinvoicefiles->file_type, 'pdf') !== false);

print '<div class="upinvoiceimport-container">';
print '<div class="upinv-split">';

// LEFT 50%: document viewer (replaces the old "View document" button)
print '<div class="upinv-split-left">';
if ($isPdfDoc) {
    print '<iframe src="'.$previewUrl.'" class="upinv-doc-frame" title="'.dol_escape_htmltag($upinvoicefiles->original_filename).'"></iframe>';
} else {
    print '<div class="upinv-doc-imgwrap"><img src="'.$previewUrl.'" class="upinv-doc-img" alt="'.dol_escape_htmltag($upinvoicefiles->original_filename).'"></div>';
}
print '<div class="upinv-doc-foot opacitymedium small">'.dol_escape_htmltag($upinvoicefiles->original_filename).' &middot; '.dol_print_date($upinvoicefiles->date_creation, 'dayhour').'</div>';
print '</div>'; // Close upinv-split-left

// RIGHT 50%: detected data + search + create form
print '<div class="upinv-split-right">';
// Display supplier data from JSON
// Collect non-empty detected fields (label => value)
$detected = array();
if (!empty($supplier_data['name']))         $detected[$langs->trans("Name")]     = $supplier_data['name'];
if (!empty($supplier_data['idprof1']))      $detected[$langs->trans("ProfId1")]  = $supplier_data['idprof1'];
if (!empty($supplier_data['tva_intra']))    $detected[$langs->trans("VATIntra")] = $supplier_data['tva_intra'];
if (!empty($supplier_data['address']))      $detected[$langs->trans("Address")]  = $supplier_data['address'];
if (!empty($supplier_data['zip']))          $detected[$langs->trans("Zip")]      = $supplier_data['zip'];
if (!empty($supplier_data['town']))         $detected[$langs->trans("Town")]     = $supplier_data['town'];
if (!empty($supplier_data['country_code'])) $detected[$langs->trans("Country")]  = getCountryLabel($supplier_data['country_code']);
if (!empty($supplier_data['state']))        $detected[$langs->trans("State")]    = $supplier_data['state'];
if (!empty($supplier_data['phone']))        $detected[$langs->trans("Phone")]    = $supplier_data['phone'];
if (!empty($supplier_data['email']))        $detected[$langs->trans("Email")]    = $supplier_data['email'];

print '<div class="upinvoiceimport-detected-data">';

// Title bar with the supplier search inline on the right
print '<div class="upinv-detected-title">';
print '<span class="upinv-detected-title-text">' . $langs->trans("DetectedSupplierData") . '</span>';
print '<form id="supplier-search-form" method="GET" action="' . $_SERVER['PHP_SELF'] . '" class="upinv-header-search">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" id="file_id" name="file_id" value="' . $upinvoicefiles->id . '">';
print '<label for="search_term" style="display:none">' . $langs->trans("SearchSupplier") . '</label>';
print '<input type="text" name="search_term" id="search_term" value="' . dol_escape_htmltag(GETPOST('search_term')) . '" placeholder="' . dol_escape_htmltag($langs->trans("SearchSupplier")) . '">';
print '<button type="submit" class="button" title="' . dol_escape_htmltag($langs->trans("Search")) . '"><i class="fas fa-search"></i></button>';
print '</form>';
print '</div>'; // Close title bar

print '<div class="upinv-detected-grid">';
$splitAt = 5; // first 5 fields in column 1, the rest in column 2
$idx = 0;
print '<div class="upinv-detected-col">';
foreach ($detected as $label => $value) {
    if ($idx === $splitAt) {
        print '</div><div class="upinv-detected-col">';
    }
    print '<div class="upinv-detected-row">';
    print '<span class="upinv-dt-label">' . dol_escape_htmltag($label) . '</span>';
    print '<span class="upinv-dt-value">' . dol_escape_htmltag($value) . '</span>';
    print '</div>';
    $idx++;
}
print '</div>'; // Close last column
print '</div>'; // Close grid
print '</div>'; // Close detected data

// Container for live search results
print '<div id="search-results-container"></div>';

// If suppliers were found automatically, display them
if (!empty($searchResults) && count($searchResults) > 1) {
    print '<div class="upinvoiceimport-search-results">';
    print '<h3>' . $langs->trans("FoundSuppliers") . '</h3>';
    
    // Display suppliers in a table
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<td>' . $langs->trans("Name") . '</td>';
    print '<td>' . $langs->trans("TaxIDs") . '</td>';
    print '<td>' . $langs->trans("Address") . '</td>';
    print '<td>' . $langs->trans("Actions") . '</td>';
    print '</tr>';
    
    foreach ($searchResults as $supplier) {
        print '<tr class="oddeven">';
        print '<td>' . $supplier->name . (!empty($supplier->name_alias) ? ' (' . $supplier->name_alias . ')' : '') . '</td>';
        print '<td>';
        if (!empty($supplier->idprof1)) print $langs->trans("ProfId1") . ': ' . $supplier->idprof1 . '<br>';
        if (!empty($supplier->tva_intra)) print $langs->trans("VATIntra") . ': ' . $supplier->tva_intra;
        print '</td>';
        print '<td>';
        print $supplier->address . '<br>';
        print $supplier->zip . ' ' . $supplier->town;
        if (!empty($supplier->country_code)) print '<br>' . getCountryLabel($supplier->country_code);
        print '</td>';
        print '<td>';
        print '<button class="btn btn-success btn-sm select-supplier-btn" data-supplier-id="' . $supplier->id . '">';
        print '<i class="fas fa-check"></i> ' . $langs->trans("SelectThisSupplier") . '</button>';
        print '</td>';
        print '</tr>';
    }
    
    print '</table>';
    print '</div>'; // Close search results
}

// Create new supplier form (compact, 2 columns)
print '<div class="upinvoiceimport-create-form">';
print '<h2>' . $langs->trans("CreateNewSupplier") . '</h2>';
print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="create_supplier">';
print '<input type="hidden" name="file_id" value="' . $upinvoicefiles->id . '">';

// Pre-fill form with data from JSON (2-column compact grid)
print '<div class="upinv-create-grid">';

// Name (required, full width)
print '<div class="upinv-field full"><label class="fieldrequired">' . $langs->trans("Name") . '</label>';
print '<input type="text" name="name" value="' . dol_escape_htmltag($supplier_data['name'] ?? '') . '" required></div>';

// Name alias
print '<div class="upinv-field"><label>' . $langs->trans("NameAlias") . '</label>';
print '<input type="text" name="name_alias" value="' . dol_escape_htmltag($supplier_data['name_alias'] ?? '') . '"></div>';

// Tax ID
print '<div class="upinv-field"><label>' . $langs->trans("ProfId1") . '</label>';
print '<input type="text" name="idprof1" value="' . dol_escape_htmltag($supplier_data['idprof1'] ?? '') . '"></div>';

// VAT Intra
print '<div class="upinv-field"><label>' . $langs->trans("VATIntra") . '</label>';
print '<input type="text" name="tva_intra" value="' . dol_escape_htmltag($supplier_data['tva_intra'] ?? '') . '"></div>';

// Phone
print '<div class="upinv-field"><label>' . $langs->trans("Phone") . '</label>';
print '<input type="text" name="phone" value="' . dol_escape_htmltag($supplier_data['phone'] ?? '') . '"></div>';

// Address (full width)
print '<div class="upinv-field full"><label>' . $langs->trans("Address") . '</label>';
print '<input type="text" name="address" value="' . dol_escape_htmltag($supplier_data['address'] ?? '') . '"></div>';

// Zip
print '<div class="upinv-field"><label>' . $langs->trans("Zip") . '</label>';
print '<input type="text" name="zip" value="' . dol_escape_htmltag($supplier_data['zip'] ?? '') . '"></div>';

// Town
print '<div class="upinv-field"><label>' . $langs->trans("Town") . '</label>';
print '<input type="text" name="town" value="' . dol_escape_htmltag($supplier_data['town'] ?? '') . '"></div>';

// Country
print '<div class="upinv-field"><label>' . $langs->trans("Country") . '</label>';
$selectedCountry = $supplier_data['country_code'] ?? '';
print $form->select_country($selectedCountry, 'country_code', '', 0, 'minwidth300');
print '</div>';

// State (ids preserved for the country->state JS)
print '<div class="upinv-field" id="state_tr"><label>' . $langs->trans("State") . '</label>';
print '<span id="state_td">';
$selectedState = $supplier_data['state'] ?? '';
print $formcompany->select_state($selectedState, $supplier_data['country_code'] ?? '');
print '</span></div>';

if (!empty($supplier_data['state'])) {
    ?>
    <script type="text/javascript">
    // Función que se ejecutará cuando el DOM esté listo
    $(document).ready(function() {
        // Esperar a que el select de estados esté cargado y transformado por Select2
        setTimeout(function() {
            // Obtener el select de estados
            var stateSelect = $("#state_td select");
            if(stateSelect.length) {
                var stateToFind = "<?php echo addslashes(strtolower($supplier_data['state'])); ?>";
                var found = false;
                var selectedOptionValue = null;
                
                // Recorrer todas las opciones y buscar una que contenga el nombre del estado
                stateSelect.find("option").each(function() {
                    var optionText = $(this).text().toLowerCase();
                    
                    // Si el texto de la opción contiene el nombre del estado, guardar su valor
                    if (optionText.indexOf(stateToFind) !== -1) {
                        selectedOptionValue = $(this).val();
                        found = true;
                        return false; // Salir del bucle
                    }
                });
                
                // Si se encontró una coincidencia, usar la API de Select2 para seleccionarla
                if (found && selectedOptionValue) {
                    stateSelect.val(selectedOptionValue).trigger('change');
                }
            }
        }, 1000); // Aumentamos el tiempo a 1000ms para dar más tiempo a Select2
    });
    </script>
    <?php
}

// Email (full width)
print '<div class="upinv-field full"><label>' . $langs->trans("Email") . '</label>';
print '<input type="email" name="email" value="' . dol_escape_htmltag($supplier_data['email'] ?? '') . '"></div>';

print '</div>'; // Close upinv-create-grid

// Submit button
print '<div class="upinv-form-actions">';
print '<input type="submit" class="button upinv-btn-green" value="' . $langs->trans("CreateSupplier") . '">';
print '</div>';

print '</form>';
print '</div>'; // Close create form

print '</div>'; // Close upinv-split-right
print '</div>'; // Close upinv-split

// Close container
print '</div>';

// JavaScript variables for supplier search
?>
<script type="text/javascript">
    var upinvoiceimport_root = '<?php echo dirname($_SERVER['PHP_SELF']); ?>';
    var upinvoiceimport_token = '<?php echo newToken(); ?>';
    var upinvoiceimport_langs = {
        'Searching': '<?php echo $langs->trans("Searching"); ?>',
        'FoundSuppliers': '<?php echo $langs->trans("FoundSuppliers"); ?>',
        'Name': '<?php echo $langs->trans("Name"); ?>',
        'TaxIDs': '<?php echo $langs->trans("TaxIDs"); ?>',
        'Address': '<?php echo $langs->trans("Address"); ?>',
        'Actions': '<?php echo $langs->trans("Actions"); ?>',
        'SelectThisSupplier': '<?php echo $langs->trans("SelectThisSupplier"); ?>',
        'NoSuppliersFound': '<?php echo $langs->trans("NoSuppliersFound"); ?>',
        'ErrorProcessingResponse': '<?php echo $langs->trans("ErrorProcessingResponse"); ?>',
        'SearchFailed': '<?php echo $langs->trans("SearchFailed"); ?>',
        'ErrorConfirmingSupplier': '<?php echo $langs->trans("ErrorConfirmingSupplier"); ?>'
    };
</script>

<script type="text/javascript">
$(document).ready(function() {
    // Country-State dynamic selection
    $("#country_code").change(function() {
        var country_id = $(this).val();
        
        if (country_id) {
            $.ajax({
                url: '<?php echo DOL_URL_ROOT; ?>/core/ajax/fetchStateByCountry.php',
                data: {
                    country_id: country_id,
                    token: '<?php echo newToken(); ?>'
                },
                type: 'POST',
                dataType: 'html',
                success: function(html) {
                    $("#state_td").html(html);
                }
            });
        } else {
            $("#state_td").html('');
        }
    });
    
    // Handler for supplier selection buttons (for the automatically found suppliers)
    $('.select-supplier-btn').on('click', function() {
        var supplierId = $(this).data('supplier-id');
        confirmSupplier(supplierId);
    });
    
    // Function to confirm supplier selection
    function confirmSupplier(supplierId) {
        $.ajax({
            url: upinvoiceimport_root + '/ajax/confirm_supplier.php',
            type: 'POST',
            data: {
                token: upinvoiceimport_token,
                file_id: $('#file_id').val(),
                supplier_id: supplierId
            },
            success: function(response) {
                try {
                    var result = JSON.parse(response);
                    
                    if (result.status === 'success') {
                        // Redirect to invoice validation page
                        window.location.href = upinvoiceimport_root + '/invoice.php?file_id=' + $('#file_id').val();
                    } else {
                        alert(result.message || upinvoiceimport_langs.ErrorConfirmingSupplier);
                    }
                } catch (e) {
                    alert(upinvoiceimport_langs.ErrorProcessingResponse);
                    console.error('Error parsing response', e);
                }
            },
            error: function(xhr, status, error) {
                alert(upinvoiceimport_langs.ErrorConfirmingSupplier + ': ' + error);
                console.error('Confirmation failed', error);
            }
        });
    }
});
</script>
<?php
// Footer
llxFooter();
$db->close();

/**
 * Get country label from code
 * 
 * @param string $countryCode Country code
 * @return string Country label
 */
function getCountryLabel($countryCode)
{
    global $db, $langs;
    
    if (empty($countryCode)) {
        return '';
    }
    
    $sql = "SELECT label FROM ".MAIN_DB_PREFIX."c_country";
    $sql .= " WHERE code = '".$db->escape($countryCode)."'";
    
    $resql = $db->query($sql);
    if ($resql && $db->num_rows($resql) > 0) {
        $obj = $db->fetch_object($resql);
        return $obj->label;
    }
    
    return $countryCode;
}