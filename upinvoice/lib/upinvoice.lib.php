<?php
/* Copyright (C) 2023-2025
 * Licensed under the GNU General Public License version 3
 */

/**
 * Document types of UpInvoice (supplier invoices, supplier orders, customer orders):
 * which ones a user can use, their labels and their pages.
 */

dol_include_once('/upinvoice/class/upinvoicefiles.class.php');

/**
 * Setup constant that enables each document type (supplier invoices are always on)
 *
 * @return array doc_type => constant name
 */
function upinvoice_doc_type_constants()
{
	return array(
		UpInvoiceFiles::DOC_SUPPLIER_ORDER => 'UPINVOICE_SUPPLIER_ORDERS_ENABLED',
		UpInvoiceFiles::DOC_CUSTOMER_ORDER => 'UPINVOICE_CUSTOMER_ORDERS_ENABLED',
	);
}

/**
 * Is the Dolibarr module needed by a document type enabled?
 * isModEnabled('supplier_order') covers the old Suppliers module and the new split one;
 * customer orders are 'commande' up to Dolibarr 23 and 'order' after (both names are mapped).
 *
 * @param string $docType Document type
 * @return bool
 */
function upinvoice_doc_type_module_enabled($docType)
{
	switch ($docType) {
		case UpInvoiceFiles::DOC_SUPPLIER_INVOICE:
			return isModEnabled('supplier_invoice');
		case UpInvoiceFiles::DOC_SUPPLIER_ORDER:
			return isModEnabled('supplier_order');
		case UpInvoiceFiles::DOC_CUSTOMER_ORDER:
			return isModEnabled('commande') || isModEnabled('order');
	}
	return false;
}

/**
 * Can the user create the Dolibarr object of a document type?
 *
 * @param User   $user    User
 * @param string $docType Document type
 * @return bool
 */
function upinvoice_user_can_create($user, $docType)
{
	switch ($docType) {
		case UpInvoiceFiles::DOC_SUPPLIER_INVOICE:
			// Same check the module always used for its pages
			return !empty($user->rights->facture->lire);
		case UpInvoiceFiles::DOC_SUPPLIER_ORDER:
			return $user->hasRight('fournisseur', 'commande', 'creer') || $user->hasRight('supplier_order', 'creer');
		case UpInvoiceFiles::DOC_CUSTOMER_ORDER:
			return $user->hasRight('commande', 'creer');
	}
	return false;
}

/**
 * Can the user see the uploaded document of an UpInvoice file (preview in viewfile.php)?
 * Same rules as the module pages: the import queue (upload.php, invoice.php) needs the invoice read
 * right, the order review page needs the right to create that order.
 *
 * @param User           $user User
 * @param UpInvoiceFiles $file File record
 * @return bool
 */
function upinvoice_user_can_view_file($user, $file)
{
	if (!empty($user->rights->facture->lire)) {
		return true;
	}
	return $file->isOrder() && upinvoice_user_can_create($user, $file->doc_type);
}

/**
 * URL of the preview of an UpInvoice file (served by viewfile.php with the module rights,
 * since viewimage.php?modulepart=upinvoice only lets admins in)
 *
 * @param UpInvoiceFiles|object $file File record (needs id)
 * @return string
 */
function upinvoice_file_preview_url($file)
{
	return dol_buildpath('/upinvoice/viewfile.php', 1).'?id='.((int) $file->id);
}

/**
 * Remember the document types announced by the UpInvoice API (/api/check)
 *
 * @param string[] $docTypes Document types
 * @return void
 */
function upinvoice_set_api_doc_types($docTypes)
{
	global $conf;
	$_SESSION['upinvoice_api_doc_types'][(int) $conf->entity] = array_values(array_intersect(UpInvoiceFiles::DOC_TYPES, (array) $docTypes));
}

/**
 * Document types announced by the API, or null if not known yet in this session
 *
 * @return string[]|null
 */
function upinvoice_get_api_doc_types()
{
	global $conf;
	return isset($_SESSION['upinvoice_api_doc_types'][(int) $conf->entity]) ? $_SESSION['upinvoice_api_doc_types'][(int) $conf->entity] : null;
}

/**
 * Is a document type enabled in the module setup and its Dolibarr module active?
 * (independent of the user and of the API)
 *
 * @param string $docType Document type
 * @return bool
 */
function upinvoice_doc_type_enabled($docType)
{
	if ($docType === UpInvoiceFiles::DOC_SUPPLIER_INVOICE) {
		return true;
	}
	$constants = upinvoice_doc_type_constants();
	return isset($constants[$docType]) && getDolGlobalInt($constants[$docType]) && upinvoice_doc_type_module_enabled($docType);
}

/**
 * Document types the user can work with now: enabled in setup, Dolibarr module on, user
 * allowed, and not refused by the API (unknown API capabilities do not hide anything).
 * Supplier invoices are always first and always present.
 *
 * @param User $user User
 * @return string[]
 */
function upinvoice_available_doc_types($user)
{
	$apiTypes = upinvoice_get_api_doc_types();
	$types = array(UpInvoiceFiles::DOC_SUPPLIER_INVOICE);
	foreach (array(UpInvoiceFiles::DOC_SUPPLIER_ORDER, UpInvoiceFiles::DOC_CUSTOMER_ORDER) as $docType) {
		if (upinvoice_doc_type_enabled($docType) && upinvoice_user_can_create($user, $docType)
			&& ($apiTypes === null || in_array($docType, $apiTypes, true))) {
			$types[] = $docType;
		}
	}
	return $types;
}

/**
 * Translation key of the plural label of a document type ("Supplier invoices"...)
 *
 * @param string $docType Document type
 * @return string
 */
function upinvoice_doc_type_label_key($docType)
{
	$keys = array(
		UpInvoiceFiles::DOC_SUPPLIER_INVOICE => 'UpInvoiceDocSupplierInvoices',
		UpInvoiceFiles::DOC_SUPPLIER_ORDER => 'UpInvoiceDocSupplierOrders',
		UpInvoiceFiles::DOC_CUSTOMER_ORDER => 'UpInvoiceDocCustomerOrders',
	);
	return isset($keys[$docType]) ? $keys[$docType] : $docType;
}

/**
 * Page of the last step (review and create the Dolibarr object) of a document type
 *
 * @param string $docType Document type
 * @return string Relative URL inside the module
 */
function upinvoice_doc_type_review_page($docType)
{
	return ($docType === UpInvoiceFiles::DOC_SUPPLIER_INVOICE) ? '/upinvoice/invoice.php' : '/upinvoice/order.php';
}

/**
 * Card URL of the Dolibarr object created from a file
 *
 * @param string $docType  Document type
 * @param int    $objectId Object id
 * @return string
 */
function upinvoice_object_card_url($docType, $objectId)
{
	switch ($docType) {
		case UpInvoiceFiles::DOC_SUPPLIER_ORDER:
			return DOL_URL_ROOT.'/fourn/commande/card.php?id='.((int) $objectId);
		case UpInvoiceFiles::DOC_CUSTOMER_ORDER:
			return DOL_URL_ROOT.'/commande/card.php?id='.((int) $objectId);
	}
	return DOL_URL_ROOT.'/fourn/facture/card.php?facid='.((int) $objectId);
}

/**
 * For AJAX endpoints: stop with a JSON error when the module files were updated without
 * disabling/enabling the module (new columns of llx_upinvoice_files missing)
 *
 * @param DoliDB $db Database handler
 * @return void
 */
function upinvoice_ajax_schema_guard($db)
{
	global $langs;
	$missing = UpInvoiceFiles::getMissingColumns($db);
	if (!empty($missing)) {
		echo json_encode(array('status' => 'error', 'code' => 'schema_outdated', 'message' => $langs->transnoentitiesnoconv('UpInvoiceSchemaOutdated', implode(', ', $missing))));
		exit;
	}
}

/**
 * Document type sent by the browser, only if the user can use it now (else supplier invoice)
 *
 * @param User $user User
 * @return string
 */
function upinvoice_requested_doc_type($user)
{
	$docType = GETPOST('doc_type', 'aZ09');
	return in_array($docType, upinvoice_available_doc_types($user), true) ? $docType : UpInvoiceFiles::DOC_SUPPLIER_INVOICE;
}

/**
 * Role of the third party of a document type: 'customer' for customer orders, else 'supplier'
 *
 * @param string $docType Document type
 * @return string
 */
function upinvoice_doc_type_role($docType)
{
	return $docType === UpInvoiceFiles::DOC_CUSTOMER_ORDER ? 'customer' : 'supplier';
}

/**
 * Customer orders: the third party pages say "customer" instead of "supplier". The texts of
 * those pages are replaced by their customer variant (UpInvoiceCustomer_<key>), so the
 * supplier wording of invoices is left untouched.
 *
 * @param Translate $langs Translations (upinvoice@upinvoice loaded)
 * @return void
 */
function upinvoice_use_customer_wording($langs)
{
	foreach (array('SupplierValidation', 'DetectedSupplierData', 'SupplierInformation', 'FoundSuppliers', 'SelectThisSupplier', 'CreateNewSupplier', 'CreateSupplier', 'NoSupplierDataInJSON', 'ErrorCreatingSupplier', 'SupplierConfirmed', 'NoSuppliersFound', 'SearchSupplier', 'ErrorConfirmingSupplier', 'NoSupplierSelected', 'SupplierNotFound') as $key) {
		$customerKey = 'UpInvoiceCustomer_'.$key;
		if ($langs->trans($customerKey) !== $customerKey) {
			$langs->tab_translate[$key] = $langs->tab_translate[$customerKey];
		}
	}
}
