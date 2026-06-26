# UpInvoice Module for Dolibarr

This module lets you capture supplier invoices (PDF or images), extract their data automatically with the UpInvoice AI API, validate the supplier, and register the invoice in Dolibarr. Invoices can be added manually by upload or collected automatically from an email inbox.

## Installation

Prerequisites: You must have the Dolibarr ERP CRM software installed. You can download it from [Dolibarr.org](https://www.dolibarr.org).
You can also get a ready-to-use instance in the cloud from https://saas.dolibarr.org

### From the ZIP file and GUI interface

If the module is a ready-to-deploy zip file, with a name like `module_xxx-version.zip` (as when downloading it from a marketplace like [Dolistore](https://www.dolistore.com)),
go into menu `Home - Setup - Modules - Deploy external module` and upload the zip file.

Note: If this screen tells you that there is no "custom" directory, check that your setup is correct:

<!--

- In your Dolibarr installation directory, edit the `htdocs/conf/conf.php` file and check that the following lines are not commented:

    ```php
    //$dolibarr_main_url_root_alt ...
    //$dolibarr_main_document_root_alt ...
    ```

- Uncomment them if necessary (delete the leading `//`) and assign a sensible value according to your Dolibarr installation

    For example:

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

## Configuration

After enabling the module, open its setup page (`Home - Setup - Modules - UpInvoice - Settings`).

### API key (required)

The module sends invoices to the UpInvoice API for AI extraction, so an API key is required.

1. Sign in at [upinvoice.eu](https://upinvoice.eu) and generate a key at https://upinvoice.eu/api/tokens
2. Paste it into the **API Key** field on the setup page and save.

The setup page shows your account status, active plan and remaining credits once a valid key is configured. The API URL (`https://upinvoice.eu/api/process-invoice`) is fixed and does not need to be changed.

### Email intake (optional)

Invoices can be collected automatically from an email mailbox instead of being uploaded by hand:

1. Enable Dolibarr's **EmailCollector** module.
2. On the UpInvoice setup page, turn on **Email intake**. If no collector exists yet, use the **Create collector** button to generate one preconfigured with the UpInvoice import operation, then fill in your IMAP host/login and enable it.
3. (Optional) Turn on **Automatic AI processing** so a scheduled cron job extracts data from incoming email invoices without manual action. Make sure the Dolibarr cron is running.

The live mailbox tab uses the PHP `imap` extension (or `MAIN_IMAP_USE_PHPIMAP`). Email rules and a blacklist let you control which messages and attachments are imported.

## Usage

After installation, you'll see a new "UpInvoice" menu in your Dolibarr top menu.

1. **Step 1**: Add supplier invoice files (PDF or images) by upload, or let them arrive via email intake.
2. **Step 2**: Validate or create the supplier.
3. **Step 3**: Validate and register the invoice.

The pending-files list refreshes automatically and offers search, status filtering, sorting, queue pause/resume, and retry of failed files. Stuck files are detected automatically (threshold configurable via the `UPINVOICE_STUCK_SECONDS` constant, default 180 s).

## Requirements

- Dolibarr 11.0 or higher
- PHP 7.0 or higher
- Suppliers (`modFournisseur`) and Invoices (`modFacture`) modules enabled
- A valid UpInvoice API key
- For email intake: the EmailCollector module, the Dolibarr cron, and the PHP `imap` extension

## Troubleshooting

If the module doesn't appear in the modules list:
- Make sure the files are in the correct directory structure
- Check that file permissions are correct
- Verify that the Dolibarr version is compatible
- Check the Dolibarr logs for any errors

If invoices are not processed:
- Confirm a valid API key is set and your plan has remaining credits (shown on the setup page)
- For email intake, check that the EmailCollector is enabled and the cron job is running

## Support

For support and questions about this module, please contact:
- Email: info@upinvoice.eu
- Website: https://upinvoice.eu

## License

This module is licensed under the GNU General Public License v3.0.
