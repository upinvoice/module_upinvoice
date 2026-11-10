1.4.6 (2026-11-10)
- Product matching by reference looks at the product ref, its external ref (ref_ext) and its label, in that order of priority (exact match before normalized match). Before, only the ref was searched
- Reading the candidates goes page by page (20 per page, up to 100), so a correct product is not lost when many products share part of the reference
- When several products tie on the best match, the one with the same type (product/service) as the invoice line is chosen; if the tie remains, the line is not linked and the product is chosen by hand. A single match is linked whatever its type (the line takes the product's type)
- Normalized reference comparison uses multibyte uppercase

1.4.5 (2026-11-02)
- An invoice is never sent twice to the UpInvoice API: processing now takes an atomic lease with an owner token, shared by the web page and the cron, so two tabs, two users or the cron cannot process the same file at the same time. A file already processed with data, or with a supplier or invoice already set, is refused by the server
- A late result (lease expired and taken by another worker, or file deleted) is discarded instead of overwriting the file, and processing never overwrites the supplier or invoice chosen meanwhile
- The API call has a timeout below the lease (UPINVOICE_STUCK_SECONDS, minimum 60), so a retry can never overlap a request still waiting for the API
- Idempotency-Key sent to the API, stable across retries of the same attempt: a retry after a lost response is replayed by the API without charging credits again. When the API is still processing (409) the file shows it and the attempt is not counted as a failure
- Update: disable and enable the module again after updating, to add the new columns of llx_upinvoice_files. If this is forgotten, the upload page and the module setup now show a warning naming the missing columns, with a link to the module list, instead of an empty file list

1.4.4 (2026-10-23)
- Fix: the API key and the other module settings were deleted when the module was disabled (the usual step when updating it), so the key had to be entered again after each update

1.4.3 (2026-10-23)
- Register payment: new box next to the invoice header to pay the invoice while creating it (payment date, optional reference). The full amount is paid with the payment mode and bank account of the invoice, which is then created validated and closed as paid; if the payment fails, nothing is created
- Fix: when a product is picked by hand on a line, its price and VAT rate are compared with the purchase price registered for the invoice supplier (Products > Purchase prices) instead of the product sale price and sale VAT. If that supplier has no purchase price for the product, the invoice price and VAT are kept and the search shows the product cost as information
- Fix: price and VAT confirmations compare localized values correctly ("4,50" was read as 4)

1.4.2 (2026-10-22)
- Product matching by reference tries every reference the API sends for a line (product_refs: up to 3, e.g. article code and supplier item number), not only product_ref
- References are compared on letters and digits only, ignoring accents and case: "SWITCH'GSMIP4G12V" on the invoice now matches the product Dolibarr stores as "SWITCH_GSMIP4G12V" (Dolibarr turns spaces, quotes, "/", "," etc. into "_" when creating a product)
- The invoice line keeps the reference that matched
- With the current UpInvoice API, invoices sent from the module always keep their detailed lines (they are no longer grouped into a single line on accounts without a subscription), and eco-tax or fee sub-lines no longer inherit the reference of the product above

1.4.1 (2026-10-16)
- Credits bar: "Add credits" button when credits are low or exhausted, linking to the UpInvoice plans page (shown only with API versions that send the link)
- Credits bar uses the server-side low-credits flag (plan and bonus balances) instead of a fixed 20% when available
- No-plan accounts: the bar shows "No credits available" with the link instead of an empty dash
- Invoice lines: the matched/selected product is shown as a badge (ref + label) inside the product search, with a red "x" to remove it; the separate "Matching product" label under the selector is gone
- Fix: long product names widened the description column and pushed the lines table out of the right panel
- Invoice lines on a narrow right column: numeric fields wrap into two rows (qty, unit price, discount, base / VAT %, VAT, total), with a two-row header so every label sits above its field; below 520 px the description takes the full width
- Fix: when the layout stacks (window under 1100 px) the right column no longer collapses to zero width

1.4.0 (2026-07-07)
- Cron auto-retries transient AI failures ("success" with no data) up to UPINVOICE_AI_MAX_RETRIES times (default 2); new ai_retries column (migration llx_upinvoice_files-add-ai_retries.sql)
- Raw API response logged via dol_syslog when extraction returns no data, and on every failed attempt
- Fix: email rules with spaces in the subject never matched MIME Q-encoded subjects (underscores left undecoded); matching now folds _ to space on both sides
- Pagination on the finished-files list (server-side, respects conf->liste_limit)
- Setup UX: email intake toggle disabled with a call-to-action when the EmailCollector module is off; auto AI processing toggle requires intake enabled (the cron only processes email files)
- Cron job eligibility test now also requires EmailCollector + intake enabled (applies on module re-activation); fixed misleading job comment
- Fix: double-encoded HTML entities in the "Modules" link of setup warnings
- Reconciliation warning: "extra" lines only add to the document total, not to the extracted base/VAT (no more false Base mismatch)
- README: configuration section (API key) and email intake documentation

1.3.0 (2026-06-19)
- Pending files toolbar: search, status filter, sort, pause/resume queue, retry errors, refresh
- Auto-polling every 20 s keeps the list up to date without manual reload
- Honest indeterminate progress bar replaces simulated percentage
- Stacked, compact toast notifications (append, not replace)
- Optimistic fade-out on delete; card slides up on success, restores on failure
- Credit-consumed warning when deleting an already-processed file
- Removed bulk-selection bar (processing is fully automatic)
- Stuck-file detection (configurable via UPINVOICE_STUCK_SECONDS, default 180 s)
- Fix: modal confirm button was closing dialog before resolving, silently cancelling the action
- Fix: Gemini 503 responses no longer overwrite valid api_json with empty data
- Fix: missing es_ES translations for Saving and Supplier keys

1.2.0 (2025-11-08)
- Check max_input_vars PHP configuration to prevent data loss on large invoices
- Improved amount format handling with better validation and normalization
- Support for line-level discount percentage in invoice import
- Enhanced error messages for configuration issues

1.1.0 (2025-09-22)
- Fix: qty number type, upload folder, payment method autoselect
- Check tva ids (intra, siren, siret,..)
- Updates and translations
- Langs and fix file viewer for file names with '#' chars
- Fix translations on ajax files and CSRF check problems
- Fix for reinstall
- Alert if not api key
- Update README.md improvements

1.0.0 (2025-03-29)
- Initial release
- Module for supplier invoice import using UpInvoice API REST
- Three-step process: upload, validate supplier, validate invoice
- Multi-language support (EN, ES, FR, DE, IT)
- Integration with Dolibarr supplier and invoice modules
