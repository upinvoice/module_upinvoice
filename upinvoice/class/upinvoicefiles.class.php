<?php
/* Copyright (C) 2023
 * Licensed under the GNU General Public License version 3
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * Class to manage uploaded invoice files
 */
class UpInvoiceFiles extends CommonObject
{
    /**
     * @var DoliDB Database handler
     */
    public $db;
    
    /**
     * @var string ID from database
     */
    public $id;
    
    /**
     * @var string File path
     */
    public $file_path;
    
    /**
     * @var string Original filename
     */
    public $original_filename;
    
    /**
     * @var int File size in bytes
     */
    public $file_size;
    
    /**
     * @var string File MIME type
     */
    public $file_type;
    
    /**
     * @var string JSON data from UpInvoice API
     */
    public $api_json;
    
    /**
     * @var int Creation timestamp
     */
    public $date_creation;
    
    /**
     * @var int Creator user ID
     */
    public $fk_user_creat;
    
    /**
     * @var int Modification timestamp
     */
    public $date_modification;
    
    /**
     * @var int User who modified record
     */
    public $fk_user_modif;
    
    /**
     * @var int Current import step (1=upload, 2=supplier validation, 3=invoice validation)
     */
    public $import_step;
    
    /**
     * @var int Supplier ID
     */
    public $fk_supplier;
    
    /**
     * @var int Invoice ID
     */
    public $fk_invoice;
    
    /**
     * @var int Status (0=pending, 1=processed, -1=error)
     */
    public $status;
    
    /**
     * @var int Processing flag (0=not processing, 1=processing)
     */
    public $processing;
    
    /**
     * @var string Error message if any
     */
    public $import_error;
    
    /**
     * @var string Table name
     */
    public $table_element = 'upinvoice_files';
    
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
     * Create record in database
     *
     * @param User $user User that creates
     * @param int $notrigger 0=launch triggers after, 1=disable triggers
     * @return int <0 if KO, Id of created object if OK
     */
    public function create(User $user, $notrigger = 0)
    {
        global $conf;
        
        $this->db->begin();
        
        $this->date_creation = dol_now();
        $this->fk_user_creat = $user->id;
        
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . $this->table_element . " (";
        $sql .= "file_path, original_filename, file_size, file_type, date_creation, fk_user_creat, import_step, status, processing, entity";
        $sql .= ") VALUES (";
        $sql .= " '" . $this->db->escape($this->file_path) . "',";
        $sql .= " '" . $this->db->escape($this->original_filename) . "',";
        $sql .= " " . (int) $this->file_size . ",";
        $sql .= " '" . $this->db->escape($this->file_type) . "',";
        $sql .= " '" . $this->db->idate($this->date_creation) . "',";
        $sql .= " " . (int) $this->fk_user_creat . ",";
        $sql .= " " . (int) $this->import_step . ",";
        $sql .= " " . (int) $this->status . ",";
        $sql .= " " . (int) $this->processing . ",";
        $sql .= " " . (int) $conf->entity;
        $sql .= ")";
        
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = "Error " . $this->db->lasterror();
            $this->db->rollback();
            return -1;
        }
        
        $this->id = $this->db->last_insert_id(MAIN_DB_PREFIX . $this->table_element);
        
        if (!$notrigger) {
            // Call triggers
            $result = $this->call_trigger('UPINVOICEFILE_CREATE', $user);
            if ($result < 0) {
                $this->db->rollback();
                return -1;
            }
            // End call triggers
        }
        
        $this->db->commit();
        return $this->id;
    }
    
    /**
     * Load object in memory from the database
     *
     * @param int    $id Id object
     * @return int <0 if KO, 0 if not found, >0 if OK
     */
    public function fetch($id)
    {
        $sql = "SELECT rowid, file_path, original_filename, file_size, file_type, api_json,";
        $sql .= " date_creation, fk_user_creat, date_modification, fk_user_modif,";
        $sql .= " import_step, fk_supplier, fk_invoice, status, processing, import_error";
        $sql .= " FROM " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " WHERE rowid = " . (int) $id;
        
        $resql = $this->db->query($sql);
        if ($resql) {
            if ($this->db->num_rows($resql)) {
                $obj = $this->db->fetch_object($resql);
                
                $this->id = $obj->rowid;
                $this->file_path = $obj->file_path;
                $this->original_filename = $obj->original_filename;
                $this->file_size = $obj->file_size;
                $this->file_type = $obj->file_type;
                $this->api_json = $obj->api_json;
                $this->date_creation = $this->db->jdate($obj->date_creation);
                $this->fk_user_creat = $obj->fk_user_creat;
                $this->date_modification = $this->db->jdate($obj->date_modification);
                $this->fk_user_modif = $obj->fk_user_modif;
                $this->import_step = $obj->import_step;
                $this->fk_supplier = $obj->fk_supplier;
                $this->fk_invoice = $obj->fk_invoice;
                $this->status = $obj->status;
                $this->processing = $obj->processing;
                $this->import_error = $obj->import_error;
                
                $this->db->free($resql);
                return 1;
            } else {
                $this->db->free($resql);
                return 0;
            }
        } else {
            $this->error = "Error " . $this->db->lasterror();
            return -1;
        }
    }
    
    /**
     * Update record in database
     *
     * @param User $user User that modifies
     * @param int $notrigger 0=launch triggers after, 1=disable triggers
     * @return int <0 if KO, >0 if OK
     */
    public function update(User $user, $notrigger = 0)
    {
        $this->db->begin();
        
        $this->date_modification = dol_now();
        $this->fk_user_modif = $user->id;
        
        $sql = "UPDATE " . MAIN_DB_PREFIX . $this->table_element . " SET";
        $sql .= " file_path = " . (isset($this->file_path) ? "'".$this->db->escape($this->file_path)."'" : "null") . ",";
        $sql .= " original_filename = " . (isset($this->original_filename) ? "'".$this->db->escape($this->original_filename)."'" : "null") . ",";
        $sql .= " file_size = " . (isset($this->file_size) ? (int) $this->file_size : "null") . ",";
        $sql .= " file_type = " . (isset($this->file_type) ? "'".$this->db->escape($this->file_type)."'" : "null") . ",";
        $sql .= " api_json = " . (isset($this->api_json) ? "'".$this->db->escape($this->api_json)."'" : "null") . ",";
        $sql .= " date_modification = '" . $this->db->idate($this->date_modification) . "',";
        $sql .= " fk_user_modif = " . (int) $this->fk_user_modif . ",";
        $sql .= " import_step = " . (int) $this->import_step . ",";
        $sql .= " fk_supplier = " . (isset($this->fk_supplier) ? (int) $this->fk_supplier : "null") . ",";
        $sql .= " fk_invoice = " . (isset($this->fk_invoice) ? (int) $this->fk_invoice : "null") . ",";
        $sql .= " status = " . (int) $this->status . ",";
        $sql .= " processing = " . (int) $this->processing . ",";
        $sql .= " import_error = " . (isset($this->import_error) ? "'".$this->db->escape($this->import_error)."'" : "null");
        $sql .= " WHERE rowid = " . (int) $this->id;
        
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = "Error " . $this->db->lasterror();
            $this->db->rollback();
            return -1;
        }
        
        if (!$notrigger) {
            // Call triggers
            $result = $this->call_trigger('UPINVOICEFILE_MODIFY', $user);
            if ($result < 0) {
                $this->db->rollback();
                return -1;
            }
            // End call triggers
        }
        
        $this->db->commit();
        return 1;
    }
    
    /**
     * Delete record from database
     *
     * @param User $user User that deletes
     * @param int $notrigger 0=launch triggers after, 1=disable triggers
     * @return int <0 if KO, >0 if OK
     */
    public function delete(User $user, $notrigger = 0)
    {
        $this->db->begin();
        
        if (!$notrigger) {
            // Call triggers
            $result = $this->call_trigger('UPINVOICEFILE_DELETE', $user);
            if ($result < 0) {
                $this->db->rollback();
                return -1;
            }
            // End call triggers
        }
        
        // Delete physical file
        if (!empty($this->file_path) && file_exists($this->file_path)) {
            @unlink($this->file_path);
        }
        
        // Delete record
        $sql = "DELETE FROM " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " WHERE rowid = " . (int) $this->id;
        
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = "Error " . $this->db->lasterror();
            $this->db->rollback();
            return -1;
        }
        
        $this->db->commit();
        return 1;
    }
    
    /**
     * Get all pending files not being processed
     *
     * @return array|int Array of records or -1 if error
     */
    public function getPendingFiles()
    {
        $files = array();
        
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " WHERE processing = 0 AND status = 0";
        $sql .= " ORDER BY date_creation ASC";
        
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = "Error " . $this->db->lasterror();
            return -1;
        }
        
        while ($obj = $this->db->fetch_object($resql)) {
            $file = new UpInvoiceFiles($this->db);
            $file->fetch($obj->rowid);
            $files[] = $file;
        }
        
        return $files;
    }
    
    /**
     * Process file through UpInvoice API
     *
     * @param User $user User that processes
     * @return int <0 if KO, >0 if OK
     */
    public function processWithApi(User $user)
    {
        global $conf, $langs;
        $langs->load("upinvoice@upinvoice");
        
        // Set processing flag
        $this->processing = 1;
        $this->update($user, 1);
        
        try {
            // Check if file exists
            if (!file_exists($this->file_path)) {
                throw new Exception("File not found: " . $this->file_path);
            }
            
            // API URL and Key from config
            $apiUrl = !empty($conf->global->UPINVOICE_API_URL) ? $conf->global->UPINVOICE_API_URL : 'https://upinvoice.eu/api/process-invoice';
            $apiKey = !empty($conf->global->UPINVOICE_API_KEY) ? $conf->global->UPINVOICE_API_KEY : '';
            
            $linkToSetup = dol_buildpath('/upinvoice/admin/setup.php', 1);
            if (empty($apiKey)) {
                throw new Exception("UpInvoice API key not configured. <a href=\"$linkToSetup\">Go to module setup</a>");
            }
            
            // Prepare API request
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            
            // Set headers
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Authorization: Bearer ' . $apiKey,
                'Accept: application/json'
            ));
            
            // Prepare file for upload
            $cFile = new CURLFile(
                $this->file_path,
                $this->file_type,
                $this->original_filename
            );
            
            $supportedMimeTypes = ['application/pdf', 'image/jpeg', 'image/png'];
            if (!in_array($this->file_type, $supportedMimeTypes)) {
                throw new Exception("Unsupported file type: " . $this->file_type);
            }

            //$cFile debe ser base64_encode
            $cFile = base64_encode(file_get_contents($this->file_path));
            $cFile = 'data:'.$this->file_type.';base64,'.$cFile;

            //Definitmos $company_tax_id... $conf->global->MAIN_INFO_TVAINTRA si existe quitando las 2 primeras letras o $conf->global->MAIN_INFO_SIREN si existe o $conf->global->MAIN_INFO_SIRET si existe o $conf->global->MAIN_INFO_APE si existe. Si no mostramos un error para que el usuario rellene el campo de la empresa
            $companyTaxId = '';
            if (!empty($conf->global->MAIN_INFO_TVAINTRA)) {
                // Remove first 2 characters si son letras...
                if (preg_match('/^[A-Z]{2}/', $conf->global->MAIN_INFO_TVAINTRA)) {
                    $companyTaxId = substr($conf->global->MAIN_INFO_TVAINTRA, 2); // Remove first 2 characters
                } else if (preg_match('/^[a-z]{2}/', $conf->global->MAIN_INFO_TVAINTRA)) {
                    $companyTaxId = substr($conf->global->MAIN_INFO_TVAINTRA, 2); // Remove first 2 characters
                } {
                    $companyTaxId = $conf->global->MAIN_INFO_TVAINTRA; // Use as is
                }
            } elseif (!empty($conf->global->MAIN_INFO_SIREN)) {
                $companyTaxId = $conf->global->MAIN_INFO_SIREN;
            } elseif (!empty($conf->global->MAIN_INFO_SIRET)) {
                $companyTaxId = $conf->global->MAIN_INFO_SIRET;
            } elseif (!empty($conf->global->MAIN_INFO_APE)) {
                $companyTaxId = $conf->global->MAIN_INFO_APE;
            }
            if (empty($companyTaxId)) {
                throw new Exception("Company tax ID not configured. Please fill in the company information.");
            }

            $post = array(
                'invoice_file' => $cFile ,
                'company_name' => $conf->global->MAIN_INFO_SOCIETE_NOM,
                'company_tax_id' => $companyTaxId,
            );
            
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
            
            // Execute API call
            $response = curl_exec($ch);
            
            // Check for errors
            if (curl_errno($ch)) {
                throw new Exception("API request failed: " . curl_error($ch));
            }
            
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if($httpCode == 400){
                // Si en $response hay un mensaje de error, lo mostramos
                $jsonResponse = json_decode($response, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception("Invalid JSON response: " . json_last_error_msg());
                }
                if(isset($jsonResponse['error']) || isset($jsonResponse['message'])){
                    //Si $jsonResponse['error'] contiene el texto "You have consumed your plan" mostramos un mensaje de error traducible
                    if(isset($jsonResponse['error']) && strpos($jsonResponse['error'], "You have consumed your plan") !== false){
                        throw new Exception($langs->trans("consumedPlans"));
                    }
                    
                    throw new Exception($jsonResponse['error'] ?? $jsonResponse['message']);
                }
            }

            if ($httpCode != 200) {
                $jsonResponse = json_decode($response, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    // Log the JSON response
                    error_log("API returned HTTP code $httpCode: " . print_r($jsonResponse, true));
                    if (isset($jsonResponse['message'])) {
                        // Si $jsonResponse['message'] es "Division by zero" mostramos un mensaje de error personalizado: "The IA processing failed, please try again. If the problem persists, contact info@upinvoice.eu"
                        if ($jsonResponse['message'] === "Division by zero") {
                            throw new Exception("The IA processing failed, please try again. If the problem persists, contact info@upinvoice.eu");
                        }
                        throw new Exception("API returned error: " . $jsonResponse['message']);
                    }
                }

                throw new Exception("API returned HTTP code $httpCode: $response");
            }
            
            curl_close($ch);
            
            // Process response
            $jsonResponse = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Invalid JSON response: " . json_last_error_msg());
            }

            if (!$jsonResponse['success']) {
                throw new Exception("API returned error: " . $jsonResponse['message']);
            }
            
            if($jsonResponse['data']){
                $this->api_json = json_encode($jsonResponse['data']);
            } else {
                throw new Exception("API returned empty data");
            }
            
            // Store API response in database
            $this->status = 1; // Processed
            $this->processing = 0; // No longer processing
            $this->import_step = 2; // Next step: supplier validation
            $this->import_error = '';
            
            $result = $this->update($user, 1);
            if ($result < 0) {
                throw new Exception("Failed to update database record: " . $this->error);
            }
            
            return 1;
            
        } catch (Exception $e) {
            // Update record with error
            $this->status = -1; // Error
            $this->processing = 0; // No longer processing
            $this->import_error = $e->getMessage();
            $this->update($user, 1);
            
            return -1;
        }
    }
}