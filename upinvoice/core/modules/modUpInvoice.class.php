<?php

/* Copyright (C) 2023
 * Licensed under the GNU General Public License version 3
 */

/**
 *  \defgroup   upinvoiceimport     Module UpInvoice Import
 *  \brief      Module to import supplier invoices using UpInvoice API
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 *  Class to describe and enable module UpInvoice Import
 */
class modUpInvoice extends DolibarrModules
{
    /**
     * Constructor.
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        global $langs, $conf;

        $this->db = $db;
        $this->numero = 173361;

        $this->family = "financial";
        $this->module_position = 500;
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = "Module for supplier invoice import using UpInvoice api REST";
        $this->descriptionlong = "This module allows to upload, validate and register supplier invoices in a three steps process";
        $this->editor_name = "UpInvoice.eu";
        $this->editor_url = "";
        $this->version = '1.2.0';
        $this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
        $this->picto = 'bill';

        // Dependencies
        $this->depends = array('modFournisseur', 'modFacture');
        $this->requiredby = array();
        $this->conflictwith = array();
        $this->phpmin = array(7, 0);
        $this->need_dolibarr_version = array(11, 0);
        $this->langfiles = array("upinvoice@upinvoice");

        // Constants
        $this->const = array(
            0 => array(
                'UPINVOICE_API_URL',
                'chaine',
                'https://upinvoice.eu/api/process-invoice',
                'URL for UpInvoice API',
                0,
                'current',
                1
            ),
            1 => array(
                'UPINVOICE_API_KEY',
                'chaine',
                '',
                'API Key for UpInvoice',
                0,
                'current',
                1
            )
        );

        // Config page
        $this->config_page_url = array("setup.php@upinvoice");

        // Tabs
        $this->tabs = array();

        // Directories for module
        $this->dirs = array(
            DOL_DATA_ROOT . '/upinvoice/temp'
        );

        // Document access configuration
        $this->module_parts = array(
            'dir' => array('output' => 'upinvoice'),
            'modulepart' => array('upinvoice' => 'upinvoice')
        );

        $this->picto = 'upinvoice@upinvoice';

        // Menus
        $this->menu = array();
        $r = 0;

        // Top menu entry
        $this->menu[$r] = array(
            'fk_menu' => 'fk_mainmenu=billing,fk_leftmenu=suppliers_bills',
            'type' => 'top',
            'titre' => 'UpInvoice',
            'prefix' => img_picto('', 'fa-cloud-upload-alt', 'class="fa-fw pictofixedwidth"'),
            'mainmenu' => 'billing',
            'leftmenu' => 'suppliers_bills',
            'url' => '/upinvoice/upload.php',
            'langs' => 'upinvoice@upinvoice',
            'position' => 100,
            'enabled' => '$conf->upinvoice->enabled',
            'perms' => '1',
            'target' => '',
            'user' => 0
        );
        $r++;

        /* // Left menu entry - Step 2: Supplier Validation
        $this->menu[$r] = array(
            'fk_menu' => 'fk_mainmenu=upinvoice',
            'type' => 'left',
            'titre' => 'SupplierValidation',
            'mainmenu' => 'billing',
            'leftmenu' => 'suppliers_bills',
            'url' => '/upinvoice/supplier.php',
            'langs' => 'upinvoice@upinvoice',
            'position' => 102,
            'enabled' => '$conf->upinvoice->enabled',
            'perms' => '1',
            'target' => '',
            'user' => 0
        );
        $r++;

        // Left menu entry - Step 3: Invoice Validation
        $this->menu[$r] = array(
            'fk_menu' => 'fk_mainmenu=upinvoice',
            'type' => 'left',
            'titre' => 'InvoiceValidation',
            'mainmenu' => 'billing',
            'leftmenu' => 'suppliers_bills',
            'url' => '/upinvoice/invoice.php',
            'langs' => 'upinvoice@upinvoice',
            'position' => 103,
            'enabled' => '$conf->upinvoice->enabled',
            'perms' => '1',
            'target' => '',
            'user' => 0
        );
        $r++; */
    }

    /**
     *  Function called when module is enabled.
     *  The init function add constants, boxes, permissions and menus (defined in constructor) into Dolibarr database.
     *  It also creates data directories
     *
     *  @param      string  $options    Options when enabling module
     *  @return     int                 1 if OK, 0 if KO
     */
    public function init($options = '')
    {
        global $conf;

        $sql = array();

        $result = $this->_load_tables('/upinvoice/sql/');

        // Create temp directory if it does not exist
        $tempdir = DOL_DATA_ROOT . '/upinvoice/temp';
        if (!is_dir($tempdir)) {
            dol_mkdir($tempdir);
        }

        // Create symbolic link for documents
        $docdir = DOL_DOCUMENT_ROOT . '/upinvoice';
        if (!is_link($docdir)) {
            @symlink(DOL_DATA_ROOT . '/upinvoice', $docdir);
        }

        return $this->_init($sql, $options);
    }

    /**
     *  Function called when module is disabled.
     *
     *  @param      string  $options    Options when disabling module
     *  @return     int                 1 if OK, 0 if KO
     */
    public function remove($options = '')
    {
        $sql = array();

        // Remove symbolic link for documents
        $docdir = DOL_DOCUMENT_ROOT . '/upinvoice';
        if (is_link($docdir)) {
            @unlink($docdir);
        }

        return $this->_remove($sql, $options);
    }
}
