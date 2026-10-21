/**
 * Product selector functionality for UpInvoice module
 * Uses Select2 for a more efficient product selection interface
 */

var UpInvoiceProductSelector = {
    /**
     * Initialize the product selector
     */
    init: function() {
        // Set up event handlers for the product select boxes
        this.setupEventHandlers();
    },
    
    /**
     * Setup event handlers for product selector
     */
    setupEventHandlers: function() {
        // Handle line initialization after DOM is ready
        $(document).ready(function() {
            // Initialize existing product selectors
            $('.product-select2').each(function() {
                UpInvoiceProductSelector.initializeSelect2($(this));
            });
        });
        
        // When adding a new line, we need to initialize the select2 after the line is added
        $(document).on('line_added', function(e, lineNum) {
            // Initialize select2 for the new line
            var $select = $('#line_product_' + lineNum);
            UpInvoiceProductSelector.initializeSelect2($select);
        });
    },
    
    /**
     * Initialize Select2 for a product selector
     * 
     * @param {jQuery} $select - The select element to initialize
     */
    initializeSelect2: function($select) {
        if ($select.hasClass('select2-hidden-accessible')) {
            // Select2 already initialized
            return;
        }
        
        var lineNum = $select.data('line');
        var currentProductId = $('input[name="line_fk_product_' + lineNum + '"]').val() || 0;
        var currentType = $('#line_type_' + lineNum).val();
        
        // Trigger de borrado de producto cuando cambia el tipo de producto
        $('#line_type_' + lineNum).off('change').on('change', function() {
            console.log('Product type changed for line ' + lineNum);
            // Si hay un producto seleccionado, resetear la selección
            if ($select.val()) {
                console.log('Clearing product selection due to type change');
                
                // Limpiar el select2
                $select.val(null).trigger('change');
                
                // Asegurarse explícitamente que se limpia el ID
                // Limpiar el hidden field
                $('input[name="line_fk_product_' + lineNum + '"]').val(0);
                
                // Resetear la clase de fila
                $('#line_row_' + lineNum).removeClass('has-product');
            }
        });
        $select.select2({
            theme: 'bootstrap',
            language: $('html').attr('lang') || 'es', // Usa el atributo lang del HTML o valor predeterminado
            width: '100%',
            placeholder: upinvoiceimport_langs.SearchProduct,
            allowClear: true,
            minimumInputLength: 1,
            ajax: {
                url: upinvoiceimport_root + '/ajax/product_search.php',
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    return {
                        search: params.term,
                        type: $('#line_type_' + lineNum).val() || '-1', // Get the current product type value
                        token: upinvoiceimport_token
                    };
                },
                processResults: function(data, params) {
                    if (data.status === 'success') {
                        return {
                            results: $.map(data.results, function(product) {
                                var typeBadge = product.type == 1 ? 
                                    '<span class="badge badge-success">' + upinvoiceimport_langs.Service + '</span>' : 
                                    '<span class="badge badge-info">' + upinvoiceimport_langs.Product + '</span>';
                                
                                return {
                                    id: product.id,
                                    text: product.ref + ' - ' + product.label,
                                    html: '<div class="select2-product">' + 
                                          '<span class="product-ref">' + product.ref + '</span> - ' +
                                          '<span class="product-label">' + product.label + '</span> ' +
                                          typeBadge +
                                          '<div class="product-price">' + upinvoiceimport_langs.Price + ': ' + product.price + ' | ' + 
                                          upinvoiceimport_langs.VATRate + ': ' + product.tva_tx + '%</div>' +
                                          '</div>',
                                    product: product
                                };
                            })
                        };
                    } else {
                        return { results: [] };
                    }
                },
                cache: true
            },
            templateResult: function(data) {
                if (data.html) {
                    return $(data.html);
                }
                return data.text;
            },
            templateSelection: function(data) {
                return UpInvoiceProductSelector.renderSelectionBadge(data);
            },
            escapeMarkup: function(markup) {
                return markup; // Allow custom HTML in the dropdown
            }
        });
        
        // Handle product selection
        $select.on('select2:select', function(e) {
            var lineNum = $(this).data('line');
            var selectedData = e.params.data;
            
            if (selectedData && selectedData.product) {
                var product = selectedData.product;
                UpInvoiceProductSelector.applyProductToLine(
                    lineNum,
                    product.id,
                    product.ref,
                    product.label,
                    product.description,
                    product.price.replace(/[^0-9,-]/g, '').replace(',', '.'),
                    product.tva_tx,
                    product.type
                );
            }
        });
        
        // Handle select2:clear event más explícitamente
        $select.on('select2:clear', function(e) {
            var lineNum = $(this).data('line');
            console.log('Select2 clear event for line ' + lineNum);
            
            // Limpiar el ID del producto
            $('input[name="line_fk_product_' + lineNum + '"]').val(0);
            
            // Resetear la clase de fila
            $('#line_row_' + lineNum).removeClass('has-product');
            
            // Actualizar totales
            //updateLineTotals(lineNum);
        });
        
        // Cuando se inicializa Select2, si ya tiene un valor seleccionado
        if (currentProductId && parseInt(currentProductId) > 0) {
            $('#line_row_' + lineNum).addClass('has-product');
        }
        
        // When product type changes, reset the product selector
        $('#line_type_' + lineNum).on('change', function() {
            $select.val(null).trigger('change');
        });
    },
    
    /**
     * Apply selected product to invoice line
     * 
     * @param {number} lineNum - Line number
     * @param {number} productId - Product ID
     * @param {string} productRef - Product reference
     * @param {string} productLabel - Product label
     * @param {string} productDesc - Product description
     * @param {number} productPrice - Product price
     * @param {number} productVat - Product VAT rate
     * @param {number} productType - Product type (0=product, 1=service)
     */
    applyProductToLine: function(lineNum, productId, productRef, productLabel, productDesc, productPrice, productVat, productType) {
        // Update hidden product ID input
        $('input[name="line_fk_product_' + lineNum + '"]').val(productId);
        
        // Update product type
        $('#line_type_' + lineNum).val(productType);
        
        // Update description field
        var descText = productLabel;
        if (productDesc) {
            descText += "\n\n" + productDesc;
        }
        $('textarea[name="line_desc_' + lineNum + '"]').val(descText);
        
        // Comprobar y actualizar el precio unitario con confirmación si es necesario
        var currentPrice = $('input[name="line_pu_ht_' + lineNum + '"]').val();
        if (currentPrice && parseFloat(currentPrice) > 0 && parseFloat(currentPrice) !== parseFloat(productPrice)) {
            if (confirm(upinvoiceimport_langs.ConfirmReplacePrice.replace('&iquest;','¿') + "\n" + 
                      upinvoiceimport_langs.Current + ": " + currentPrice + "\n" + 
                      upinvoiceimport_langs.New + ": " + productPrice)) {
                $('input[name="line_pu_ht_' + lineNum + '"]').val(productPrice);
            }
            // Si no confirma, mantenemos el precio actual
        } else {
            // Si no hay precio o es 0, actualizamos directamente
            $('input[name="line_pu_ht_' + lineNum + '"]').val(productPrice);
        }
        
        // Comprobar y actualizar la tasa de IVA con confirmación si es necesario
        var currentVat = $('input[name="line_tva_tx_' + lineNum + '"]').val();
        if (currentVat && parseFloat(currentVat) >= 0 && parseFloat(currentVat) !== parseFloat(productVat)) {
            if (confirm(upinvoiceimport_langs.ConfirmReplaceVAT + "\n" + 
                      upinvoiceimport_langs.Current + ": " + currentVat + "%\n" + 
                      upinvoiceimport_langs.New + ": " + productVat + "%")) {
                $('input[name="line_tva_tx_' + lineNum + '"]').val(productVat);
            }
            // Si no confirma, mantenemos la tasa actual
        } else {
            // Si no hay tasa o es 0, actualizamos directamente
            $('input[name="line_tva_tx_' + lineNum + '"]').val(productVat);
        }
        
        // If quantity is empty or 0, set it to 1
        var qty = parseFloat($('input[name="line_qty_' + lineNum + '"]').val()) || 0;
        if (qty <= 0) {
            $('input[name="line_qty_' + lineNum + '"]').val(1);
        }
        
        // Update totals
        updateLineTotals(lineNum);
        
        // Add visual indication that product is selected (the badge itself is drawn by templateSelection)
        $('#line_row_' + lineNum).addClass('has-product');
    },

    /**
     * Render the selected product as a badge inside the Select2 box.
     * Select2 prepends its own clear "×" to the same container; CSS places it at the end of the badge.
     *
     * @param {Object} data - Select2 data item (ajax result with .product, or preselected <option> with data-*)
     * @return {jQuery|string}
     */
    renderSelectionBadge: function(data) {
        if (!data.id) {
            return data.text; // Placeholder
        }

        var ref = '', label = '', type = 0;
        if (data.product) {
            ref = data.product.ref;
            label = data.product.label;
            type = data.product.type;
        } else if (data.element && $(data.element).data('ref') !== undefined) {
            ref = String($(data.element).data('ref'));
            label = String($(data.element).data('label') || '');
            type = $(data.element).data('type');
        } else {
            return data.text;
        }

        var typeText = type == 1 ? upinvoiceimport_langs.Service : upinvoiceimport_langs.Product;
        var $badge = $('<span class="upinv-prod-badge"></span>').attr('title', ref + (label ? ' - ' + label : ''));
        $badge.append($('<i></i>').addClass('fas fa-' + (type == 1 ? 'concierge-bell' : 'cube')).attr('title', typeText));
        var $text = $('<span class="upinv-prod-badge-text"></span>').append($('<strong></strong>').text(ref));
        if (label) {
            $text.append(document.createTextNode(' - ' + label));
        }
        $badge.append($text);
        return $badge;
    },
    
    /**
     * Clear product data from line
     * 
     * @param {number} lineNum - Line number
     */
    clearProductFromLine: function(lineNum) {
        // Clear hidden product ID field
        $('input[name="line_fk_product_' + lineNum + '"]').val(0);
        
        // Remove visual indication that product is selected
        $('#line_row_' + lineNum).removeClass('has-product');
        
        // Clear the product description (optional, depending on UX preference)
        // $('textarea[name="line_desc_' + lineNum + '"]').val('');
        
        // Don't reset prices or quantities - that might be annoying for users
        // Just update the totals based on current values
        //updateLineTotals(lineNum);
        
        console.log('Product cleared from line ' + lineNum);
    }
};

// Initialize on document ready
$(document).ready(function() {
    // Initialize product selector
    UpInvoiceProductSelector.init();
});