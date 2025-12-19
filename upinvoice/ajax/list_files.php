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

// Load translation files required by the page
$langs->loadLangs(array('upinvoice@upinvoice'));

require_once '../class/upinvoicefiles.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';

// Control access
if (!$user->rights->facture->lire) {
    $result = array('status' => 'error', 'message' => $langs->trans('NotAllowed'));
    echo json_encode($result);
    exit;
}

// Security check
if (!isset($_GET['token'])) {
    $result = array('status' => 'error', 'message' => $langs->trans('InvalidRequest'));
    echo json_encode($result);
    exit;
}

$token = GETPOST('token', 'alpha');

// Get the file type requested (pending or finished)
$fileType = GETPOST('file_type', 'alpha');
if (!in_array($fileType, array('pending', 'finished'))) {
    $fileType = 'pending'; // Default to pending
}

// Initialize objects
$upinvoicefiles = new UpInvoiceFiles($db);

// Build SQL query based on the file type
$sql = "SELECT f.rowid FROM " . MAIN_DB_PREFIX . "upinvoice_files as f";

// Filter by file type
if ($fileType === 'pending') {
    // Pending files: No fk_invoice yet
    $sql .= " WHERE f.entity = " . $conf->entity;
    $sql .= " AND (f.fk_invoice IS NULL OR f.fk_invoice = 0)";
    $sql .= " ORDER BY f.date_creation DESC";
} else {
    // Finished files: Have fk_invoice - Join with invoice and supplier tables for additional data
    $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "facture_fourn as ff ON f.fk_invoice = ff.rowid";
    $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "societe as s ON f.fk_supplier = s.rowid";
    $sql .= " WHERE f.entity = " . $conf->entity;
    $sql .= " AND f.fk_invoice IS NOT NULL AND f.fk_invoice > 0";
    $sql .= " ORDER BY f.date_modification DESC"; // Order by modification date (when invoice was created)
}

$resql = $db->query($sql);
if (!$resql) {
    $result = array('status' => 'error', 'message' => $langs->trans('DatabaseError'));
    echo json_encode($result);
    exit;
}

$files = array();
while ($obj = $db->fetch_object($resql)) {
    $file = new UpInvoiceFiles($db);
    $file->fetch($obj->rowid);
    $files[] = $file;
}

// Generate HTML
$html = '';

if (count($files) == 0) {
    if ($fileType === 'pending') {
        $html .= '<div class="opacitymedium">' . $langs->trans('NoPendingFiles') . '</div>';
    } else {
        $html .= '<div class="opacitymedium">' . $langs->trans('NoFinishedFiles') . '</div>';
    }
} else {
    if ($fileType === 'pending') {
        // Files grid container for pending files (keep the original design)
        $html .= '<div class="files-grid">';
        
        // Files list
        foreach ($files as $file) {
            // Check if file is stuck in processing state (processing == 1 but last modification was more than 100 seconds ago)
            $isStuckProcessing = false;
            if ($file->processing == 1) {
                $timeSinceModification = time() - $file->date_modification;
                if ($timeSinceModification > 100) {
                    $isStuckProcessing = true;
                }
            }
            
            $html .= '<div class="file-card">';
            
            // File thumbnail and info grouped together on left
            $html .= '<div class="file-card-top">';
            
            // File thumbnail
            $html .= '<div class="file-thumbnail" data-file-id="' . $file->id . '" data-file-name="' . dol_escape_htmltag($file->original_filename) . '" 
                     data-file-type="' . dol_escape_htmltag($file->file_type) . '" data-file-path="' . dol_buildpath('/viewimage.php', 1).'?modulepart=upinvoice&file=temp/'.urlencode(basename($file->file_path)).'&cache=0' . '">';
            
            if (strpos($file->file_type, 'pdf') !== false) {
                $html .= '<i class="fas fa-file-pdf fa-2x"></i>';
            } elseif (strpos($file->file_type, 'image') !== false) {
                $html .= '<img src="' . dol_buildpath('/viewimage.php', 1).'?modulepart=upinvoice&file=temp/'.urlencode(basename($file->file_path)).'&cache=0' . '" alt="' . dol_escape_htmltag($file->original_filename) . '">';
            } else {
                $html .= '<i class="fas fa-file fa-2x"></i>';
            }
            
            $html .= '</div>';
            
            // File info
            $html .= '<div class="file-info">';
            $html .= '<div class="file-name">' . dol_escape_htmltag($file->original_filename) . '</div>';
            $html .= '<div class="file-meta">';
            $html .= '<span><i class="fas fa-calendar-alt"></i> ' . dol_print_date($file->date_creation, 'dayhour') . '</span>';
            $html .= ' <span><i class="fas fa-weight"></i> ' . dol_print_size($file->file_size) . '</span>';
            $html .= '</div>';
            $html .= '</div>';
            
            // Status column on right side
            $html .= '<div class="file-status" id="file-status-' . $file->id . '">';
            if ($file->processing == 1 && !$isStuckProcessing) {
                $html .= '<i class="fas fa-spinner fa-spin"></i> ' . $langs->trans('Processing');
            } else {
                // If stuck processing, show as error
                $statusToDisplay = $isStuckProcessing ? -1 : $file->status;
                
                switch ($statusToDisplay) {
                    case 0:
                        $html .= '<span class="badge badge-pending">' . $langs->trans('Pending') . '</span>';
                        break;
                    case 1:
                        $html .= '<span class="badge badge-processed">' . $langs->trans('Processed') . '</span>';
                        break;
                    case -1:
                        $html .= '<span class="badge badge-error">' . $langs->trans('Error') . '</span>';
                        break;
                    default:
                        $html .= '<span class="badge badge-pending">' . $langs->trans('Unknown') . '</span>';
                }
            }
            $html .= '</div>';
            
            $html .= '</div>'; // End file-card-top
            
            // Separator
            $html .= '<div class="file-card-divider"></div>';
            
            // Progress bar for processing files
            if ($file->processing == 1 && !$isStuckProcessing) {
                $html .= '<div id="file-progress-' . $file->id . '" class="file-progress">';
                $html .= '<div class="progress">';
                $html .= '<div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 30%;" aria-valuenow="30" aria-valuemin="0" aria-valuemax="100">30%</div>';
                $html .= '</div>';
                $html .= '</div>';
            }
            
            // Error message if any
            if (!empty($file->import_error)) {
                $html .= '<div class="file-error">';
                $html .= '<span class="text-danger"><i class="fas fa-exclamation-circle"></i> ' . dol_escape_htmltag($file->import_error) . '</span>';
                $html .= '</div>';
            }
            
            // Actions at bottom
            $html .= '<div class="file-actions">';
            if ($file->processing == 0 || $isStuckProcessing) {
                // For pending tab (files with invoice)
                if ($fileType === 'pending') {
                    // Si tenemos un fk_supplier pero no un fk_invoice, mostrar "Validar factura"
                    if (!empty($file->fk_supplier) && empty($file->fk_invoice)) {
                        $html .= '<a href="' . dol_buildpath('/upinvoice/invoice.php', 1) . '?file_id=' . $file->id . '" class="btn btn-success btn-sm">';
                        $html .= '<i class="fas fa-check"></i> ' . $langs->trans('ValidateInvoice');
                        $html .= '</a>';
                    }
                    // Otros casos según el estado
                    elseif ($file->status == 0 || $isStuckProcessing) {
                        // Pending or stuck: show process/retry button
                        if ($isStuckProcessing) {
                            $html .= '<button class="btn btn-warning btn-sm process-file-btn" data-file-id="' . $file->id . '">';
                            $html .= '<i class="fas fa-redo"></i> ' . $langs->trans('Retry');
                            $html .= '</button>';
                        } else {
                            $html .= '<button class="btn btn-primary btn-sm process-file-btn" data-file-id="' . $file->id . '">';
                            $html .= '<i class="fas fa-cogs"></i> ' . $langs->trans('Process');
                            $html .= '</button>';
                        }
                    } elseif ($file->status == 1 && $file->import_step > 1 && empty($file->fk_supplier)) {
                        // Si está procesado pero no tiene proveedor, mostrar "Siguiente paso" para ir a validar proveedor
                        $html .= '<a href="' . dol_buildpath('/upinvoice/supplier.php', 1) . '?file_id=' . $file->id . '" class="btn btn-success btn-sm">';
                        $html .= '<i class="fas fa-arrow-right"></i> ' . $langs->trans('NextStep');
                        $html .= '</a>';
                    } elseif ($file->status == -1) {
                        // Error: show retry button
                        $html .= '<button class="btn btn-warning btn-sm process-file-btn" data-file-id="' . $file->id . '">';
                        $html .= '<i class="fas fa-redo"></i> ' . $langs->trans('Retry');
                        $html .= '</button>';
                    }
                    
                    // Delete button - always present in pending tab
                    $html .= ' <button class="btn btn-danger btn-sm delete-file-btn" data-file-id="' . $file->id . '">';
                    $html .= '<i class="fas fa-trash"></i>';
                    $html .= '</button>';
                    
                    // Pause button solo para archivos pendientes (no para stuck)
                    if ($file->status == 0 && !$isStuckProcessing) {
                        $html .= ' <button class="btn btn-outline-warning btn-sm pause-file-btn" data-file-id="' . $file->id . '" title="' . $langs->trans('PauseProcessing') . '">';
                        $html .= '<i class="fas fa-pause"></i>';
                        $html .= '</button>';
                    }
                }
            } else {
                // If actively processing (not stuck), show spinner
                $html .= '<button class="btn btn-secondary btn-sm" disabled>';
                $html .= '<i class="fas fa-spinner fa-spin"></i>';
                $html .= '</button>';
            }
            $html .= '</div>'; // End file-actions
            
            $html .= '</div>'; // End file-card
        }
        
        $html .= '</div>'; // End files-grid
    } else {
        // Finished files - Use standard Dolibarr table format
        // No need for a container as the table already exists in upload.php
        
        foreach ($files as $file) {
            $html .= '<tr class="oddeven">';
            
            // Column 1: File Name with thumbnail icon
            $html .= '<td>';
            $html .= '<div class="file-info-compact">';
            // File icon/thumbnail - clickable for preview
            if (strpos($file->file_type, 'pdf') !== false) {
                $html .= '<i class="fas fa-file-pdf" style="margin-right: 5px;"></i>';
            } elseif (strpos($file->file_type, 'image') !== false) {
                $html .= '<i class="fas fa-file-image" style="margin-right: 5px;"></i>';
            } else {
                $html .= '<i class="fas fa-file" style="margin-right: 5px;"></i>';
            }
            $html .= '<a href="' . DOL_URL_ROOT . '/fourn/facture/card.php?facid=' . $file->fk_invoice . '" class="file-thumbnail-name" data-file-id="' . $file->id . '" data-file-name="' . dol_escape_htmltag($file->original_filename) . '" ';
            $html .= 'data-file-type="' . dol_escape_htmltag($file->file_type) . '" data-file-path="' . dol_buildpath('/viewimage.php', 1) . '?modulepart=upinvoice&file=temp/' . urlencode(basename($file->file_path)) . '&cache=0">';
            $html .= dol_escape_htmltag($file->original_filename);
            $html .= '</a>';
            $html .= '</div>';
            $html .= '</td>';
            
            // Column 2: File Size
            $html .= '<td class="right">' . dol_print_size($file->file_size) . '</td>';
            
            // Column 3: Upload Date
            $html .= '<td class="center">' . dol_print_date($file->date_creation, 'dayhour') . '</td>';
            
            // Column 4: Completion Date (when invoice was created)
            $html .= '<td class="center">' . dol_print_date($file->date_modification, 'dayhour') . '</td>';
            
            // Column 5: Invoice Date
            // Need to fetch invoice data
            $invoiceData = null;
            $invoiceDate = '';
            $totalAmount = 0;
            $apiTotalAmount = 0;
            $hasAmountDifference = false;
            
            if (!empty($file->fk_invoice)) {
                $invoice = new FactureFournisseur($db);
                if ($invoice->fetch($file->fk_invoice) > 0) {
                    $invoiceDate = $invoice->date;
                    $totalAmount = $invoice->total_ttc;
                    
                    // Get the API extracted amount from JSON
                    if (!empty($file->api_json)) {
                        $jsonData = json_decode($file->api_json, true);
                        if ($jsonData && isset($jsonData['total_ttc'])) {
                            $apiTotalAmount = price2num($jsonData['total_ttc']);
                            
                            // Check if there's a significant difference (more than 0.01)
                            if (abs($totalAmount - $apiTotalAmount) > 0.005) {
                                $hasAmountDifference = true;
                            }
                        }
                    }
                }
            }
            
            $html .= '<td class="center">' . ($invoiceDate ? dol_print_date($invoiceDate, 'day') : '') . '</td>';
            
            // Column 6: Total Amount with warning icon if there's a difference
            $html .= '<td class="right" style="display:table-cell;justify-content:right;padding:6px 10px 6px 12px;">';
            if ($hasAmountDifference) {
                $html .= ' <span style="margin-right:2px;" class="text-warning" title="' . $langs->trans('TotalAmountMismatch') . ': ' . 
                    $langs->trans('Detected') . ': ' . price($apiTotalAmount) . ', ' . 
                    $langs->trans('Entered') . ': ' . price($totalAmount) . '">';
                $html .= '<i class="fas fa-exclamation-triangle" style="padding-right:3px;"></i>';
                $html .= '</span>';
            }
            $html .= price($totalAmount);
            $html .= '</td>';
            
            // Column 7: Supplier
            $html .= '<td class="supplier-on-table">';
            if (!empty($file->fk_supplier)) {
                $supplier = new Fournisseur($db);
                $html .= '<a href="' . DOL_URL_ROOT . '/fourn/card.php?socid=' . $file->fk_supplier . '" target="_blank">';
                if ($supplier->fetch($file->fk_supplier) > 0) {
                    $html .= $supplier->name;
                } else {
                    $html .= $langs->trans('SupplierNotFound');
                }
                $html .= '</a>';
            } else {
                $html .= $langs->trans('NoSupplierSelected');
            }
            $html .= '</td>';
            
            // Column 8: Actions
            $html .= '<td class="center nowrap">';
            // View invoice button
            if (!empty($file->fk_invoice)) {
                $html .= '<a href="' . DOL_URL_ROOT . '/fourn/facture/card.php?facid=' . $file->fk_invoice . '" class="btn btn-info btn-sm" target="_blank" title="' . $langs->trans('ViewInvoice') . '">';
                $html .= '<i class="fas fa-link" style="color:#fff"></i>';
                $html .= '</a> ';
            }
            
            // Delete button 
            $html .= '<button class="btn btn-danger btn-sm delete-file-btn" data-file-id="' . $file->id . '" title="' . $langs->trans('DeleteOnlyFromList') . '">';
            $html .= '<i class="fas fa-trash"></i>';
            $html .= '</button>';
            
            $html .= '</td>';
            
            $html .= '</tr>';
        }
    }
}

// Return result
$result = array(
    'status' => 'success',
    'html' => $html,
    'count' => count($files)
);

echo json_encode($result);
exit;