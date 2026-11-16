<?php
/* Copyright (C) 2023-2025
 * Licensed under the GNU General Public License version 3
 */

require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.commande.class.php';
require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
dol_include_once('/upinvoice/class/upinvoicefiles.class.php');
dol_include_once('/upinvoice/class/upinvoiceinvoice.class.php');

/**
 * Creates the Dolibarr order of an UpInvoice file: supplier order (CommandeFournisseur)
 * or customer order (Commande), as a draft or validated, with the PDF attached.
 * Receiving or shipping the goods and invoicing the order is left to Dolibarr.
 */
class UpInvoiceOrder
{
	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var string[] */
	public $errors = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * New order object of a document type
	 *
	 * @param string $docType supplier_order or customer_order
	 * @return CommandeFournisseur|Commande
	 */
	private function newOrder($docType)
	{
		return $docType === UpInvoiceFiles::DOC_CUSTOMER_ORDER ? new Commande($this->db) : new CommandeFournisseur($this->db);
	}

	/**
	 * Can the order be validated when created? Validating a customer order moves the stock
	 * when STOCK_CALCULATE_ON_VALIDATE_ORDER is on, and that needs a warehouse the form does
	 * not ask for: then the user validates from the order card.
	 *
	 * @param string $docType Document type
	 * @param User   $user    User
	 * @return bool
	 */
	public static function canValidateOnCreate($docType, $user)
	{
		if ($docType === UpInvoiceFiles::DOC_CUSTOMER_ORDER) {
			if (isModEnabled('stock') && getDolGlobalString('STOCK_CALCULATE_ON_VALIDATE_ORDER')) {
				return false;
			}
			if (getDolGlobalString('MAIN_USE_ADVANCED_PERMS')) {
				return $user->hasRight('commande', 'order_advance', 'validate');
			}
			return $user->hasRight('commande', 'creer');
		}
		if (getDolGlobalString('MAIN_USE_ADVANCED_PERMS')) {
			return $user->hasRight('fournisseur', 'supplier_order_advance', 'validate');
		}
		return $user->hasRight('fournisseur', 'commande', 'creer') || $user->hasRight('supplier_order', 'creer');
	}

	/**
	 * Payment terms and mode proposed for the order: the third party defaults, else the most
	 * used ones in its last supplier invoices (supplier orders), else Dolibarr defaults
	 *
	 * @param string  $docType    Document type
	 * @param Societe $thirdparty Third party
	 * @return array  cond_reglement_id, mode_reglement_id
	 */
	public function getPaymentOptions($docType, $thirdparty)
	{
		if ($docType === UpInvoiceFiles::DOC_CUSTOMER_ORDER) {
			return array(
				'cond_reglement_id' => (int) $thirdparty->cond_reglement_id,
				'mode_reglement_id' => (int) $thirdparty->mode_reglement_id,
			);
		}
		$options = array(
			'cond_reglement_id' => (int) $thirdparty->cond_reglement_supplier_id,
			'mode_reglement_id' => (int) $thirdparty->mode_reglement_supplier_id,
		);
		if (empty($options['cond_reglement_id']) || empty($options['mode_reglement_id'])) {
			$fromInvoices = (new UpInvoiceInvoice($this->db))->getSupplierPaymentOptions($thirdparty->id);
			foreach (array('cond_reglement_id', 'mode_reglement_id') as $key) {
				if (empty($options[$key]) && !empty($fromInvoices[$key])) {
					$options[$key] = (int) $fromInvoices[$key];
				}
			}
		}
		return $options;
	}

	/**
	 * Orders of the third party with the same reference (ref_supplier for supplier orders,
	 * ref_client for customer orders): a warning before creating a duplicate
	 *
	 * @param string $docType Document type
	 * @param int    $socid   Third party
	 * @param string $ref     Reference of the document
	 * @return array          List of array('id', 'ref', 'url')
	 */
	public function findSameReference($docType, $socid, $ref)
	{
		$ref = trim((string) $ref);
		if ($ref === '' || $socid <= 0) {
			return array();
		}
		if ($docType === UpInvoiceFiles::DOC_CUSTOMER_ORDER) {
			$sql = "SELECT rowid, ref FROM ".MAIN_DB_PREFIX."commande WHERE fk_soc = ".((int) $socid);
			$sql .= " AND ref_client = '".$this->db->escape($ref)."' AND entity IN (".getEntity('commande').")";
		} else {
			$sql = "SELECT rowid, ref FROM ".MAIN_DB_PREFIX."commande_fournisseur WHERE fk_soc = ".((int) $socid);
			$sql .= " AND ref_supplier = '".$this->db->escape($ref)."' AND entity IN (".getEntity('supplier_order').")";
		}
		$sql .= " ORDER BY rowid DESC";
		$found = array();
		$resql = $this->db->query($sql.$this->db->plimit(5));
		if ($resql) {
			dol_include_once('/upinvoice/lib/upinvoice.lib.php');
			while ($obj = $this->db->fetch_object($resql)) {
				$found[] = array('id' => (int) $obj->rowid, 'ref' => $obj->ref, 'url' => upinvoice_object_card_url($docType, $obj->rowid));
			}
		}
		return $found;
	}

	/**
	 * Create the order
	 *
	 * @param string $docType  supplier_order or customer_order
	 * @param array  $data     ref, date, delivery_date, cond_reglement_id, mode_reglement_id, note_public,
	 *                         note_private, calc_method, validate, extracted_total_ttc, lines[]
	 * @param int    $socid    Third party
	 * @param User   $user     User
	 * @param string $filePath Document to attach
	 * @return array           status (success|error), id, message
	 */
	public function createOrder($docType, $data, $socid, $user, $filePath)
	{
		global $langs;

		$isCustomer = ($docType === UpInvoiceFiles::DOC_CUSTOMER_ORDER);
		if (empty($data['lines'])) {
			return array('status' => 'error', 'message' => $langs->trans('UpInvoiceOrderNoLines'));
		}

		$this->db->begin();

		$order = $this->newOrder($docType);
		$order->socid = (int) $socid;
		if ($isCustomer) {
			$order->ref_client = $data['ref'];
		} else {
			$order->ref_supplier = $data['ref'];
		}
		$order->date = $data['date'] ? $data['date'] : dol_now();
		$order->date_commande = $order->date;
		$order->delivery_date = !empty($data['delivery_date']) ? $data['delivery_date'] : null;
		$order->cond_reglement_id = (int) $data['cond_reglement_id'];
		$order->mode_reglement_id = (int) $data['mode_reglement_id'];
		$order->note_public = $data['note_public'];
		$order->note_private = $data['note_private'];

		$result = $order->create($user);
		if ($result <= 0) {
			$this->db->rollback();
			return array('status' => 'error', 'message' => $langs->trans('UpInvoiceErrorCreatingOrder').': '.$order->error.' '.implode(', ', (array) $order->errors));
		}

		foreach ($data['lines'] as $line) {
			$qty = (float) price2num($line['qty']);
			$pu = (float) price2num($line['pu_ht']);
			$tva = (float) price2num($line['tva_tx']);
			$remise = (float) price2num($line['remise_percent']);
			$fkProduct = (int) $line['fk_product'];
			$type = (int) $line['product_type'];
			$desc = (string) $line['product_desc'];

			if ($isCustomer) {
				$res = $order->addline($desc, $pu, $qty, $tva, 0, 0, $fkProduct, $remise, 0, 0, 'HT', 0, '', '', $type);
			} else {
				// No supplier price id: the price of the document is kept (as when typing the line by hand)
				$res = $order->addline($desc, $pu, $qty, $tva, 0, 0, $fkProduct, 0, (string) ($line['ref_supplier'] ?? ''), $remise, 'HT', 0, $type);
			}
			if ($res <= 0) {
				$this->db->rollback();
				return array('status' => 'error', 'message' => $langs->trans('UpInvoiceErrorAddingOrderLine').': '.dol_trunc($desc, 40).' '.$order->error);
			}
		}

		// Totals with the VAT method chosen on the page (1 = total of rounded lines, 2 = rounded total)
		$calcMethod = (int) $data['calc_method'];
		$roundmode = ($calcMethod == 1) ? '0' : (($calcMethod == 2) ? '1' : 'auto');
		$order->fetch($order->id);
		$order->update_price(1, $roundmode);
		$this->reconcileTotals($docType, $order, $data, $user);

		$messages = array();
		if (!empty($data['validate'])) {
			$order->fetch($order->id);
			$res = $order->valid($user);
			if ($res < 0) {
				// The order stays as a draft; the user can validate it from its card
				$messages[] = $langs->trans('UpInvoiceOrderNotValidated').': '.$order->error;
			}
		}

		$order->fetch($order->id);
		$attach = $this->attachDocument($docType, $order, $filePath, $user);
		if ($attach < 0) {
			$messages[] = $langs->trans('UpInvoiceOrderAttachFailed');
		}

		$this->db->commit();

		$message = $langs->trans($order->status > 0 ? 'UpInvoiceOrderCreatedAndValidated' : 'UpInvoiceOrderCreated', $order->ref);
		if ($messages) {
			$message .= ' '.implode(' ', $messages);
		}
		return array('status' => 'success', 'id' => $order->id, 'ref' => $order->ref, 'message' => $message, 'warning' => !empty($messages));
	}

	/**
	 * Same rule as invoices: a cent-level gap between the computed totals and the document
	 * total is absorbed into one line; a larger gap is left (it is shown as a warning).
	 * Orders without amounts (total 0) are left as they are.
	 *
	 * @param string                       $docType Document type
	 * @param CommandeFournisseur|Commande $order   Order (already priced)
	 * @param array                        $data    Form data (extracted_total_ttc, lines)
	 * @param User                         $user    User
	 * @return void
	 */
	private function reconcileTotals($docType, $order, $data, $user)
	{
		$exTtc = isset($data['extracted_total_ttc']) ? (float) price2num($data['extracted_total_ttc']) : 0;
		if ($exTtc <= 0) {
			return;
		}
		$tol = min(0.10, 0.01 + 0.01 * count($data['lines']));
		$diffTtc = (float) price2num($exTtc - (float) $order->total_ttc);
		if ($diffTtc == 0.0 || abs($diffTtc) > $tol) {
			return;
		}

		$order->fetch_lines();
		$target = null;
		foreach ($order->lines as $ln) {
			if ((float) $ln->tva_tx > 0 && ($target === null || (float) $ln->total_ht > (float) $target->total_ht)) {
				$target = $ln;
			}
		}
		$adjustVat = ($target !== null);
		if ($target === null) {
			foreach ($order->lines as $ln) {
				if ($target === null || (float) $ln->total_ht > (float) $target->total_ht) {
					$target = $ln;
				}
			}
		}
		if ($target === null) {
			return;
		}

		if ($adjustVat) {
			$target->total_tva = (float) price2num((float) $target->total_tva + $diffTtc);
			$newTva = (float) price2num((float) $order->total_tva + $diffTtc);
			$newHt = (float) $order->total_ht;
		} else {
			$target->total_ht = (float) price2num((float) $target->total_ht + $diffTtc);
			$newTva = (float) $order->total_tva;
			$newHt = (float) price2num((float) $order->total_ht + $diffTtc);
		}
		$target->total_ttc = (float) price2num((float) $target->total_ttc + $diffTtc);

		// Line update signatures differ: supplier order lines update($notrigger), order lines update($user, $notrigger)
		if ($docType === UpInvoiceFiles::DOC_CUSTOMER_ORDER) {
			$target->update($user, 1);
		} else {
			$target->update(1);
		}

		$sql = "UPDATE ".MAIN_DB_PREFIX.$order->table_element." SET";
		$sql .= " total_ht = ".((float) $newHt).", total_tva = ".((float) $newTva).", total_ttc = ".((float) price2num($exTtc));
		$sql .= " WHERE rowid = ".((int) $order->id);
		$this->db->query($sql);
		dol_syslog("UpInvoiceOrder reconcileTotals: absorbed ".$diffTtc." into line ".$target->id, LOG_INFO);
	}

	/**
	 * Copy the document to the order directory and register it in the ECM, so it shows in the
	 * Documents tab of the order (the directory follows the order ref, PROV... for a draft;
	 * Dolibarr renames it when the order is validated)
	 *
	 * @param string                       $docType  Document type
	 * @param CommandeFournisseur|Commande $order    Order
	 * @param string                       $filePath Document
	 * @param User                         $user     User
	 * @return int                                   >0 OK, <0 KO
	 */
	private function attachDocument($docType, $order, $filePath, $user)
	{
		global $conf;

		if (empty($filePath) || !file_exists($filePath)) {
			return -1;
		}
		include_once DOL_DOCUMENT_ROOT.'/ecm/class/ecmfiles.class.php';

		$ref = dol_sanitizeFileName($order->ref);
		if ($docType === UpInvoiceFiles::DOC_CUSTOMER_ORDER) {
			$baseDir = !empty($conf->commande->multidir_output[$order->entity]) ? $conf->commande->multidir_output[$order->entity] : $conf->commande->dir_output;
			$relDir = 'commande/'.$ref;
			$srcType = 'commande';
		} else {
			$baseDir = !empty($conf->fournisseur->commande->multidir_output[$order->entity]) ? $conf->fournisseur->commande->multidir_output[$order->entity] : $conf->fournisseur->commande->dir_output;
			$relDir = 'fournisseur/commande/'.$ref;
			$srcType = 'order_supplier';
		}
		$orderDir = $baseDir.'/'.$ref;
		if (!is_dir($orderDir)) {
			dol_mkdir($orderDir);
		}
		$filename = basename($filePath);
		$destFile = $orderDir.'/'.$filename;
		if (dol_copy($filePath, $destFile, 0, 1) < 0) {
			return -2;
		}

		$ecmfile = new EcmFiles($this->db);
		$ecmfile->filepath = $relDir;
		$ecmfile->filename = $filename;
		$ecmfile->label = md5_file(dol_osencode($destFile));
		$ecmfile->fullpath_orig = $filePath;
		$ecmfile->gen_or_uploaded = 'uploaded';
		$ecmfile->description = '';
		$ecmfile->keywords = '';
		$ecmfile->entity = $conf->entity;
		$ecmfile->fk_create_user = $user->id;
		$ecmfile->src_object_type = $srcType;
		$ecmfile->src_object_id = $order->id;
		if ($ecmfile->create($user) < 0) {
			return -3;
		}
		return 1;
	}
}
