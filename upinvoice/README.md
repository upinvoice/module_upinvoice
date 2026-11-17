# UpInvoice Module for Dolibarr

AI document capture for Dolibarr (beyond OCR). The module reads **supplier invoices**, **supplier orders** (purchase orders) and **customer orders** from PDFs or images with the [UpInvoice](https://upinvoice.eu) AI API, finds or creates the third party, links each line to your Dolibarr products, and creates the document in Dolibarr. Documents are uploaded by hand; supplier invoices can also be collected automatically from an email inbox.

| Document | Created in Dolibarr as | What you can upload | Since |
|----------|------------------------|---------------------|-------|
| Supplier invoice | Supplier invoice | Invoice PDF, scan, photo or receipt | 1.0 |
| Supplier order | Supplier order (purchase order) | Your own purchase order or the supplier's order confirmation | 1.5.0 |
| Customer order | Customer order (sales order) | The customer's purchase order, your order form or confirmation, a handwritten note or a photo | 1.6.0 |

No templates are needed: the AI reads layouts it has never seen, phone photos and handwriting. Each document uses one UpInvoice credit; there is a free plan with 10 documents.

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

The setup page shows your account status, active plan and remaining credits once a valid key is configured. Credits are shared by all document types. The API URL (`https://upinvoice.eu/api/process-invoice`) is fixed and does not need to be changed.

### Email intake (optional)

Invoices can be collected automatically from an email mailbox instead of being uploaded by hand:

1. Enable Dolibarr's **EmailCollector** module.
2. On the UpInvoice setup page, turn on **Email intake**. If no collector exists yet, use the **Create collector** button to generate one preconfigured with the UpInvoice import operation, then fill in your IMAP host/login and enable it.
3. (Optional) Turn on **Automatic AI processing** so a scheduled cron job extracts data from incoming email invoices without manual action. Make sure the Dolibarr cron is running.

The live mailbox tab uses the PHP `imap` extension (or `MAIN_IMAP_USE_PHPIMAP`). Email rules and a blacklist let you control which messages and attachments are imported.

### Supplier orders and customer orders (optional)

On the setup page, turn on **Supplier orders** (needs Dolibarr's supplier orders module) and/or **Customer orders** (needs Dolibarr's orders module). A tab for each appears next to "Supplier invoices" on the upload page, and an UpInvoice entry is added to the Commercial > Supplier orders / Customer orders menus. After updating the module, disable and enable it again to add the new menu entries and database column.

## Usage

After installation, you'll see a new "UpInvoice" menu in your Dolibarr top menu.

1. **Add files**: upload PDFs or images in the tab of the document type, or let them arrive via email intake.
2. **Third party**: the supplier or customer is found (by tax ID or name) or created. If the document was issued by your own company, the other party is used.
3. **Review and create**: check the header and the lines (products matched by supplier code `ref_fourn`, customer reference, ref, `ref_ext` or label) and create the document as a draft or validated, with the original file in its Documents tab. Supplier invoices can be paid on creation.

Before creating, the module warns when the document does not look like the chosen type (a quotation, delivery note or invoice uploaded as an order), when its currency differs from Dolibarr's, or when the third party already has an order with the same reference. Customer orders without prices take the Dolibarr sale price of each product for that customer.

The pending-files list refreshes automatically and offers search, status filtering, sorting, queue pause/resume, and retry of failed files. Stuck files are detected automatically (threshold configurable via the `UPINVOICE_STUCK_SECONDS` constant, default 180 s).

## Requirements

- Dolibarr 16.0 or higher (Dolicloud included)
- PHP 7.0 or higher
- Suppliers (`modFournisseur`) and Invoices (`modFacture`) modules enabled
- For supplier orders: the supplier orders module; for customer orders: the orders module (`modCommande`)
- A valid UpInvoice API key
- For email intake: the EmailCollector module, the Dolibarr cron, and the PHP `imap` extension

## Troubleshooting

If the module doesn't appear in the modules list:
- Make sure the files are in the correct directory structure
- Check that file permissions are correct
- Verify that the Dolibarr version is compatible
- Check the Dolibarr logs for any errors

If documents are not processed:
- Confirm a valid API key is set and your plan has remaining credits (shown on the setup page)
- For email intake, check that the EmailCollector is enabled and the cron job is running

## Support

For support and questions about this module, please contact:
- Email: info@upinvoice.eu
- Website: https://upinvoice.eu

## License

This module is licensed under the GNU General Public License v3.0.
