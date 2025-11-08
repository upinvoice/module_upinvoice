<?php

/* Copyright (C) 2023
 * Licensed under the GNU General Public License version 3
 */

require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/price.lib.php';

/**
 * Class to handle supplier invoice operations for UpInvoice Import module
 */
class UpInvoiceInvoice
{
    /**
     * @var DoliDB Database handler
     */
    public $db;

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Create supplier invoice in Dolibarr
     *
     * @param array $invoiceData Invoice data
     * @param int $supplierId Supplier ID
     * @param User $user User creating the invoice
     * @param string $filePath Path to the invoice file (PDF or image)
     * @return array Result of the operation [status, id, message]
     */
    public function createInvoice($invoiceData, $supplierId, $user, $filePath = '')
    {
        global $conf, $langs;

        $error = 0;
        $result = array(
            'status' => 'error',
            'id' => 0,
            'message' => ''
        );

        if (empty($supplierId)) {
            $result['message'] = $langs->trans('SupplierIdRequired');
            return $result;
        }

        $this->db->begin();

        try {
            // Create new invoice object
            $invoice = new FactureFournisseur($this->db);
            $invoice->socid = $supplierId;

            // Set invoice data
            $invoice->ref_supplier = $invoiceData['ref_supplier'];

            // Convert date string to timestamp
            if (!empty($invoiceData['date'])) {
                $invoice->date = $invoiceData['date'];
            } else {
                $invoice->date = dol_now();
            }

            // Payment terms and conditions
            if (!empty($invoiceData['cond_reglement_id'])) {
                $invoice->cond_reglement_id = $invoiceData['cond_reglement_id'];
            }
            if (!empty($invoiceData['mode_reglement_id'])) {
                $invoice->mode_reglement_id = $invoiceData['mode_reglement_id'];
            }

            // Bank account
            if (!empty($invoiceData['fk_account'])) {
                $invoice->fk_account = $invoiceData['fk_account'];
            }

            // Note public/private
            if (!empty($invoiceData['note_public'])) {
                $invoice->note_public = $invoiceData['note_public'];
            }
            if (!empty($invoiceData['note_private'])) {
                $invoice->note_private = $invoiceData['note_private'];
            }

            // Create invoice
            $invoice_id = $invoice->create($user);

            if ($invoice_id < 0) {
                throw new Exception($invoice->error);
            }

            // Add invoice lines
            if (!empty($invoiceData['lines']) && is_array($invoiceData['lines'])) {
                foreach ($invoiceData['lines'] as $line) {
                    $line_id = $this->addInvoiceLine($invoice, $line, $user);
                    if ($line_id < 0) {
                        throw new Exception($langs->trans('ErrorAddingInvoiceLine') . ': ' . $invoice->error);
                    }
                }
            }

            // Validar la factura solo si se solicita explícitamente
            $shouldValidate = isset($invoiceData['validate']) && $invoiceData['validate'] === true;

            if ($shouldValidate) {
                $result_validate = $invoice->validate($user);
                if ($result_validate < 0) {
                    throw new Exception($langs->trans('ErrorValidatingInvoice') . ': ' . $invoice->error);
                }
            }

            // Attach document if provided
            if (!empty($filePath) && file_exists($filePath)) {
                $result_file = $this->attachDocument($invoice, $filePath, $user);
                if ($result_file < 0) {
                    throw new Exception($langs->trans('ErrorAttachingDocument'));
                }
            }

            $this->db->commit();

            if ($shouldValidate) {
                $result['status'] = 'success';
                $result['id'] = $invoice_id;
                $result['message'] = $langs->trans('InvoiceCreatedAndValidatedSuccessfully');
            } else {
                $result['status'] = 'success';
                $result['id'] = $invoice_id;
                $result['message'] = $langs->trans('InvoiceCreatedSuccessfully');
            }

            return $result;
        } catch (Exception $e) {
            $this->db->rollback();
            $result['message'] = $e->getMessage();
            return $result;
        }
    }

    /**
     * Add a line to an invoice
     *
     * @param FactureFournisseur $invoice Invoice object
     * @param array $lineData Line data
     * @param User $user User adding the line
     * @return int Line ID if success, < 0 if error
     */
    private function addInvoiceLine($invoice, $lineData, $user)
    {
        // Datos básicos de la línea
        $desc = isset($lineData['product_desc']) ? $lineData['product_desc'] : '';
        $ref_supplier = isset($lineData['ref_supplier']) ? $lineData['ref_supplier'] : '';
        $fk_product = isset($lineData['fk_product']) ? $lineData['fk_product'] : 0;
        $product_type = isset($lineData['product_type']) ? $lineData['product_type'] : 0;
        $remise_percent = isset($lineData['remise_percent']) ? price2num($lineData['remise_percent']) : 0;

        // IMPORTANTE: Asegurarse de tener los valores correctos
        // Si el formulario pasa valores totales en lugar de unitarios, hay que calcular los unitarios
        $qty = isset($lineData['qty']) ? price2num($lineData['qty']) : 1;

        // Si ya tienes el precio unitario, úsalo directamente
        if (isset($lineData['pu_ht']) && $lineData['pu_ht'] > 0) {
            $pu_ht = price2num($lineData['pu_ht']);
        }
        // Si tienes el total pero no el unitario, calcúlalo
        elseif (isset($lineData['total_ht']) && $qty > 0) {
            $pu_ht = price2num($lineData['total_ht'] / $qty / (1 - $remise_percent / 100));
        } else {
            $pu_ht = 0;
        }

        // Tasa de IVA
        $tva_tx = isset($lineData['tva_tx']) ? price2num($lineData['tva_tx']) : 0;

        // Registra para depuración los valores que estás pasando
        dol_syslog("addInvoiceLine: pu_ht=" . $pu_ht . ", qty=" . $qty . ", tva_tx=" . $tva_tx . ", fk_product=" . $fk_product . ", product_type=" . $product_type);

        return $invoice->addline(
            $desc,               // Description
            $pu_ht,              // Unit price HT (sin impuestos)
            $tva_tx,             // VAT rate
            0,                   // Localtax1 rate
            0,                   // Localtax2 rate
            $qty,                // Quantity
            $fk_product,         // Product ID
            $remise_percent,     // Remise percent
            '',                  // Date start
            '',                  // Date end
            0,                   // Ventil account code
            0,                   // Info bits
            'HT',                // Price base type (HT = sin impuestos)
            $product_type,       // Product type
            0,                   // Rang
            0,                   // No trigger
            array(),             // Extrafields array
            0,                   // fk_unit
            0,                   // Origin id
            0,                   // Precio en divisa
            $ref_supplier        // Ref supplier
        );
    }

    /**
     * Attach document to an invoice
     *
     * @param FactureFournisseur $invoice Invoice object
     * @param string $filePath Path to the document
     * @param User $user User attaching the document
     * @return int >0 if OK, <0 if KO
     */
    private function attachDocument($invoice, $filePath, $user)
    {
        global $conf;

        // Check if file exists
        if (empty($filePath) || !file_exists($filePath)) {
            return -1;
        }

        $filename = basename($filePath);

        // Get target directory
        $destDir = $conf->fournisseur->facture->dir_output;
        if (!is_dir($destDir)) {
            dol_mkdir($destDir);
        }

        // Get invoice directory
        include_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

        $ref = dol_sanitizeFileName($invoice->ref);
        $invoiceDir = $destDir.'/'.get_exdir($invoice->id, 2, 0, 0, $invoice, 'invoice_supplier').$ref;

        if (!is_dir($invoiceDir)) {
            dol_mkdir($invoiceDir);
        }

        // Copy file to invoice directory
        $destFile = $invoiceDir.'/'.$filename;

        $result = dol_copy($filePath, $destFile, 0, 1); // Overwrites if target exists
        if ($result < 0) {
            return -2;
        }

        // Add the attachment to the database
        if (is_object($invoice) && $invoice->id > 0) {
            include_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
            include_once DOL_DOCUMENT_ROOT.'/ecm/class/ecmfiles.class.php';

            // Create doc object
            $docModel = $conf->global->MAIN_DOCUMENT_IS_OUTSIDE_FOLDER_FOR_EXTERNAL_USER ? 'unboxed' : '0';

            // Add entry in ecm_files table
            $ecmfile = new EcmFiles($this->db);
            $ecmfile->filepath = 'fournisseur/facture/'.get_exdir($invoice->id, 2, 0, 0, $invoice, 'invoice_supplier').$ref;
            $ecmfile->filename = $filename;
            $ecmfile->label = md5_file(dol_osencode($destFile));  // Hash of file content
            $ecmfile->fullpath_orig = $filePath;
            $ecmfile->gen_or_uploaded = 'uploaded';
            $ecmfile->description = ''; // Description can be filled
            $ecmfile->keywords = ''; // Keywords can be filled

            $docModel = '0';
            $ecmfile->entity = $conf->entity;
            $ecmfile->fk_create_user = $user->id;

            // Fill object_type & object_id
            $ecmfile->src_object_type = 'facture_fourn';
            $ecmfile->src_object_id = $invoice->id;

            $result = $ecmfile->create($user);
            if ($result < 0) {
                setEventMessages($ecmfile->error, $ecmfile->errors, 'errors');
                return -1;
            }

            return 1;
        }

        return -3;
    }

    /**
     * Get payment terms, modes and bank accounts from recent invoices
     *
     * @param int $supplierId Supplier ID
     * @return array Array with payment terms, modes and bank accounts
     */
    public function getSupplierPaymentOptions($supplierId)
    {
        $lastInvoices = $this->getLastThreeInvoices($supplierId);
        $valueCounts = [];

        // Default values if no previous invoices
        $defaultOptions = [
            'cond_reglement_id' => 1,
            'cond_reglement_code' => 'RECEP',
            'cond_reglement_label' => 'PaymentConditionShortRECEP',
            'cond_reglement_doc' => 'PaymentConditionRECEP',
            'mode_reglement_id' => 3,
            'mode_reglement_code' => 'PRE',
            'fk_account' => 1,
        ];

        // If no invoices found, return defaults
        if (empty($lastInvoices)) {
            return $defaultOptions;
        }

        // Count occurrences of each value
        foreach ($lastInvoices as $invoice) {
            $extraData = [
                'cond_reglement_id' => $invoice['cond_reglement_id'] ?? '',
                'cond_reglement_code' => $invoice['cond_reglement_code'] ?? '',
                'cond_reglement_label' => $invoice['cond_reglement_label'] ?? '',
                'cond_reglement_doc' => $invoice['cond_reglement_doc'] ?? '',
                'mode_reglement_id' => $invoice['mode_reglement_id'] ?? '',
                'mode_reglement_code' => $invoice['mode_reglement_code'] ?? '',
                'fk_account' => $invoice['fk_account'] ?? '',
            ];

            foreach ($extraData as $key => $value) {
                if (empty($value)) {
                    continue;
                }

                if (!isset($valueCounts[$key])) {
                    $valueCounts[$key] = [];
                }
                if (!isset($valueCounts[$key][$value])) {
                    $valueCounts[$key][$value] = 0;
                }
                $valueCounts[$key][$value]++;
            }
        }

        // Get most common values
        $mostCommonOptions = [];
        foreach ($valueCounts as $key => $values) {
            if (empty($values)) {
                continue;
            }

            arsort($values);
            $mostCommonValue = array_key_first($values);
            $mostCommonOptions[$key] = $mostCommonValue;
        }

        // Merge with defaults for any missing values
        return array_merge($defaultOptions, $mostCommonOptions);
    }

    /**
     * Get the last three invoices for a supplier
     *
     * @param int $supplierId Supplier ID
     * @return array Array of invoices
     */
    private function getLastThreeInvoices($supplierId)
    {
        if (empty($supplierId)) {
            return [];
        }

        $invoices = [];

        // Get the 3 latest invoices for this supplier
        $sql = "SELECT f.rowid, f.fk_cond_reglement, f.fk_mode_reglement, f.fk_account, ";
        $sql .= " c.code as cond_reglement_code, c.libelle as cond_reglement_label, c.libelle_facture as cond_reglement_doc, ";
        $sql .= " p.code as mode_reglement_code ";
        $sql .= " FROM " . MAIN_DB_PREFIX . "facture_fourn as f";
        $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "c_payment_term as c ON f.fk_cond_reglement = c.rowid";
        $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "c_paiement as p ON f.fk_mode_reglement = p.id";
        $sql .= " WHERE f.fk_soc = " . (int)$supplierId;
        $sql .= " AND f.entity = " . (int)$this->db->escape($GLOBALS['conf']->entity);
        $sql .= " ORDER BY f.datef DESC";
        $sql .= " LIMIT 3";

        $resql = $this->db->query($sql);
        if (!$resql) {
            return [];
        }

        $num = $this->db->num_rows($resql);
        if ($num <= 0) {
            return [];
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $invoices[] = [
                'rowid' => $obj->rowid,
                'cond_reglement_id' => $obj->fk_cond_reglement,
                'cond_reglement_code' => $obj->cond_reglement_code,
                'cond_reglement_label' => $obj->cond_reglement_label,
                'cond_reglement_doc' => $obj->cond_reglement_doc,
                'mode_reglement_id' => $obj->fk_mode_reglement,
                'mode_reglement_code' => $obj->mode_reglement_code,
                'fk_account' => $obj->fk_account,
            ];
        }

        return $invoices;
    }

    /**
     * Validate invoice data against JSON data
     *
     * @param array $invoiceData Invoice data from form
     * @param array $jsonData Invoice data from JSON
     * @return array Array with validation results
     */
    public function validateInvoiceData($invoiceData, $jsonData)
    {
        $warnings = array();

        // Check if total amount matches
        if (isset($invoiceData['total_ht']) && isset($jsonData['total_ht'])) {
            $form_total_ht = price2num($invoiceData['total_ht']);
            $json_total_ht = price2num($jsonData['total_ht']);

            if (abs($form_total_ht - $json_total_ht) > 0.01) {
                $warnings[] = array(
                    'type' => 'amount',
                    'message' => 'TotalAmountMismatch',
                    'detected' => $json_total_ht,
                    'entered' => $form_total_ht
                );
            }
        }

        // Check if VAT amount matches
        if (isset($invoiceData['total_tva']) && isset($jsonData['total_tva'])) {
            $form_total_tva = price2num($invoiceData['total_tva']);
            $json_total_tva = price2num($jsonData['total_tva']);

            if (abs($form_total_tva - $json_total_tva) > 0.01) {
                $warnings[] = array(
                    'type' => 'vat',
                    'message' => 'VATAmountMismatch',
                    'detected' => $json_total_tva,
                    'entered' => $form_total_tva
                );
            }
        }

        // Check if total TTC matches
        if (isset($invoiceData['total_ttc']) && isset($jsonData['total_ttc'])) {
            $form_total_ttc = price2num($invoiceData['total_ttc']);
            $json_total_ttc = price2num($jsonData['total_ttc']);

            if (abs($form_total_ttc - $json_total_ttc) > 0.01) {
                $warnings[] = array(
                    'type' => 'ttc',
                    'message' => 'TotalTTCMismatch',
                    'detected' => $json_total_ttc,
                    'entered' => $form_total_ttc
                );
            }
        }

        // Check if dates match
        if (isset($invoiceData['date']) && isset($jsonData['date'])) {
            $form_date = dol_stringtotime($invoiceData['date']);
            $json_date = dol_stringtotime($jsonData['date']);

            if (date('Y-m-d', $form_date) != date('Y-m-d', $json_date)) {
                $warnings[] = array(
                    'type' => 'date',
                    'message' => 'DateMismatch',
                    'detected' => date('Y-m-d', $json_date),
                    'entered' => date('Y-m-d', $form_date)
                );
            }
        }

        // Check if number of lines match
        if (isset($invoiceData['lines']) && isset($jsonData['lines'])) {
            $form_lines_count = count($invoiceData['lines']);
            $json_lines_count = count($jsonData['lines']);

            if ($form_lines_count != $json_lines_count) {
                $warnings[] = array(
                    'type' => 'lines',
                    'message' => 'LineCountMismatch',
                    'detected' => $json_lines_count,
                    'entered' => $form_lines_count
                );
            }
        }

        return $warnings;
    }
}
