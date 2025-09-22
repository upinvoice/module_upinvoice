# UpInvoice Module for Dolibarr

This module allows uploading, validating and registering supplier invoices in a three-step process using the UpInvoice API.

## Installation

Prerequisites: You must have the Dolibarr ERP CRM software installed. You can down it from [Dolistore.org](https://www.dolibarr.org).
You can also get a ready to use instance in the cloud from htts://saas.dolibarr.org


### From the ZIP file and GUI interface

If the module is a ready to deploy zip file, so with a name module_xxx-version.zip (like when downloading it from a market place like [Dolistore](https://www.dolistore.com)),
go into menu ```Home - Setup - Modules - Deploy external module``` and upload the zip file.

Note: If this screen tell you that there is no "custom" directory, check that your setup is correct:

<!--

- In your Dolibarr installation directory, edit the ```htdocs/conf/conf.php``` file and check that following lines are not commented:

    ```php
    //$dolibarr_main_url_root_alt ...
    //$dolibarr_main_document_root_alt ...
    ```

- Uncomment them if necessary (delete the leading ```//```) and assign a sensible value according to your Dolibarr installation

    For example :

    - UNIX:
        ```php
        $dolibarr_main_url_root_alt = '/custom';
        $dolibarr_main_document_root_alt = '/var/www/Dolibarr/htdocs/custom';
        ```

    - Windows:
        ```php
        $dolibarr_main_url_root_alt = '/custom';
        $dolibarr_main_document_root_alt = 'C:/My Web Sites/Dolibarr/htdocs/custom';
        ```
-->

## Usage

After installation, you'll see a new "UpInvoice" menu in your Dolibarr top menu.

1. **Step 1**: Upload supplier invoice files (PDF or images)
2. **Step 2**: Validate or create the supplier
3. **Step 3**: Validate and register the invoice

## Requirements

- Dolibarr 11.0 or higher
- PHP 7.0 or higher
- Supplier and Invoice modules enabled

## Troubleshooting

If the module doesn't appear in the modules list:
- Make sure the files are in the correct directory structure
- Check that file permissions are correct
- Verify that the Dolibarr version is compatible
- Check the Dolibarr logs for any errors

## License

This module is licensed under the GNU General Public License v3.0.
