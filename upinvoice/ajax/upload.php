<?php
/* Copyright (C) 2023
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

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once '../class/upinvoicefiles.class.php';

// Control access
if (!$user->rights->facture->lire) {
    $result = array('status' => 'error', 'message' => $langs->trans('NotAllowed'));
    echo json_encode($result);
    exit;
}

// Security check
if (empty($_POST['token']) || empty($_FILES['userfile'])) {
    $result = array('status' => 'error', 'message' => $langs->trans('InvalidRequest'));
    echo json_encode($result);
    exit;
}


$token = GETPOST('token', 'alpha');

// Define directory
$upload_dir = DOL_DATA_ROOT . '/upinvoice/temp';

// Create directory if not exists
if (!dol_is_dir($upload_dir)) {
    dol_mkdir($upload_dir);
}

// Initialize return array
$result = array(
    'status' => 'success',
    'message' => $langs->trans('FilesUploaded'),
    'files' => array()
);

// Process uploaded files
if (isset($_FILES['userfile']) && is_array($_FILES['userfile']['name'])) {
    $nbFiles = count($_FILES['userfile']['name']);
    
    // Loop through each file
    for ($i = 0; $i < $nbFiles; $i++) {
        $originalFileName = $_FILES['userfile']['name'][$i];
        $fileSize = $_FILES['userfile']['size'][$i];
        $fileType = $_FILES['userfile']['type'][$i];
        $tmpName = $_FILES['userfile']['tmp_name'][$i];
        $error = $_FILES['userfile']['error'][$i];
        
        // Check upload error
        if ($error != UPLOAD_ERR_OK) {
            $errorMessage = getUploadErrorMessage($error);
            $result['files'][] = array(
                'name' => $originalFileName,
                'status' => 'error',
                'message' => $errorMessage
            );
            continue;
        }
        
        // Validate file type (only PDF and images allowed)
        $allowedTypes = array('application/pdf', 'image/jpeg', 'image/png', 'image/gif');
        if (!in_array($fileType, $allowedTypes)) {
            $result['files'][] = array(
                'name' => $originalFileName,
                'status' => 'error',
                'message' => $langs->trans('InvalidFileType')
            );
            continue;
        }
        
        // Sanitize filename properly for storage
        // We'll keep the original filename in the database but sanitize it for actual storage
        $sanitizedFileName = dol_sanitizeFileName($originalFileName);
        
        // Generate a unique filename for storage (irrespective of original characters)
        $uniqueFilename = pathinfo($sanitizedFileName, PATHINFO_FILENAME); // Get filename without extension
        $uniqueFilename = trim($uniqueFilename); // Remove spaces at beginning and end
        
        if (empty($uniqueFilename)) {
            $uniqueFilename = 'file'; // Fallback if filename is empty after sanitization
        }
        
        $uniqueFilename .= '_' . dol_print_date(dol_now(), 'dayhourlog') . '_' . substr(md5(uniqid()), 0, 8);
        
        // Add appropriate extension based on file type
        if (strpos($fileType, 'pdf') !== false) {
            $uniqueFilename .= '.pdf';
        } elseif (strpos($fileType, 'jpeg') !== false || strpos($fileType, 'jpg') !== false) {
            $uniqueFilename .= '.jpg';
        } elseif (strpos($fileType, 'png') !== false) {
            $uniqueFilename .= '.png';
        } elseif (strpos($fileType, 'gif') !== false) {
            $uniqueFilename .= '.gif';
        }
        
        // Destination path
        $dest_file = $upload_dir . '/' . $uniqueFilename;
        
        // Move file to destination
        $res = dol_move_uploaded_file($tmpName, $dest_file, 0, 0, 0, 0);
        
        if ($res) {
            // File successfully uploaded
            // Create database record
            $upinvoicefiles = new UpInvoiceFiles($db);
            $upinvoicefiles->file_path = $dest_file;
            $upinvoicefiles->original_filename = $originalFileName; // Keep original filename with special chars
            $upinvoicefiles->file_size = $fileSize;
            $upinvoicefiles->file_type = $fileType;
            $upinvoicefiles->import_step = 1;
            $upinvoicefiles->status = 0; // Pending
            $upinvoicefiles->processing = 0; // Not processing
            
            $fileId = $upinvoicefiles->create($user);
            
            if ($fileId > 0) {
                $result['files'][] = array(
                    'name' => $originalFileName,
                    'status' => 'success',
                    'file_id' => $fileId,
                    'message' => $langs->trans('FileUploaded')
                );
            } else {
                // Database error, remove the uploaded file
                @unlink($dest_file);
                $result['files'][] = array(
                    'name' => $originalFileName,
                    'status' => 'error',
                    'message' => $langs->trans('DatabaseError')
                );
            }
        } else {
            // Upload error
            $result['files'][] = array(
                'name' => $originalFileName,
                'status' => 'error',
                'message' => $langs->trans('UploadError')
            );
        }
    }
}

// Check if there were any successful uploads
$anySuccess = false;
foreach ($result['files'] as $file) {
    if ($file['status'] === 'success') {
        $anySuccess = true;
        break;
    }
}

if (!$anySuccess) {
    $result['status'] = 'error';
    $result['message'] = $langs->trans('NoFilesUploaded');
}

// Return JSON response
echo json_encode($result);
exit;

/**
 * Get upload error message
 * 
 * @param int $errorCode Error code
 * @return string Error message
 */
function getUploadErrorMessage($errorCode)
{
    global $langs;
    
    switch ($errorCode) {
        case UPLOAD_ERR_INI_SIZE:
            return $langs->trans('FileTooBig');
        case UPLOAD_ERR_FORM_SIZE:
            return $langs->trans('FileTooBig');
        case UPLOAD_ERR_PARTIAL:
            return $langs->trans('PartialUpload');
        case UPLOAD_ERR_NO_FILE:
            return $langs->trans('NoFileUploaded');
        case UPLOAD_ERR_NO_TMP_DIR:
            return $langs->trans('TempDirError');
        case UPLOAD_ERR_CANT_WRITE:
            return $langs->trans('WriteError');
        case UPLOAD_ERR_EXTENSION:
            return $langs->trans('ExtensionError');
        default:
            return $langs->trans('UnknownError');
    }
}