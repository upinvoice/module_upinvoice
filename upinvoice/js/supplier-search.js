/**
 * JavaScript para búsqueda en tiempo real de proveedores
 */
$(document).ready(function() {
    var searchTimeout = null;
    var searchResultsContainer = $('#search-results-container');
    
    // Función para realizar la búsqueda AJAX
    function performSearch() {
        var searchTerm = $('#search_term').val();
        
        // No buscar si el campo está vacío
        if (searchTerm.trim() === '') {
            searchResultsContainer.html('');
            return;
        }
        
        // Mostrar indicador de carga
        searchResultsContainer.html('<div class="search-loading"><i class="fas fa-spinner fa-spin"></i> ' + upinvoiceimport_langs.Searching + '...</div>');
        
        // Realizar la búsqueda
        $.ajax({
            url: upinvoiceimport_root + '/ajax/search_supplier.php',
            type: 'GET',
            data: {
                token: upinvoiceimport_token,
                search_term: searchTerm
            },
            success: function(response) {
                try {
                    var result = JSON.parse(response);
                    
                    if (result.status === 'success') {
                        if (result.suppliers && result.suppliers.length > 0) {
                            // Construir tabla de resultados
                            var html = '<div class="upinvoiceimport-search-results">';
                            html += '<h3>' + upinvoiceimport_langs.FoundSuppliers + '</h3>';
                            html += '<table class="noborder centpercent">';
                            html += '<tr class="liste_titre">';
                            html += '<td>' + upinvoiceimport_langs.Name + '</td>';
                            html += '<td>' + upinvoiceimport_langs.TaxIDs + '</td>';
                            html += '<td>' + upinvoiceimport_langs.Address + '</td>';
                            html += '<td>' + upinvoiceimport_langs.Actions + '</td>';
                            html += '</tr>';
                            
                            $.each(result.suppliers, function(index, supplier) {
                                html += '<tr class="oddeven">';
                                html += '<td>' + supplier.name + (supplier.name_alias ? ' (' + supplier.name_alias + ')' : '') + '</td>';
                                html += '<td>' + supplier.tax_info + '</td>';
                                html += '<td>' + supplier.address + '</td>';
                                html += '<td>';
                                html += '<button type="button" class="select-supplier-btn btn btn-success btn-sm" data-supplier-id="' + supplier.id + '">';
                                html += '<i class="fas fa-check"></i> ' + upinvoiceimport_langs.SelectThisSupplier + '</button>';
                                html += '</td>';
                                html += '</tr>';
                            });
                            
                            html += '</table>';
                            html += '</div>';
                            
                            searchResultsContainer.html(html);
                            
                            // Agregar eventos a botones de selección
                            $('.select-supplier-btn').on('click', function() {
                                var supplierId = $(this).data('supplier-id');
                                confirmSupplier(supplierId);
                            });
                        } else {
                            searchResultsContainer.html('<div class="info">' + upinvoiceimport_langs.NoSuppliersFound + '</div>');
                        }
                    } else {
                        searchResultsContainer.html('<div class="error">' + result.message + '</div>');
                    }
                } catch (e) {
                    searchResultsContainer.html('<div class="error">' + upinvoiceimport_langs.ErrorProcessingResponse + '</div>');
                    console.error('Error parsing response', e);
                }
            },
            error: function(xhr, status, error) {
                searchResultsContainer.html('<div class="error">' + upinvoiceimport_langs.SearchFailed + ': ' + error + '</div>');
                console.error('Search failed', error);
            }
        });
    }
    
    // Función para confirmar la selección de un proveedor
    function confirmSupplier(supplierId) {
        $.ajax({
            url: upinvoiceimport_root + '/ajax/confirm_supplier.php',
            type: 'POST',
            data: {
                token: upinvoiceimport_token,
                file_id: $('#file_id').val(),
                supplier_id: supplierId
            },
            success: function(response) {
                try {
                    var result = JSON.parse(response);
                    
                    if (result.status === 'success') {
                        // Redirigir a la página de validación de facturas
                        window.location.href = upinvoiceimport_root + (typeof upinvoiceimport_review_file !== 'undefined' ? upinvoiceimport_review_file : '/invoice.php') + '?file_id=' + $('#file_id').val();
                    } else {
                        alert(result.message || upinvoiceimport_langs.ErrorConfirmingSupplier);
                    }
                } catch (e) {
                    alert(upinvoiceimport_langs.ErrorProcessingResponse);
                    console.error('Error parsing response', e);
                }
            },
            error: function(xhr, status, error) {
                alert(upinvoiceimport_langs.ErrorConfirmingSupplier + ': ' + error);
                console.error('Confirmation failed', error);
            }
        });
    }
    
    // Eventos para campo de búsqueda
    $('#search_term').on('keyup', function() {
        // Cancelar búsqueda anterior si existe
        if (searchTimeout) {
            clearTimeout(searchTimeout);
        }
        
        // Programar nueva búsqueda con retraso de 300ms
        searchTimeout = setTimeout(function() {
            performSearch();
        }, 300);
    });
    
    // Evitar envío del formulario de búsqueda
    $('#supplier-search-form').on('submit', function(e) {
        e.preventDefault();
        performSearch();
    });
});