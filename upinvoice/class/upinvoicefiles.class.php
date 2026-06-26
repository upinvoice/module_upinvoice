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
     * @var string|null Origin of the file: null/'web' = manual upload; 'email' = queued via EmailCollector
     */
    public $source;

    /**
     * @var string|null SHA-256 hex digest of the raw file content (used for content-based deduplication)
     */
    public $file_hash;

    /**
     * @var string|null Snapshot describing the auto-import rule that queued this email file (NULL = manual)
     */
    public $import_rule_label;

    /**
     * @var int|null VAT calculation method used to create the invoice (1=total of round, 2=round of total)
     */
    public $calc_method;

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
        $sql .= "file_path, original_filename, file_size, file_type, date_creation, fk_user_creat, import_step, status, processing, entity, source, file_hash, import_rule_label, calc_method";
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
        $sql .= " " . (int) $conf->entity . ",";
        $sql .= " " . (!empty($this->source) ? "'".$this->db->escape($this->source)."'" : "NULL") . ",";
        $sql .= " " . (!empty($this->file_hash) ? "'".$this->db->escape($this->file_hash)."'" : "NULL") . ",";
        $sql .= " " . (!empty($this->import_rule_label) ? "'".$this->db->escape($this->import_rule_label)."'" : "NULL") . ",";
        $sql .= " " . (!empty($this->calc_method) ? (int) $this->calc_method : "NULL");
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
        $sql .= " import_step, fk_supplier, fk_invoice, status, processing, import_error, source, file_hash, import_rule_label, calc_method";
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
                $this->source = isset($obj->source) ? $obj->source : null;
                $this->file_hash = isset($obj->file_hash) ? $obj->file_hash : null;
                $this->import_rule_label = isset($obj->import_rule_label) ? $obj->import_rule_label : null;
                $this->calc_method = isset($obj->calc_method) ? $obj->calc_method : null;
                
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
        $sql .= " import_error = " . (isset($this->import_error) ? "'".$this->db->escape($this->import_error)."'" : "null") . ",";
        $sql .= " calc_method = " . (!empty($this->calc_method) ? (int) $this->calc_method : "null");
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
        $sql .= " WHERE processing = 0 AND status = 0 AND source = 'email'";
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
     * Cron method: process pending files with the UpInvoice AI API.
     * Called by the scheduled job declared in modUpInvoice (label "UpInvoice AI queue processing").
     *
     * @param int $maxfiles Maximum number of files to process in one run
     * @return int 0 if OK, >0 if some files failed (count of errors)
     */
    public function processPendingAiBatch($maxfiles = 5)
    {
        global $conf, $user;

        $this->output = '';
        $this->error = '';

        if (!getDolGlobalString('UPINVOICE_AUTO_AI_PROCESSING')) {
            $this->output = 'Automatic AI processing is disabled (UPINVOICE_AUTO_AI_PROCESSING)';
            return 0;
        }

        $maxfiles = max(1, (int) $maxfiles);

        // No entity filter: the cron handles the queue of every entity, switching context per row
        // Only auto-process files that arrived via email; manually uploaded files stay pending
        // until the user clicks "Process" from the web interface.
        $sql = "SELECT rowid, entity FROM ".MAIN_DB_PREFIX.$this->table_element;
        $sql .= " WHERE processing = 0 AND status = 0 AND source = 'email'";
        $sql .= " ORDER BY date_creation ASC";
        $sql .= " ".$this->db->plimit($maxfiles);

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = "Error ".$this->db->lasterror();
            return 1;
        }

        $rows = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $rows[] = array('rowid' => (int) $obj->rowid, 'entity' => (int) $obj->entity);
        }
        $this->db->free($resql);

        if (empty($rows)) {
            $this->output = 'No pending files to process';
            return 0;
        }

        $nbok = 0;
        $nberror = 0;
        $nbskipped = 0;
        $savedentity = $conf->entity;

        foreach ($rows as $row) {
            // Atomic claim so parallel cron runs never process the same file twice
            $sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element." SET processing = 1";
            $sql .= " WHERE rowid = ".((int) $row['rowid'])." AND processing = 0 AND status = 0";
            $resql = $this->db->query($sql);
            if (!$resql || $this->db->affected_rows($resql) == 0) {
                $nbskipped++;
                continue;
            }

            $file = new UpInvoiceFiles($this->db);
            if ($file->fetch($row['rowid']) <= 0) {
                $nbskipped++;
                continue;
            }

            // processWithApi() reads entity-dependent company constants ($conf->global->MAIN_INFO_*)
            $conf->entity = $row['entity'];
            $result = $file->processWithApi($user);
            $conf->entity = $savedentity;

            if ($result > 0) {
                $nbok++;
            } else {
                // processWithApi() already stored status=-1 and import_error on the row
                $nberror++;
                dol_syslog("UpInvoiceFiles::processPendingAiBatch error on file id=".$row['rowid'].": ".$file->import_error, LOG_WARNING);
            }
        }

        $this->output = $nbok." file(s) processed, ".$nberror." error(s), ".$nbskipped." skipped";

        // Per-file API errors are stored on each row (status=-1, import_error) and must not flag the job as KO
        return 0;
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
            $apiUrl = getDolGlobalString('UPINVOICE_API_URL', 'https://upinvoice.eu/api/process-invoice');
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
            
            if (empty($jsonResponse['data']) || !is_array($jsonResponse['data'])) {
                throw new Exception("API returned empty data");
            }

            // Guard: the API can return a "success" response that carries no actual
            // invoice extraction (e.g. only {"available_points": N} when the document
            // could not be read). Storing that as "Processed" leaves a dead card that
            // breaks "Siguiente paso"/"Validar factura". So we only accept a response
            // that contains usable invoice data (a supplier name or at least one line).
            $newData = $jsonResponse['data'];
            $newHasInvoice = !empty($newData['supplier']['name']) || !empty($newData['lines']);
            if (!$newHasInvoice) {
                // If we already had good extracted data (re-processing), keep it intact
                // instead of overwriting it with an empty response.
                $oldData = !empty($this->api_json) ? json_decode($this->api_json, true) : null;
                $oldHasInvoice = is_array($oldData) && (!empty($oldData['supplier']['name']) || !empty($oldData['lines']));
                if ($oldHasInvoice) {
                    $this->processing = 0;
                    $this->update($user, 1);
                    return 1;
                }
                throw new Exception($langs->trans('CouldNotExtractInvoiceData'));
            }

            $this->api_json = json_encode($newData);

            // Store API response in database
            $this->status = 1; // Processed
            $this->processing = 0; // No longer processing
            $this->import_step = 2; // Next step: supplier validation
            $this->import_error = '';

            if (method_exists($this->db, 'ping')) {
                $this->db->ping(); 
            }
            
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

    // ---------------------------------------------------------------------------
    // Shared MIME types allowed for email imports
    // ---------------------------------------------------------------------------
    const EMAIL_ALLOWED_MIMES = array(
        'pdf'  => 'application/pdf',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
    );

    // Attachment formats selectable in auto-import rules (jpg covers jpeg)
    const RULE_FORMATS = array('pdf', 'png', 'jpg');

    /**
     * Load active email-import rules for an entity.
     *
     * @param DoliDB $db     Database handler
     * @param int    $entity Entity
     * @return array Array of rule objects (rowid, sender_contains, subject_contains, filename_pattern, formats)
     */
    public static function loadEmailRules(DoliDB $db, $entity)
    {
        $rules = array();
        $sql = "SELECT rowid, sender_contains, subject_contains, filename_pattern, formats";
        $sql .= " FROM ".MAIN_DB_PREFIX."upinvoice_email_rules";
        $sql .= " WHERE entity = ".((int) $entity)." AND status = 1";
        $sql .= " ORDER BY rowid ASC";
        $resql = $db->query($sql);
        if ($resql) {
            while ($obj = $db->fetch_object($resql)) {
                $rules[] = $obj;
            }
            $db->free($resql);
        }
        return $rules;
    }

    /**
     * Load active blacklist rules for an entity. Blacklist rules ONLY hide matching
     * emails from the listing; they never affect the collector / auto-import.
     *
     * @param DoliDB $db     Database handler
     * @param int    $entity Entity
     * @return array Array of rule objects (same shape as import rules)
     */
    public static function loadBlacklistRules(DoliDB $db, $entity)
    {
        $rules = array();
        $sql = "SELECT rowid, sender_contains, subject_contains, filename_pattern, formats";
        $sql .= " FROM ".MAIN_DB_PREFIX."upinvoice_email_blacklist";
        $sql .= " WHERE entity = ".((int) $entity)." AND status = 1";
        $sql .= " ORDER BY rowid ASC";
        $resql = $db->query($sql);
        if ($resql) {
            while ($obj = $db->fetch_object($resql)) {
                $rules[] = $obj;
            }
            $db->free($resql);
        }
        return $rules;
    }

    /**
     * Whether an email should be hidden from the listing: it is blacklisted only
     * when EVERY one of its attachments matches a blacklist rule, so emails that
     * also carry a non-blacklisted attachment (e.g. a real invoice) still show.
     *
     * @param array $blRules Blacklist rules from loadBlacklistRules()
     * @param array $email   Email row from listEmails()
     * @return bool
     */
    public static function isEmailBlacklisted(array $blRules, array $email)
    {
        if (empty($blRules) || empty($email['attachments'])) {
            return false;
        }
        foreach ($email['attachments'] as $att) {
            $match = self::matchBlacklistRule($blRules, $email['from'], $email['subject'], $att['filename'], $att['ext']);
            if ($match === null) {
                return false;
            }
        }
        return true;
    }

    /**
     * Case-insensitive filename match supporting * and ? wildcards (e.g. "fact*.pdf").
     * An empty pattern matches any filename.
     *
     * @param string $pattern  Glob pattern
     * @param string $filename Filename to test
     * @return bool
     */
    public static function filenameMatches($pattern, $filename)
    {
        $pattern = trim((string) $pattern);
        if ($pattern === '') {
            return true;
        }
        $regex = '/^'.str_replace(array('\*', '\?'), array('.*', '.'), preg_quote($pattern, '/')).'$/i';
        return (bool) preg_match($regex, (string) $filename);
    }

    /**
     * Test whether a single attachment matches an auto-import rule.
     * All conditions are ANDed; empty conditions act as wildcards.
     *
     * @param object $rule     Rule row (sender_contains, subject_contains, filename_pattern, formats)
     * @param string $from     Email sender (raw)
     * @param string $subject  Email subject
     * @param string $filename Attachment filename
     * @param string $ext      Attachment lowercase extension
     * @return bool
     */
    public static function attachmentMatchesRule($rule, $from, $subject, $filename, $ext)
    {
        $senderOk  = (empty($rule->sender_contains)  || stripos((string) $from, $rule->sender_contains) !== false);
        $subjectOk = (empty($rule->subject_contains) || stripos((string) $subject, $rule->subject_contains) !== false);
        if (!$senderOk || !$subjectOk) {
            return false;
        }
        if (!self::filenameMatches(isset($rule->filename_pattern) ? $rule->filename_pattern : '', $filename)) {
            return false;
        }
        $fmts = array_map('trim', explode(',', (string) $rule->formats));
        $extn = ($ext === 'jpeg') ? 'jpg' : strtolower($ext);
        return in_array($extn, $fmts, true);
    }

    /**
     * Find the first active rule matching an attachment, or null.
     *
     * @param array  $rules    Rules from loadEmailRules()
     * @param string $from     Email sender
     * @param string $subject  Email subject
     * @param string $filename Attachment filename
     * @param string $ext      Attachment lowercase extension
     * @return object|null Matching rule row or null
     */
    public static function matchAttachmentRule($rules, $from, $subject, $filename, $ext)
    {
        foreach ($rules as $rule) {
            if (self::attachmentMatchesRule($rule, $from, $subject, $filename, $ext)) {
                return $rule;
            }
        }
        return null;
    }

    /**
     * Blacklist matching. Like attachmentMatchesRule but format-agnostic when a
     * filename pattern is given: a blacklist rule with "Nombre del adjunto" filled
     * targets the attachment by name only (the format radio is irrelevant). When no
     * filename pattern is set, it falls back to matching by format.
     *
     * @param object $rule     Blacklist rule row
     * @param string $from     Email sender
     * @param string $subject  Email subject
     * @param string $filename Attachment filename
     * @param string $ext      Attachment lowercase extension
     * @return bool
     */
    public static function attachmentMatchesBlacklistRule($rule, $from, $subject, $filename, $ext)
    {
        $senderOk  = (empty($rule->sender_contains)  || stripos((string) $from, $rule->sender_contains) !== false);
        $subjectOk = (empty($rule->subject_contains) || stripos((string) $subject, $rule->subject_contains) !== false);
        if (!$senderOk || !$subjectOk) {
            return false;
        }

        $pattern = isset($rule->filename_pattern) ? trim((string) $rule->filename_pattern) : '';
        if ($pattern !== '') {
            // Filename-driven: match purely by the attachment name, ignore format.
            return self::filenameMatches($pattern, $filename);
        }

        // No filename pattern: fall back to format match (sender/subject already OK).
        $fmts = array_map('trim', explode(',', (string) $rule->formats));
        $extn = ($ext === 'jpeg') ? 'jpg' : strtolower($ext);
        return in_array($extn, $fmts, true);
    }

    /**
     * Find the first active blacklist rule matching an attachment, or null.
     *
     * @param array  $rules    Rules from loadBlacklistRules()
     * @param string $from     Email sender
     * @param string $subject  Email subject
     * @param string $filename Attachment filename
     * @param string $ext      Attachment lowercase extension
     * @return object|null Matching rule row or null
     */
    public static function matchBlacklistRule($rules, $from, $subject, $filename, $ext)
    {
        foreach ($rules as $rule) {
            if (self::attachmentMatchesBlacklistRule($rule, $from, $subject, $filename, $ext)) {
                return $rule;
            }
        }
        return null;
    }

    /**
     * Build a short, language-neutral label describing a rule (stored on the file, shown in the UI).
     *
     * @param object $rule Rule row
     * @return string
     */
    public static function ruleLabel($rule)
    {
        $bits = array();
        if (!empty($rule->sender_contains))  { $bits[] = $rule->sender_contains; }
        if (!empty($rule->subject_contains)) { $bits[] = '"'.$rule->subject_contains.'"'; }
        if (!empty($rule->filename_pattern)) { $bits[] = $rule->filename_pattern; }
        if (empty($bits)) {
            $bits[] = $rule->formats;
        }
        $label = implode(' / ', $bits);
        return dol_trunc($label, 250);
    }

    /**
     * Check whether a file has already been queued.
     * When $filehash is provided dedup is by SHA-256 hash + entity (content-based).
     * Falls back to original_filename + file_size + entity for legacy records without a hash.
     *
     * @param DoliDB $db       Database handler
     * @param string $filename Original filename (used for legacy fallback)
     * @param int    $filesize File size in bytes (used for legacy fallback)
     * @param int    $entity   Entity
     * @param string $filehash SHA-256 hex digest of file content (optional)
     * @return bool
     */
    public static function isAlreadyImported(DoliDB $db, $filename, $filesize, $entity, $filehash = '')
    {
        return (self::getImportedRowid($db, $filename, $filesize, $entity, $filehash) > 0);
    }

    /**
     * Find an already-queued file by original filename + size (ignoring hash).
     *
     * Used for DISPLAY in the email list, where the attachment content is not
     * downloaded so no hash is available. Unlike getImportedRowid()'s legacy
     * fallback, this matches records that DO have a hash (i.e. files queued from
     * email), which is what we need to flag an email as already imported/processed.
     * Returns the most recent match.
     *
     * @param DoliDB $db       Database handler
     * @param string $filename Original filename
     * @param int    $filesize File size in bytes
     * @param int    $entity   Entity
     * @return int Rowid (>0) or 0 if not found
     */
    public static function findRowidByNameSize(DoliDB $db, $filename, $filesize, $entity)
    {
        $base = "SELECT rowid FROM ".MAIN_DB_PREFIX."upinvoice_files";
        $base .= " WHERE entity = ".((int) $entity);
        $base .= " AND original_filename = '".$db->escape($filename)."'";

        // When a size is known (some IMAP modes expose it), prefer an exact name+size match.
        if ((int) $filesize > 0) {
            $sql = $base." AND file_size = ".((int) $filesize)." ORDER BY rowid DESC LIMIT 1";
            $resql = $db->query($sql);
            if ($resql) {
                if ($db->num_rows($resql) > 0) {
                    $obj = $db->fetch_object($resql);
                    $db->free($resql);
                    return (int) $obj->rowid;
                }
                $db->free($resql);
            }
        }

        // Fallback: match by filename only. Native IMAP listing (core getAttachments)
        // does not expose the attachment size, so size cannot be relied upon here.
        $sql = $base." ORDER BY rowid DESC LIMIT 1";
        $resql = $db->query($sql);
        if (!$resql) {
            return 0;
        }
        $rowid = 0;
        if ($db->num_rows($resql) > 0) {
            $obj = $db->fetch_object($resql);
            $rowid = (int) $obj->rowid;
        }
        $db->free($resql);
        return $rowid;
    }

    /**
     * Return the rowid of an already-queued file, or 0 if not found.
     * When $filehash is given, tries hash-based lookup first; then falls back to name+size.
     *
     * @param DoliDB $db       Database handler
     * @param string $filename Original filename
     * @param int    $filesize File size in bytes
     * @param int    $entity   Entity
     * @param string $filehash SHA-256 hex digest (optional)
     * @return int Rowid (>0) or 0 if not found
     */
    public static function getImportedRowid(DoliDB $db, $filename, $filesize, $entity, $filehash = '')
    {
        // --- 1. Hash-based lookup (exact content match) ---
        if (!empty($filehash)) {
            $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."upinvoice_files";
            $sql .= " WHERE entity = ".((int) $entity);
            $sql .= " AND file_hash = '".$db->escape($filehash)."'";
            $sql .= " LIMIT 1";
            $resql = $db->query($sql);
            if ($resql) {
                if ($db->num_rows($resql) > 0) {
                    $obj = $db->fetch_object($resql);
                    $db->free($resql);
                    return (int) $obj->rowid;
                }
                $db->free($resql);
            }
        }

        // --- 2. Legacy fallback: name + size (for records imported before hash tracking) ---
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."upinvoice_files";
        $sql .= " WHERE entity = ".((int) $entity);
        $sql .= " AND original_filename = '".$db->escape($filename)."'";
        $sql .= " AND file_size = ".((int) $filesize);
        $sql .= " AND file_hash IS NULL";
        $sql .= " LIMIT 1";
        $resql = $db->query($sql);
        if (!$resql) {
            return 0;
        }
        $rowid = 0;
        if ($db->num_rows($resql) > 0) {
            $obj = $db->fetch_object($resql);
            $rowid = (int) $obj->rowid;
        }
        $db->free($resql);
        return $rowid;
    }

    /**
     * Save an attachment to disk and queue it as a pending upinvoice file.
     *
     * @param DoliDB   $db          Database handler
     * @param User     $user        Acting user
     * @param string   $filename    Original filename (used for dedup and display)
     * @param string   $content     Raw file content (binary string)
     * @param int      $entity      Entity to store file under
     * @param array    $allowedext  Allowed extension => MIME map (subset of EMAIL_ALLOWED_MIMES)
     * @param string   $source      Source label ('email', 'web', …)
     * @param string   $ruleLabel   Snapshot of the auto-import rule that queued this file ('' = manual)
     * @param string   &$reason     Out: human-readable skip reason when return=0
     * @param string   &$error      Out: error message when return<0
     * @return int >0 rowid inserted, 0 skipped (dedup/size/ext), <0 error
     */
    public static function queueAttachment(DoliDB $db, User $user, $filename, $content, $entity, array $allowedext, $source, $ruleLabel = '', &$reason = '', &$error = '')
    {
        global $conf;

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (empty($ext) || !array_key_exists($ext, $allowedext)) {
            $reason = 'ext_not_allowed:'.$ext;
            return 0;
        }

        $filesize = strlen($content);
        $maxsize = getDolGlobalInt('UPINVOICE_EMAIL_MAX_FILE_SIZE', 10485760);
        if ($filesize > $maxsize) {
            $reason = 'too_large:'.$filesize;
            return 0;
        }

        $fileHash = hash('sha256', $content);

        if (self::isAlreadyImported($db, $filename, $filesize, $entity, $fileHash)) {
            $reason = 'duplicate';
            return 0;
        }

        $upload_dir = DOL_DATA_ROOT.'/upinvoice/temp';
        dol_mkdir($upload_dir);

        $baseName = trim(pathinfo(dol_sanitizeFileName($filename), PATHINFO_FILENAME));
        if (empty($baseName)) {
            $baseName = 'file';
        }
        $storedExt = ($ext === 'jpeg') ? 'jpg' : $ext;
        $uniqueFilename = $baseName.'_'.dol_print_date(dol_now(), 'dayhourlog').'_'.substr(md5(uniqid()), 0, 8).'.'.$storedExt;
        $dest_file = $upload_dir.'/'.$uniqueFilename;

        if (file_put_contents($dest_file, $content) === false) {
            $error = 'write_failed:'.$dest_file;
            return -1;
        }
        dolChmod($dest_file);

        $savedEntity = $conf->entity;
        $conf->entity = $entity;

        $obj = new self($db);
        $obj->file_path         = $dest_file;
        $obj->original_filename = $filename;
        $obj->file_size         = $filesize;
        $obj->file_type         = $allowedext[$ext];
        $obj->file_hash         = $fileHash;
        $obj->import_step       = 1;
        $obj->status            = 0;
        $obj->processing        = 0;
        $obj->source            = $source;
        $obj->import_rule_label = ($ruleLabel !== '') ? $ruleLabel : null;

        $rowid = $obj->create($user, 1);
        $conf->entity = $savedEntity;

        if ($rowid <= 0) {
            @unlink($dest_file);
            $error = 'db_create_failed:'.$obj->error;
            return -1;
        }

        dol_syslog("UpInvoiceFiles::queueAttachment rowid=".$rowid." file=".$filename." source=".$source, LOG_INFO);
        return $rowid;
    }
}