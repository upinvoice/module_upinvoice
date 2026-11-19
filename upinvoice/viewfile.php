<?php
/* Copyright (C) 2023-2025
 * Licensed under the GNU General Public License version 3
 */

/**
 * Serves an uploaded UpInvoice document (PDF or image) for the preview.
 *
 * viewimage.php?modulepart=upinvoice only lets admins in: the module declares no permission,
 * so Dolibarr finds no upinvoice->read right for other users. This page applies the same rules
 * as the module pages instead (logged-in internal user, same entity, right on the document type).
 */

if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
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
dol_include_once('/upinvoice/class/upinvoicefiles.class.php');
dol_include_once('/upinvoice/lib/upinvoice.lib.php');

/**
 * Stop with a plain HTTP error (httponly_accessforbidden() does not exist on Dolibarr 16)
 *
 * @param string $message Message
 * @param int    $code    HTTP code
 * @return never
 */
function upinvoice_viewfile_deny($message, $code)
{
	http_response_code($code);
	header('Content-Type: text/plain; charset=UTF-8');
	print $message;
	exit;
}

$id = GETPOSTINT('id');

// External users never see the import queue
if (empty($user->id) || !empty($user->socid) || $id <= 0) {
	upinvoice_viewfile_deny('Not allowed', 403);
}

$file = new UpInvoiceFiles($db);
if ($file->fetch($id) <= 0 || (int) $file->entity !== (int) $conf->entity) {
	upinvoice_viewfile_deny('File not found', 404);
}

if (!upinvoice_user_can_view_file($user, $file)) {
	upinvoice_viewfile_deny('Not allowed', 403);
}

// Only files of the module temp dir (the record stores an absolute path)
$baseDir = realpath(DOL_DATA_ROOT.'/upinvoice/temp');
$fullPath = $baseDir ? realpath($baseDir.'/'.basename((string) $file->file_path)) : false;
if (!$fullPath || strpos($fullPath, $baseDir.DIRECTORY_SEPARATOR) !== 0 || !is_file($fullPath)) {
	upinvoice_viewfile_deny('File not found', 404);
}

// Inline preview of PDFs and images only
$mime = dol_mimetype($fullPath);
if ($mime !== 'application/pdf' && strpos($mime, 'image/') !== 0) {
	upinvoice_viewfile_deny('This file type is not qualified for preview', 403);
}

if (ob_get_level()) {
	ob_end_clean();
}
header('Content-Type: '.$mime);
header('Content-Length: '.dol_filesize($fullPath));
header('Content-Disposition: inline; filename="'.dol_sanitizeFileName((string) $file->original_filename).'"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-cache, must-revalidate');
readfile($fullPath);
