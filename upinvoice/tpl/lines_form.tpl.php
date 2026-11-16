<?php
/* Copyright (C) 2023-2025
 * Licensed under the GNU General Public License version 3
 */

/**
 * Lines table of a document (invoice, order): product type and product (Select2), description,
 * qty, unit price, discount, totals; "Add line" button and the line_count hidden field.
 * Behaviour (totals, VAT method, reconciliation, add/delete line) is in js/upinvoice-lines.js.
 *
 * Expects: $langs, $upinv_lines (lines from the API, already linked by UpInvoiceProductMatcher).
 * Sets:    $line_count (number of lines printed).
 */
if (!defined('DOL_VERSION')) {
    exit;
}

// Invoice lines card
print '<div class="upinvoiceimport-invoice-card">';
print '<div class="upinvoiceimport-card-body">';

// Invoice lines table
print '<table class="noborder centpercent" id="invoice_lines_table">';
print '<tr class="liste_titre">';
print '<th class="linecoldescription minwidth300imp">' . $langs->trans("Description") . '</th>';
print '<th class="center">' . $langs->trans("Qty") . '</th>';
print '<th class="center">' . $langs->trans("PriceUHT") . '</th>';
print '<th class="center">' . $langs->trans("DiscountShort") . '</th>';
print '<th class="right">' . $langs->trans("TaxBaseShort") . '</th>';
print '<th class="center">% ' . $langs->trans("VAT") . '</th>';
print '<th class="right">' . $langs->trans("VAT") . '</th>';
print '<th class="right">' . $langs->trans("TotalTTC") . '</th>';
print '<th class="center"></th>';
print '</tr>';

// Lines from JSON data
$line_count = 0;
if (!empty($upinv_lines) && is_array($upinv_lines)) {
    foreach ($upinv_lines as $line) {
        $line_product_type = isset($line['product_type']) ? $line['product_type'] : 0; // Default to product=0
        print '<tr class="invoice-line" id="line_row_'.$line_count.'">';

        // Description + Product selection/info
        print '<td>';

        // Flag "extra": la línea suma solo al total del documento, no a la base/IVA extraídos.
        // Se usa únicamente para la reconciliación de avisos (no altera el cálculo de la factura).
        print '<input type="hidden" name="line_extra_'.$line_count.'" class="lineextra" value="'.(!empty($line['extra']) ? 1 : 0).'">';

        // --- START: Display Matched Product Info or Product Selector ---
        // Check if product matching was found
        if (!empty($line['fk_product'])) {
            // Product type selector - visible incluso con producto seleccionado
            print '<div class="product-type-selector" style="margin-bottom:5px;">';
            print '<select id="line_type_' . $line_count . '" name="line_type_' . $line_count . '" class="flat" style="padding: 6px 5px; height: auto;">';
            print '<option value="0"' . ($line_product_type == 0 ? ' selected' : '') . '>' . $langs->trans("Product") . '</option>';
            print '<option value="1"' . ($line_product_type == 1 ? ' selected' : '') . '>' . $langs->trans("Service") . '</option>';
            print '</select>';
            print '</div>';
            
            // Hidden field for product ID
            print '<input type="hidden" name="line_fk_product_'.$line_count.'" value="'.intval($line['fk_product']).'">';
            
            // Select2 producto con valor preseleccionado
            print '<div class="product-select-container" style="margin-bottom:5px;">';
            print '<select id="line_product_' . $line_count . '" name="line_product_' . $line_count . '" class="product-select2" data-line="' . $line_count . '" style="width: 100%;">';
            // Se añade una opción con el valor preseleccionado para inicializar Select2 con ese valor.
            // ref/label/type van en data-* para que la selección se pinte como badge (templateSelection)
            print '<option value="'.intval($line['fk_product']).'" selected="selected"';
            print ' data-ref="'.dol_escape_htmltag($line['product_ref'] ?? '').'"';
            print ' data-label="'.dol_escape_htmltag($line['product_label'] ?? '').'"';
            print ' data-type="'.intval($line_product_type).'">';
            print dol_escape_htmltag($line['product_ref'] . ' - ' . $line['product_label']) . '</option>';
            print '</select>';
            print '</div>';
        } else {
            // --- START: Product Type Selector and Product Select2 for non-matched/new lines ---
            print '<div class="product-type-selector" style="margin-bottom:5px;">';
            print '<select id="line_type_' . $line_count . '" name="line_type_' . $line_count . '" class="flat" style="padding: 6px 5px; height: auto;">';
            print '<option value="0"' . ($line_product_type == 0 ? ' selected' : '') . '>' . $langs->trans("Product") . '</option>';
            print '<option value="1"' . ($line_product_type == 1 ? ' selected' : '') . '>' . $langs->trans("Service") . '</option>';
            print '</select>';
            print '</div>';
            
            // Select2 for product selection
            print '<div class="product-select-container" style="margin-bottom:5px;">';
            print '<select id="line_product_' . $line_count . '" name="line_product_' . $line_count . '" class="product-select2" data-line="' . $line_count . '" style="width: 100%;">';
            print '<option value=""></option>'; // Empty option for placeholder
            print '</select>';
            print '</div>';
            
            // Hidden field for product ID (0 for non-matched products)
            print '<input type="hidden" name="line_fk_product_'.$line_count.'" value="0">';
        }
         // --- END: Display Matched Product Info or Product Selector ---

        print '<textarea name="line_desc_'.$line_count.'" class="flat width100p" style="margin-top: 5px; width: 98%">'.dol_escape_htmltag($line['product_desc'] ?? '').'</textarea>';
        print '</td>';

        // Quantity
        print '<td class="center">';
        print '<input type="text" size="5" name="line_qty_'.$line_count.'" value="'.(isset($line['qty']) ? $line['qty'] : 1).'" class="flat qtyline right" onchange="updateLineTotals('.$line_count.')">';
        print '</td>';

        // Unit price
        print '<td class="center">';
        print '<input type="text" size="8" name="line_pu_ht_'.$line_count.'" value="'.price(isset($line['pu_ht']) ? $line['pu_ht'] : 0).'" class="flat puhline right" onchange="updateLineTotals('.$line_count.')">';
        print '</td>';

        // Discount
        print '<td class="center">';
        print '<input type="text" size="5" name="line_remise_percent_'.$line_count.'" value="'.price(isset($line['remise_percent']) ? $line['remise_percent'] : 0).'" class="flat remisepercentline right" onchange="updateLineTotals('.$line_count.')">';
        print '</td>';

        // Total HT (Base)
        print '<td class="right">';
        print '<input type="text" size="8" name="line_total_ht_'.$line_count.'" value="'.price(isset($line['total_ht']) ? $line['total_ht'] : 0).'" class="flat totalhtline right" readonly>';
        print '</td>';

        // VAT rate
        print '<td class="center">';
        print '<input type="text" size="5" name="line_tva_tx_'.$line_count.'" value="'.price(isset($line['tva_tx']) ? $line['tva_tx'] : 0).'" class="flat tvaline right" onchange="updateLineTotals('.$line_count.')">';
        print '</td>';

        // Total VAT
        print '<td class="right">';
        print '<input type="text" size="8" name="line_total_tva_'.$line_count.'" value="'.price(isset($line['total_tva']) ? $line['total_tva'] : 0).'" class="flat totalvaline right">';
        print '</td>';

        // Total TTC
        print '<td class="right">';
        print '<input type="text" size="8" name="line_total_ttc_'.$line_count.'" value="'.price(isset($line['total_ttc']) ? $line['total_ttc'] : 0).'" class="flat totalttcline right">';
        print '</td>';

        // Actions
        print '<td class="center">';
        print '<a href="#" class="delete-line" data-line="'.$line_count.'"><i class="fas fa-trash"></i></a>';
        print '</td>';

        print '</tr>';
        $line_count++;
    }
}

print '</table>';

// Add line button
print '<div class="invoice-actions">';
print '<a href="#" class="butAction upinv-btn-blue upinv-btn-sm" id="add-line-btn">' . $langs->trans("AddLine") . '</a>';
print '</div>';

// Input to keep track of line count
print '<input type="hidden" name="line_count" id="line_count" value="'.$line_count.'">';

print '</div>'; // Close card body
print '</div>'; // Close card
