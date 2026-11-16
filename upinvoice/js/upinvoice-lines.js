/* Copyright (C) 2023-2025
 * Licensed under the GNU General Public License version 3
 *
 * Lines table of a document (tpl/lines_form.tpl.php): line totals, document totals with the VAT
 * method (Method 1 / Method 2), reconciliation with the extracted totals, add/delete line.
 *
 * Needs, defined by the page before document ready: upinvoiceimport_langs, upinvoiceExtracted,
 * upinvoiceMethodLangs. Uses #total_ht, #total_tva, #total_ttc, #localtax2 (optional),
 * #calc_method, #upinv-reconcile-warning, #max_input_vars and #line_count.
 */

$(document).ready(function() {
    // Add line button
    $("#add-line-btn").click(function(e) {
        e.preventDefault();
        addNewLine();
    });

    // Delete line button
    $(document).on('click', '.delete-line', function(e) {
        e.preventDefault();
        var lineNum = $(this).data('line');
        deleteLine(lineNum);
    });

    // Initialize line totals (line_count holds the number of lines printed by the server)
    var upinvInitialLines = parseInt($('#line_count').val()) || 0;
    for (var i = 0; i < upinvInitialLines; i++) {
        updateLineTotals(i);
    }
    
    // Recalc method buttons (Method 1 / Method 2)
    $('.upinv-method-btn').on('click', function(e) {
        e.preventDefault();
        upinvApplyMethod(parseInt($(this).data('method')) || 1);
    });

    // Auto-pick the VAT method closest to the extracted document totals (also renders totals + reconcile)
    upinvAutoPickMethod();
    max_input_vars_check();
});

function max_input_vars_check() {
    var maxInputVars = parseInt($('#max_input_vars').val());
    // Inputs of the form that holds the lines table (form#invoice_form on invoice.php)
    var inputcount = $('#line_count').closest('form').find(':input').length;
    // Estimate total input vars: each line has ~9 inputs + other form inputs (~20)
    var estimatedTotalInputs = inputcount;
    if (estimatedTotalInputs >= maxInputVars) {
        alert('Warning: The number of input fields (' + estimatedTotalInputs + ') exceeds or is close to the PHP max_input_vars limit (' + maxInputVars + '). Please reduce the number of lines or increase the max_input_vars setting in your PHP configuration to avoid data loss.');
    }
    return false;
}

function parseLocalizedFloat(value) {
    if (typeof value !== 'string') return parseFloat(value) || 0;

    value = value.trim();

    // Elimina los espacios
    value = value.replace(/\s+/g, '');

    // Detecta si el separador decimal es una coma o un punto
    // (si hay coma y punto, el que va más a la derecha es el decimal)
    const hasComma = value.includes(',');
    const hasDot = value.includes('.');

    if (hasComma && hasDot) {
        if (value.lastIndexOf(',') > value.lastIndexOf('.')) {
            // "3.000,50" → eliminar puntos, reemplazar coma por punto
            value = value.replace(/\./g, '').replace(',', '.');
        } else {
            // "3,000.50" → eliminar comas (miles)
            value = value.replace(/,/g, '');
        }
    } else if (hasComma) {
        // "3000,50" o "3 000,50" → reemplazar coma por punto
        value = value.replace(',', '.');
    } else {
        // "3.000" debe ser interpretado como 3.0 ó 2.550 como 2.55
        if(hasDot) {
            value = value;
        } else {
            // "3000" o "3 000" sin coma decimal → eliminar separadores de miles
            value = value.replace(/\.(?=\d{3}($|[^\d]))/g, '');
        }
    }

    return parseFloat(value) || 0;
}

// Update line totals
function updateLineTotals(lineNum) {
    var qty = parseLocalizedFloat($("input[name='line_qty_" + lineNum + "']").val()) || 0;
    var pu_ht = parseLocalizedFloat($("input[name='line_pu_ht_" + lineNum + "']").val()) || 0;
    var tva_tx = parseLocalizedFloat($("input[name='line_tva_tx_" + lineNum + "']").val()) || 0;
    var remise_percent = parseLocalizedFloat($("input[name='line_remise_percent_" + lineNum + "']").val()) || 0;

    var total_ht = qty * pu_ht * (1 - remise_percent / 100);
    var total_tva = total_ht * (tva_tx / 100);
    var total_ttc = total_ht + total_tva;
    
    $("input[name='line_total_ht_" + lineNum + "']").val(total_ht.toFixed(2));
    $("input[name='line_total_tva_" + lineNum + "']").val(total_tva.toFixed(2));
    $("input[name='line_total_ttc_" + lineNum + "']").val(total_ttc.toFixed(2));
    
    updateInvoiceTotals();
}

// Update invoice totals
// ── VAT calculation methods (Method 1 = total of round, Method 2 = round of total) ──
var upinvActiveMethod = 1;

function upinvR2(n) { return Math.round((n + Number.EPSILON) * 100) / 100; }

// Read each line's base (HT) and VAT rate from the table
function upinvGetLines() {
    var lines = [];
    $("input.totalhtline").each(function() {
        var name = $(this).attr('name') || '';
        var m = name.match(/line_total_ht_(\d+)/);
        if (!m) return;
        var i = m[1];
        var ht = parseFloat($(this).val()) || 0;
        var tx = parseLocalizedFloat($("input[name='line_tva_tx_" + i + "']").val()) || 0;
        var extra = ($("input[name='line_extra_" + i + "']").val() === '1');
        lines.push({ ht: ht, tx: tx, extra: extra });
    });
    return lines;
}

// Method 1: round VAT on each line, then sum
function upinvComputeMethod1(lines, localtax2) {
    var ht = 0, tva = 0;
    lines.forEach(function(l) {
        ht += l.ht;
        tva += upinvR2(l.ht * l.tx / 100);
    });
    ht = upinvR2(ht); tva = upinvR2(tva);
    return { ht: ht, tva: tva, ttc: upinvR2(ht + tva + localtax2) };
}

// Method 2: sum bases per VAT rate, then round the VAT of each group
function upinvComputeMethod2(lines, localtax2) {
    var ht = 0, byRate = {};
    lines.forEach(function(l) {
        ht += l.ht;
        byRate[l.tx] = (byRate[l.tx] || 0) + l.ht;
    });
    var tva = 0;
    Object.keys(byRate).forEach(function(tx) {
        tva += upinvR2(byRate[tx] * parseFloat(tx) / 100);
    });
    ht = upinvR2(ht); tva = upinvR2(tva);
    return { ht: ht, tva: tva, ttc: upinvR2(ht + tva + localtax2) };
}

function upinvCompute(method, lines, localtax2) {
    return method === 2 ? upinvComputeMethod2(lines, localtax2) : upinvComputeMethod1(lines, localtax2);
}

// Recompute and display invoice totals using the active method, then reconcile vs extracted
function updateInvoiceTotals() {
    var lines = upinvGetLines();
    var localtax2 = parseLocalizedFloat($("#localtax2").val()) || 0;
    var t = upinvCompute(upinvActiveMethod, lines, localtax2);
    $("#total_ht").val(t.ht.toFixed(2));
    $("#total_tva").val(t.tva.toFixed(2));
    $("#total_ttc").val(t.ttc.toFixed(2));
    upinvReconcile(t, lines);
    // Payment box (invoice.php only)
    if (typeof upinvSyncPaymentAmount === 'function') {
        upinvSyncPaymentAmount();
    }
}

// Apply a method (updates display + hidden field + button state)
function upinvApplyMethod(m) {
    upinvActiveMethod = (m === 2) ? 2 : 1;
    $('#calc_method').val(upinvActiveMethod);
    $('.upinv-method-btn').removeClass('active');
    $('.upinv-method-btn[data-method="' + upinvActiveMethod + '"]').addClass('active');
    updateInvoiceTotals();
}

// Pick the method whose totals are closest to the extracted document totals
function upinvAutoPickMethod() {
    var ex = upinvoiceExtracted;
    var lines = upinvGetLines();
    var localtax2 = parseLocalizedFloat($("#localtax2").val()) || 0;
    if (!ex || !ex.total_ttc) { upinvApplyMethod(1); return; }
    var m1 = upinvComputeMethod1(lines, localtax2);
    var m2 = upinvComputeMethod2(lines, localtax2);
    var d1 = Math.abs(m1.ttc - ex.total_ttc) + Math.abs(m1.tva - ex.total_tva);
    var d2 = Math.abs(m2.ttc - ex.total_ttc) + Math.abs(m2.tva - ex.total_tva);
    upinvApplyMethod(d2 < d1 ? 2 : 1);
}

// Compare computed totals against extracted; fine-adjust (cents) or warn (large gap)
function upinvReconcile(t, lines) {
    lines = lines || [];
    var nLines = lines.length;
    var ex = upinvoiceExtracted;
    var $w = $('#upinv-reconcile-warning');
    if (!ex || !ex.total_ttc) { $w.hide().empty(); return; }
    var tol = Math.min(0.10, 0.01 + 0.01 * (nLines || 1));

    // Las líneas "extra" no van sumadas en la base/IVA del documento, solo en el total.
    // Ajustamos la referencia extraída sumándoles esas líneas para comparar contra los
    // totales calculados (que sí las incluyen). El TTC extraído ya las contiene.
    var exHt = ex.total_ht, exTva = ex.total_tva, exTtc = ex.total_ttc;
    lines.forEach(function(l) {
        if (!l.extra) return;
        exHt = upinvR2(exHt + l.ht);
        exTva = upinvR2(exTva + upinvR2(l.ht * l.tx / 100));
    });

    var dTtc = upinvR2(exTtc - t.ttc);
    var dTva = upinvR2(exTva - t.tva);
    var dHt  = upinvR2(exHt - t.ht);

    if (dTtc === 0 && dTva === 0 && dHt === 0) {
        $w.attr('class', 'upinv-reconcile upinv-ok').html('<i class="fas fa-check-circle"></i> ' + upinvoiceMethodLangs.match).show();
        return;
    }
    if (Math.abs(dTtc) <= tol && Math.abs(dHt) <= tol) {
        // Cent-level gap: snap displayed totals to the extracted document values
        // (ajustados con las líneas "extra", para no perder su importe de la base).
        $("#total_ht").val(exHt.toFixed(2));
        $("#total_tva").val(exTva.toFixed(2));
        $("#total_ttc").val(exTtc.toFixed(2));
        var cents = Math.round(Math.abs(dTtc) * 100);
        var adjMsg = upinvoiceMethodLangs.adjusted || 'Adjusted %s cts';
        adjMsg = (adjMsg.indexOf('%s') >= 0) ? adjMsg.replace('%s', cents) : (adjMsg + ' (' + cents + ')');
        $w.attr('class', 'upinv-reconcile upinv-adjusted')
          .html('<i class="fas fa-wand-magic-sparkles"></i> ' + adjMsg)
          .show();
        return;
    }
    // Large gap: warn per field, do not touch
    function row(label, exv, comp, d) {
        if (Math.round(d * 100) === 0) return '';
        return '<div>' + label + ': ' + upinvoiceMethodLangs.extracted + ' <b>' + exv.toFixed(2) + '</b> · '
            + upinvoiceMethodLangs.computed + ' <b>' + comp.toFixed(2) + '</b> · '
            + upinvoiceMethodLangs.diff + ' <b>' + d.toFixed(2) + '</b></div>';
    }
    var rows = row(upinvoiceMethodLangs.base, exHt, t.ht, dHt)
        + row(upinvoiceMethodLangs.vat, exTva, t.tva, dTva)
        + row(upinvoiceMethodLangs.total, exTtc, t.ttc, dTtc);
    $w.attr('class', 'upinv-reconcile upinv-mismatch')
      .html('<div><i class="fas fa-exclamation-triangle"></i> ' + upinvoiceMethodLangs.mismatch + '</div>' + rows)
      .show();
}

// Add a new line with product type selector and product select2
function addNewLine() {
    var lineCount = parseInt($("#line_count").val());
    var tableBody = $("#invoice_lines_table tbody"); // Target tbody for better structure
    // Try to get a default VAT rate from the first line if it exists
    var defaultVatInput = $("input[name='line_tva_tx_0']");
    var defaultVat = defaultVatInput.length ? defaultVatInput.val() : "0";

    var newLine = '<tr class="invoice-line" id="line_row_' + lineCount + '">' +
        '<td>' +
            // --- START: Product Type Selector for new lines ---
            '<div class="product-type-selector" style="margin-bottom:5px;">' +
                '<select id="line_type_' + lineCount + '" name="line_type_' + lineCount + '" class="flat" style="padding: 6px 5px 5px; height: auto;min-width: 100%;">' +
                    '<option value="0">' + upinvoiceimport_langs.Product + '</option>' +
                    '<option value="1">' + upinvoiceimport_langs.Service + '</option>' +
                '</select>' +
            '</div>' +
            // --- END: Product Type Selector ---
            
            // --- START: Select2 Product Selector ---
            '<div class="product-select-container" style="margin-bottom:5px;">' +
                '<select id="line_product_' + lineCount + '" name="line_product_' + lineCount + '" class="product-select2" data-line="' + lineCount + '" style="width: 100%;">' +
                    '<option value=""></option>' +
                '</select>' +
            '</div>' +
            // --- END: Select2 Product Selector ---
            
            '<textarea name="line_desc_' + lineCount + '" class="flat width100p" style="margin-top: 5px; width: 98%"></textarea>' +
            // Hidden product ID for new lines (value 0)
            '<input type="hidden" name="line_fk_product_' + lineCount + '" value="0">' +
        '</td>' +
        '<td class="center"><input type="text" size="5" name="line_qty_' + lineCount + '" value="1" class="flat qtyline right" onchange="updateLineTotals(' + lineCount + ')"></td>' +
        '<td class="center"><input type="text" size="8" name="line_pu_ht_' + lineCount + '" value="0" class="flat puhline right" onchange="updateLineTotals(' + lineCount + ')"></td>' +
        '<td class="center"><input type="text" size="5" name="line_remise_percent_' + lineCount + '" value="" class="flat remisepercentline right" onchange="updateLineTotals(' + lineCount + ')"></td>' +
        '<td class="right"><input type="text" size="8" name="line_total_ht_' + lineCount + '" value="0" class="flat totalhtline right" readonly></td>' +
        '<td class="center"><input type="text" size="5" name="line_tva_tx_' + lineCount + '" value="' + defaultVat + '" class="flat tvaline right" onchange="updateLineTotals(' + lineCount + ')"></td>' +
        '<td class="right"><input type="text" size="8" name="line_total_tva_' + lineCount + '" value="0" class="flat totalvaline right" readonly></td>' +
        '<td class="right"><input type="text" size="8" name="line_total_ttc_' + lineCount + '" value="0" class="flat totalttcline right" readonly></td>' +
        '<td class="center"><a href="#" class="delete-line" data-line="' + lineCount + '"><i class="fas fa-trash"></i></a></td>' +
        '</tr>';

    tableBody.append(newLine);
    $("#line_count").val(lineCount + 1);
    
    // Trigger an event to notify that a new line was added
    $(document).trigger('line_added', [lineCount]);
    
    // Focus on the product selector for the new line
    $("#line_product_" + lineCount).focus();
    
    // Initialize totals for the new line (will also update invoice totals)
    updateLineTotals(lineCount);
}

// Delete a line
function deleteLine(lineNum) {
    // Check if it's the last line
    if ($('.invoice-line').length <= 1) {
        alert(upinvoiceimport_langs.CannotDeleteLastLine);
        return;
    }

    $('#line_row_' + lineNum).remove();
    updateInvoiceTotals(); // Recalculate totals after deletion
    // Optional: Renumber subsequent lines if needed, but usually not necessary if using unique IDs/names
}
