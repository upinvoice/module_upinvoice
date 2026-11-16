<?php
/* Copyright (C) 2023
 * Licensed under the GNU General Public License version 3
 */

require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formother.class.php';

/**
 * Class to handle supplier operations for UpInvoice Import module
 */
class UpInvoiceSupplier
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
     * Search suppliers by tax ID (siren, siret, tva_intra)
     * 
     * @param string $taxId Tax ID to search for (only numbers)
     * @return array|int Array of suppliers or -1 if error
     */
    public function searchByTaxId($taxId)
    {
        // Clean input - keep only numbers for comparison
        $cleanTaxId = preg_replace('/[^0-9]/', '', $taxId);
        
        if (empty($cleanTaxId)) {
            return array();
        }
        
        $suppliers = array();
        
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."societe";
        $sql .= " WHERE fournisseur = 1"; // Must be a supplier
        $sql .= " AND (";
        
        // Search in various ID fields (remove non-numeric chars for comparison)
        $sql .= " REPLACE(REPLACE(REPLACE(siren, ' ', ''), '-', ''), '.', '') LIKE '%" . $this->db->escape($cleanTaxId) . "%'";
        $sql .= " OR REPLACE(REPLACE(REPLACE(siret, ' ', ''), '-', ''), '.', '') LIKE '%" . $this->db->escape($cleanTaxId) . "%'";
        $sql .= " OR REPLACE(REPLACE(REPLACE(tva_intra, ' ', ''), '-', ''), '.', '') LIKE '%" . $this->db->escape($cleanTaxId) . "%'";
        $sql .= " OR REPLACE(REPLACE(REPLACE(idprof4, ' ', ''), '-', ''), '.', '') LIKE '%" . $this->db->escape($cleanTaxId) . "%'";
        $sql .= " OR REPLACE(REPLACE(REPLACE(idprof5, ' ', ''), '-', ''), '.', '') LIKE '%" . $this->db->escape($cleanTaxId) . "%'";
        $sql .= " OR REPLACE(REPLACE(REPLACE(idprof6, ' ', ''), '-', ''), '.', '') LIKE '%" . $this->db->escape($cleanTaxId) . "%'";
        $sql .= " OR REPLACE(REPLACE(REPLACE(ape, ' ', ''), '-', ''), '.', '') LIKE '%" . $this->db->escape($cleanTaxId) . "%'";
        $sql .= ")";
        
        $resql = $this->db->query($sql);
        if (!$resql) {
            return -1;
        }
        
        while ($obj = $this->db->fetch_object($resql)) {
            $supplier = new Fournisseur($this->db);
            $supplier->fetch($obj->rowid);
            $suppliers[] = $supplier;
        }
        
        return $suppliers;
    }
    
    /**
     * Search suppliers by name
     * 
     * @param string $name Name to search for
     * @return array|int Array of suppliers or -1 if error
     */
    public function searchByName($name)
    {
        // Clean input
        $name = trim($name);
        
        if (empty($name)) {
            return array();
        }
        
        // Split name into words for better search
        $keywords = preg_split('/\s+/', $name);
        $suppliers = array();
        
        if (empty($keywords)) {
            return array();
        }
        
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."societe";
        $sql .= " WHERE fournisseur = 1"; // Must be a supplier
        
        // Search for each keyword with AND condition
        foreach ($keywords as $keyword) {
            if (strlen($keyword) >= 2) {  // Only consider keywords with at least 2 chars
                $sql .= " AND (";
                $sql .= " nom LIKE '%" . $this->db->escape($keyword) . "%'";
                $sql .= " OR name_alias LIKE '%" . $this->db->escape($keyword) . "%'";
                $sql .= ")";
            }
        }
        
        $resql = $this->db->query($sql);
        if (!$resql) {
            return -1;
        }
        
        while ($obj = $this->db->fetch_object($resql)) {
            $supplier = new Fournisseur($this->db);
            $supplier->fetch($obj->rowid);
            $suppliers[] = $supplier;
        }
        
        return $suppliers;
    }
    
    /**
     * Search suppliers by combined term (name or tax ID)
     * 
     * @param string $term Search term
     * @return array Combined results from name and tax ID searches, without duplicates
     */
    public function searchByCombinedTerm($term, $key = '')
    {
        $suppliers = array();
        $supplierIds = array(); // To track IDs and avoid duplicates
        

        if(empty($key) || $key == 'tva'){
            // First search by tax ID
            $taxIdNumeric = preg_replace('/[^0-9]/', '', $term);
            if (!empty($taxIdNumeric)) {
                $taxResults = $this->searchByTaxId($taxIdNumeric);
                if (is_array($taxResults)) {
                    foreach ($taxResults as $supplier) {
                        if (!in_array($supplier->id, $supplierIds)) {
                            $suppliers[] = $supplier;
                            $supplierIds[] = $supplier->id;
                        }
                    }
                }
            }
        }
        
        // Then search by name
        if(empty($key) || $key == 'name'){
            $nameResults = $this->searchByName($term);
            if (is_array($nameResults)) {
                foreach ($nameResults as $supplier) {
                    if (!in_array($supplier->id, $supplierIds)) {
                        $suppliers[] = $supplier;
                        $supplierIds[] = $supplier->id;
                    }
                }
            }
        }
        
        return $suppliers;
    }
    
    /**
     * Create a new supplier based on UpInvoice API data
     * 
     * @param array $data Supplier data from UpInvoice API
     * @param User $user User creating the supplier
     * @return int <0 if KO, supplier ID if OK
     */
    public function createSupplier($data, $user)
    {
        global $conf, $langs;
        
        $error = 0;
        $result = 0;
        
        $db = $this->db;
        $db->begin();
        
        try {
            $supplier = new Fournisseur($db);
            
            // Datos básicos del proveedor
            $supplier->name = !empty($data['name']) ? $data['name'] : null;
            $supplier->name_alias = !empty($data['name_alias']) ? $data['name_alias'] : null;
            $supplier->address = !empty($data['address']) ? $data['address'] : null;
            $supplier->zip = !empty($data['zip']) ? $data['zip'] : null;
            $supplier->town = !empty($data['town']) ? $data['town'] : null;
            
            // Es proveedor por defecto
            $supplier->fournisseur = 1;
            $supplier->code_fournisseur = 'auto';//$this->getNextSupplierCode();
            
            // Información fiscal
            // supplier.php envía 'tva_intra'; 'vat_number' se mantiene por compatibilidad
            $vatNumber = !empty($data['tva_intra']) ? $data['tva_intra'] : (!empty($data['vat_number']) ? $data['vat_number'] : '');
            if ($vatNumber !== '') {
                $supplier->tva_intra = $vatNumber;
                // Sujeto a IVA: solo si se indica; si no, se deja el valor por defecto de Societe (1)
                if (isset($data['vat_used'])) {
                    $supplier->tva_assuj = !empty($data['vat_used']) ? 1 : 0;
                }
            }
            
            // País y estado
            if (!empty($data['country_code'])) {
                include_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
                $country_id = getCountry($data['country_code'], 3);
                $supplier->country_id = $country_id;
                $supplier->country_code = $data['country_code'];
                
                // Si tenemos país y estado, buscar el ID del estado
                if (!empty($data['state_id'])) {
                    $supplier->state_id = $data['state_id'];
                }
            }
            
            // IDs profesionales
            if (!empty($data['idprof1'])) $supplier->idprof1 = $data['idprof1'];
            if (!empty($data['idprof2'])) $supplier->idprof2 = $data['idprof2'];
            if (!empty($data['idprof3'])) $supplier->idprof3 = $data['idprof3'];
            if (!empty($data['idprof4'])) $supplier->idprof4 = $data['idprof4'];
            if (!empty($data['idprof5'])) $supplier->idprof5 = $data['idprof5'];
            if (!empty($data['idprof6'])) $supplier->idprof6 = $data['idprof6'];
            
            // Información de contacto
            if (!empty($data['phone'])) $supplier->phone = $data['phone'];
            if (!empty($data['email'])) $supplier->email = $data['email'];
            if (!empty($data['fax'])) $supplier->fax = $data['fax'];
            if (!empty($data['url'])) $supplier->url = $data['url'];
            
            // Tipo de empresa y fuerza laboral
            if (!empty($data['typent_id'])) $supplier->typent_id = $data['typent_id'];
            if (!empty($data['effectif_id'])) $supplier->effectif_id = $data['effectif_id'];
            
            // Forma jurídica
            if (!empty($data['forme_juridique_code'])) $supplier->forme_juridique_code = $data['forme_juridique_code'];
            
            // Capital
            if (isset($data['capital'])) $supplier->capital = $data['capital'];
            
            // Estado
            $supplier->status = isset($data['status']) ? $data['status'] : 1; // Activo por defecto
            
            // No es cliente por defecto
            $supplier->client = 0;
            
            // Incoterms si está habilitado
            if (isModEnabled('incoterm') && !empty($data['incoterm_id'])) {
                $supplier->fk_incoterms = $data['incoterm_id'];
                $supplier->location_incoterms = !empty($data['location_incoterms']) ? $data['location_incoterms'] : '';
            }
            
            // Códigos contables
            if (isModEnabled('accounting')) {
                if (!empty($data['accountancy_code_sell'])) $supplier->accountancy_code_sell = $data['accountancy_code_sell'];
                if (!empty($data['accountancy_code_buy'])) $supplier->accountancy_code_buy = $data['accountancy_code_buy'];
            }
            
            // Multimoneda
            if (isModEnabled('multicurrency') && !empty($data['multicurrency_code'])) {
                $supplier->multicurrency_code = $data['multicurrency_code'];
            }
            
            // Creación del proveedor
            $result = $supplier->create($user);
            
            if ($result < 0) {
                throw new Exception($langs->trans('ErrorFailedToCreateSupplier') . ': ' . $supplier->error);
            }
            
            // Asociar categorías de proveedor
            if (!empty($data['categories']) && isModEnabled('category')) {
                $supplier->setCategories($data['categories'], 'supplier');
            }
            
            // Asociar representante comercial
            if (!empty($data['sales_representatives'])) {
                $supplier->setSalesRep($data['sales_representatives']);
            }
            
            $db->commit();
            return $supplier->id;
        } catch (Exception $e) {
            $db->rollback();
            $this->error = $e->getMessage();
            return -1;
        }
    }
    
    /**
     * Find state ID by name and country ID
     * 
     * @param string $stateName State name
     * @param int $countryId Country ID
     * @return int State ID or 0 if not found
     */
    private function findState($stateName, $countryId)
    {
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."c_departements";
        $sql .= " WHERE fk_region IN (SELECT rowid FROM ".MAIN_DB_PREFIX."c_regions WHERE fk_pays = ".(int)$countryId.")";
        $sql .= " AND (nom LIKE '%" . $this->db->escape($stateName) . "%'";
        $sql .= " OR LOWER(nom) LIKE LOWER('%" . $this->db->escape($stateName) . "%')";
        $sql .= " OR UPPER(nom) LIKE UPPER('%" . $this->db->escape($stateName) . "%'))";
        
        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            $obj = $this->db->fetch_object($resql);
            return $obj->rowid;
        }
        
        return 0;
    }
    
    /**
     * Get next supplier code
     * 
     * @return string Next supplier code
     */
    private function getNextSupplierCode()
    {
        global $conf;
        
        // Obtener el prefijo configurable (por defecto "FO")
        $prefix = getDolGlobalString('SOCIETE_SUPPLIER_PREFIX', 'FO');
        
        // Encontrar el código máximo
        $sql = "SELECT MAX(CAST(SUBSTRING(code_fournisseur, " . (strlen($prefix) + 1) . ") AS SIGNED)) as max_code";
        $sql .= " FROM ".MAIN_DB_PREFIX."societe";
        $sql .= " WHERE code_fournisseur LIKE '" . $this->db->escape($prefix) . "%'";
        $sql .= " AND code_fournisseur REGEXP '^" . $this->db->escape($prefix) . "[0-9]+$'";
        
        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            $obj = $this->db->fetch_object($resql);
            $max = intval($obj->max_code);
            return $prefix . sprintf('%06d', $max + 1);
        }
        
        return $prefix . '000001';
    }

    /**
     * Valida los datos del proveedor antes de crearlo
     * 
     * @param array $data Datos del proveedor
     * @return array Array con errores o vacío si todo es correcto
     */
    public function validateSupplierData($data)
    {
        global $langs;
        
        $errors = array();
        
        // Verificar campos obligatorios
        if (empty($data['name'])) {
            $errors[] = $langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('ThirdPartyName'));
        }
        
        // Verificar formato de email
        if (!empty($data['email']) && !isValidEmail($data['email'])) {
            $errors[] = $langs->trans('ErrorBadEMail', $data['email']);
        }
        
        // Verificar formato de URL
        if (!empty($data['url']) && !isValidUrl($data['url'])) {
            $errors[] = $langs->trans('ErrorBadUrl', $data['url']);
        }
        
        // Verificar si el código ya existe
        if (!empty($data['code_fournisseur'])) {
            $supplier = new Fournisseur($this->db);
            if ($supplier->checkCodeFournisseur($data['code_fournisseur'], 0) > 0) {
                $errors[] = $langs->trans('ErrorSupplierCodeAlreadyUsed');
            }
        }
        
        // Verificar VAT
        if (!empty($data['vat_number']) && isInEEC($data)) {
            require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
            $result = checkVATNumber($data['vat_number']);
            if (!$result) {
                $errors[] = $langs->trans('ErrorWrongVATNumber');
            }
        }
        
        return $errors;
    }

    /**
     * Obtiene la información de un proveedor por su ID
     * 
     * @param int $supplierId ID del proveedor
     * @return Fournisseur|null Objeto proveedor o null si no se encuentra
     */
    public function getSupplier($supplierId)
    {
        if (empty($supplierId)) {
            return null;
        }
        
        $supplier = new Fournisseur($this->db);
        $result = $supplier->fetch($supplierId);
        
        if ($result <= 0) {
            $this->error = $supplier->error;
            return null;
        }
        
        return $supplier;
    }
}