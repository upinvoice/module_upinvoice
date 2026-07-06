/**
 * JavaScript functions for UpInvoice Import module
 */

// Global variable for loadFilesList function
var upinvoiceLoadFilesListFunction;

// Flag to track if processing is currently active
var isProcessingActive = false;

// When true, the automatic queue is paused: the file currently in flight finishes,
// but no further pending files are picked up until the user resumes.
var isQueuePaused = false;

// Handle for the pending-tab auto-refresh poller
var _pendingPollTimer = null;

// Flag to track if a delete confirmation is active
var isDeleteConfirmationActive = false;

// Track AJAX requests so they can be aborted if needed
var activeAjaxRequests = {};

// Rowid of the email rule currently being edited (null = add mode)
var _editingRuleId = null;

// -- Credits bar --------------------------------------------------------------
// Timer handle for debounced credits refresh after batch processing
var _upinvoiceCreditsDebounceTimer = null;

/**
 * Load (or refresh) the credits bar from the API.
 * Pass force=true to bypass the PHP session cache (used on manual refresh).
 */
function upinvoiceLoadCreditsBar(force) {
    if (typeof upinvoiceHasKey === 'undefined' || !upinvoiceHasKey) return;
    if (typeof upinvoiceCheckUrl === 'undefined') return;

    var url = upinvoiceCheckUrl + (force ? '?force=1' : '');
    $.ajax({
        url: url,
        type: 'GET',
        dataType: 'json',
        success: function(resp) {
            var $bar = $('#upinvoice-credits-bar');
            if (!$bar.length) return;
            $('#upinvoice-credits-loading').hide();
            var $content = $('#upinvoice-credits-content').show();

            if (resp && resp.status === 'success' && resp.data && resp.data.credits) {
                var c = resp.data.credits;
                var remaining = parseInt(c.remaining) || 0;
                var total = parseInt(c.total) || 0;
                var pct = total > 0 ? Math.round((remaining / total) * 100) : 0;
                var barPct = Math.min(100, pct);
                var barColor = barPct < 20 ? '#e05353' : (barPct < 40 ? '#e09253' : '#47a966');

                var creditsLabel = (upinvoiceimport_langs.UpInvoiceCreditsOf || 'credits');
                var creditsText = (total > 0 && remaining <= total)
                    ? ' / ' + total + ' ' + creditsLabel + ' (' + pct + '%)'
                    : ' ' + creditsLabel;
                var creditsHtml = '<span style="font-weight:bold" id="upinvoice-credits-remaining">' + remaining + '</span>'
                    + creditsText
                    + ' &nbsp; <span style="display:inline-block;width:120px;background:#e0e0e0;border-radius:4px;height:10px;vertical-align:middle">'
                    + '<span style="display:block;width:' + barPct + '%;background:' + barColor + ';height:10px;border-radius:4px"></span></span>';

                if (resp.data.plan && resp.data.plan.name) {
                    creditsHtml += ' &nbsp; <span style="color:#666;font-size:0.9em">' + resp.data.plan.name + '</span>';
                }
                if (remaining === 0 && upinvoiceimport_langs.UpInvoiceNoCredits) {
                    creditsHtml += ' &nbsp; <span style="color:#e05353;font-weight:bold">' + upinvoiceimport_langs.UpInvoiceNoCredits + '</span>';
                    $bar.css('background', '#fff3f3');
                } else if (barPct < 20) {
                    $bar.css('background', '#fff8e1');
                } else {
                    $bar.css('background', '#f8f8f8');
                }
                $content.html(creditsHtml);
            } else {
                $content.html('<span style="color:#999;font-size:0.9em">–</span>');
            }
        },
        error: function() {
            $('#upinvoice-credits-loading').hide();
            $('#upinvoice-credits-content').show().html('<span style="color:#999;font-size:0.9em">–</span>');
        }
    });
}

/**
 * Subtract 1 from the displayed remaining credits immediately (optimistic UI).
 * The value is clamped to 0 and will be corrected by the next debounced sync.
 */
function upinvoiceUpdateCreditsOptimistic() {
    var $el = $('#upinvoice-credits-remaining');
    if (!$el.length) return;
    var current = parseInt($el.text()) || 0;
    if (current > 0) {
        $el.text(current - 1);
    }
}

/**
 * Schedule a credits sync 2 seconds from now.
 * If called again before the timer fires, it resets the timer (debounce).
 * This ensures batch processing (N files at once) triggers only 1 API call.
 */
function upinvoiceDebounceCreditsRefresh() {
    if (_upinvoiceCreditsDebounceTimer) {
        clearTimeout(_upinvoiceCreditsDebounceTimer);
    }
    _upinvoiceCreditsDebounceTimer = setTimeout(function() {
        _upinvoiceCreditsDebounceTimer = null;
        // force=1: bypass the 60s PHP session cache, otherwise the stale cached
        // value would overwrite the optimistic counter with the pre-processing total
        upinvoiceLoadCreditsBar(true);
    }, 2000);
}
// -- End credits bar ----------------------------------------------------------

// Show notification message.
// Toasts stack instead of overwriting each other, each auto-dismisses, and the
// close button works without depending on Bootstrap's JS.
function showNotification(message, type = 'info', container = '#upload-results') {
    const alertClass = type === 'success' ? 'alert-success' :
                        type === 'error' ? 'alert-danger' :
                        type === 'warning' ? 'alert-warning' : 'alert-info';

    const $alert = $(
        '<div class="alert ' + alertClass + ' alert-dismissible fade show" role="alert">' +
        '<span class="alert-body"></span>' +
        '<button type="button" class="close" aria-label="Close"><span aria-hidden="true">&times;</span></button>' +
        '</div>'
    );
    // message may contain trusted markup (icons) built by the caller
    $alert.find('.alert-body').html(message);

    function dismiss() {
        $alert.fadeOut(300, function() { $(this).remove(); });
    }
    $alert.find('.close').on('click', dismiss);

    $(container).append($alert);

    // Errors linger a little longer than info/success so they can be read
    var ttl = (type === 'error') ? 10000 : 6000;
    setTimeout(dismiss, ttl);
}

// Format file size in human-readable format
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// Create an indeterminate progress bar element.
// The AI call is a single synchronous request with no progress events, so an
// indeterminate animation is honest where a moving percentage would be fake.
function createProgressBar() {
    return '<div class="progress progress-indeterminate" role="progressbar">' +
           '<div class="progress-bar progress-bar-striped progress-bar-animated"></div>' +
           '</div>';
}

// Process file function
function processFile(fileId, callback) {
    // Set the global processing flag
    isProcessingActive = true;
    
    var $button = $('.process-file-btn[data-file-id="' + fileId + '"]');
    var $status = $('#file-status-' + fileId);
    var $progressContainer = $('#file-progress-' + fileId);
    
    // Mark the card visually as in-progress
    $button.closest('.file-card').attr('data-state', 'processing');

    // Update button and status
    $button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');
    $status.html('<i class="fas fa-spinner fa-spin"></i> ' + upinvoiceimport_langs.ProcessingWithAI);

    // Create the indeterminate progress bar if it doesn't exist
    if ($progressContainer.length === 0) {
        // Buscar el div file-card que contiene el botón
        var $fileCard = $button.closest('.file-card');
        // Añadir el contenedor de progreso después del divider
        $fileCard.find('.file-card-divider').after('<div id="file-progress-' + fileId + '" class="file-progress">' + createProgressBar() + '</div>');
        $progressContainer = $('#file-progress-' + fileId);
    } else {
        $progressContainer.html(createProgressBar());
        $progressContainer.show();
    }

    // Show processing notification
    showNotification('<i class="fas fa-spinner fa-spin"></i> ' + upinvoiceimport_langs.ProcessingWithAI, 'info');

    // Send request to process file
    var jqXHR = $.ajax({
        url: upinvoiceimport_root + '/ajax/process_file.php',
        type: 'POST',
        data: {
            file_id: fileId,
            token: upinvoiceimport_token
        },
        success: function(response) {
            var $fileCard = $button.closest('.file-card');
            $fileCard.find('.file-error').remove();

            try {
                var result = JSON.parse(response);
                if (result.status === 'success') {
                    // Update status
                    $fileCard.attr('data-state', 'processed');
                    $status.html('<span class="badge badge-processed">' + upinvoiceimport_langs.Processed + '</span>');
                    
                    // Show success notification
                    showNotification('<i class="fas fa-check-circle"></i> ' + upinvoiceimport_langs.FileProcessedSuccessfully, 'success');
                    
                    // Replace the actions div with new buttons
                    var $actionsDiv = $button.closest('.file-actions');
                    $actionsDiv.html('<a href="' + upinvoiceimport_root + '/supplier.php?file_id=' + fileId + '" class="btn btn-success btn-sm"><i class="fas fa-arrow-right"></i> ' + upinvoiceimport_langs.NextStep + '</a>' +
                       ' <button class="btn btn-danger btn-sm delete-file-btn" data-file-id="' + fileId + '"><i class="fas fa-trash"></i></button>');
                    
                    // Re-attach delete event handler
                    $actionsDiv.find('.delete-file-btn').on('click', function(e) {
                        e.preventDefault();
                        var fileId = $(this).data('file-id');
                        confirmDeleteFile(fileId);
                    });
                    
                    // Hide progress bar after a delay
                    setTimeout(function() {
                        $progressContainer.fadeOut();
                    }, 2000);
                    
                    // Release the processing flag
                    isProcessingActive = false;
                    
                    // Find and process next file
                    findAndProcessNextFile();

                    // Optimistic UI: subtract 1 from credits display immediately,
                    // then schedule a debounced sync with the real API value
                    upinvoiceUpdateCreditsOptimistic();
                    upinvoiceDebounceCreditsRefresh();
                } else {
                    // Update status with error
                    $status.html('<span class="badge badge-error">' + upinvoiceimport_langs.Error + '</span>');
                    $fileCard.attr('data-state', 'error');

                    // Show error message
                    // Eliminar cualquier mensaje de error previo
                    $fileCard.find('.file-error').remove();
                    // Añadir el nuevo mensaje de error
                    $fileCard.find('.file-card-divider').after('<div class="file-error"><span class="text-danger"><i class="fas fa-exclamation-circle"></i> ' + result.message + '</span></div>');
                    
                    $button.prop('disabled', false).html('<i class="fas fa-redo"></i> ' + upinvoiceimport_langs.Retry);
                    showNotification('<i class="fas fa-exclamation-circle"></i> ' + result.message, 'error');
                    
                    // Remove progress bar
                    $progressContainer.remove();
                    
                    // Release the processing flag
                    isProcessingActive = false;
                    
                    // Find and process next file - only process next file if this one failed
                    findAndProcessNextFile();
                }
            } catch (e) {
                // Update status with error
                $status.html('<span class="badge badge-error">' + upinvoiceimport_langs.Error + '</span>');
                $button.closest('.file-card').attr('data-state', 'error');
                $button.prop('disabled', false).html('<i class="fas fa-redo"></i> ' + upinvoiceimport_langs.Retry);
                showNotification('<i class="fas fa-exclamation-circle"></i> ' + upinvoiceimport_langs.ErrorProcessingResponse, 'error');
                console.error('Error parsing response', e);
                
                // Remove progress bar
                $progressContainer.remove();
                
                // Release the processing flag
                isProcessingActive = false;
                
                // Find and process next file
                findAndProcessNextFile();
            }
            
            // Eliminar del registro de solicitudes activas
            delete activeAjaxRequests[fileId];
            
            // Execute callback if provided
            if (typeof callback === 'function') {
                callback(fileId);
            }
            
            // Refresh the file list
            if (typeof upinvoiceLoadFilesListFunction === 'function') {
                setTimeout(function() {
                    upinvoiceLoadFilesListFunction();
                }, 1000);
            }
        },
        error: function(xhr, status, error) {
            // Si es un aborto, no mostrar como error
            if (status === 'abort') {
                console.log('Request aborted for file ' + fileId);

                // Eliminar del registro de solicitudes activas
                delete activeAjaxRequests[fileId];
                
                // Execute callback if provided
                if (typeof callback === 'function') {
                    callback(fileId);
                }
                
                // Release the processing flag
                isProcessingActive = false;
                
                return;
            }
            
            // Update status with error
            $status.html('<span class="badge badge-error">' + upinvoiceimport_langs.Error + '</span>');
            $button.prop('disabled', false).html('<i class="fas fa-redo"></i> ' + upinvoiceimport_langs.Retry);
            showNotification('<i class="fas fa-exclamation-circle"></i> ' + upinvoiceimport_langs.ProcessingFailed + ': ' + error, 'error');
            console.error('Processing failed', error);

            // Eliminar cualquier mensaje de error previo
            var $fileCard = $button.closest('.file-card');
            $fileCard.attr('data-state', 'error');
            $fileCard.find('.file-error').remove();
            // Añadir el nuevo mensaje de error
            $fileCard.find('.file-card-divider').after('<div class="file-error"><span class="text-danger"><i class="fas fa-exclamation-circle"></i> ' + upinvoiceimport_langs.ProcessingFailed + ': ' + error + '</span></div>');     
            
            // Remove progress bar
            $progressContainer.remove();
            
            // Eliminar del registro de solicitudes activas
            delete activeAjaxRequests[fileId];
            
            // Execute callback if provided
            if (typeof callback === 'function') {
                callback(fileId);
            }
            
            // Release the processing flag
            isProcessingActive = false;
            
            // Find and process next file
            findAndProcessNextFile();
        }
    });
    
    // Almacenar la referencia a la solicitud AJAX
    activeAjaxRequests[fileId] = jqXHR;
}

// Función para encontrar y procesar el siguiente archivo pendiente
function findAndProcessNextFile() {
    // Si ya hay un proceso activo, no hacemos nada
    if (isProcessingActive) {
        return;
    }

    // Queue paused by the user: stop picking up new files
    if (isQueuePaused) {
        return;
    }

    // Buscar todos los archivos pendientes que no tengan error
    var pendingFiles = [];
    var processingButtons = $('.process-file-btn');
    
    // Si no hay botones de procesamiento, significa que no hay archivos pendientes
    if (processingButtons.length === 0) {
        return;
    }
    
    processingButtons.each(function() {
        // Comprobar si el botón está habilitado (no está siendo procesado)
        if (!$(this).prop('disabled')) {
            var fileId = $(this).data('file-id');
            
            // Comprobar si el archivo no tiene error
            var $status = $('#file-status-' + fileId);
            if (!$status.find('.badge-error').length) {
                pendingFiles.push(fileId);
            }
        }
    });
    
    // Si hay archivos pendientes, procesar el primero
    if (pendingFiles.length > 0) {
        processFile(pendingFiles[0]);
    }
}

// Go to next step (supplier validation)
function goToNextStep(fileId) {
    window.location.href = upinvoiceimport_root + '/supplier.php?file_id=' + fileId;
}

// Send the delete request for a single file. Does NOT ask for confirmation and
// does NOT refresh the list (callers decide), so it can be reused for bulk delete.
function deleteFileRequest(fileId, callback) {
    $.ajax({
        url: upinvoiceimport_root + '/ajax/delete_file.php',
        type: 'POST',
        data: {
            file_id: fileId,
            token: upinvoiceimport_token
        },
        success: function(response) {
            try {
                const result = JSON.parse(response);
                if (result.status === 'success') {
                    if (typeof callback === 'function') { callback(true, result.message); }
                } else {
                    showNotification(result.message, 'error');
                    if (typeof callback === 'function') { callback(false, result.message); }
                }
            } catch (e) {
                showNotification(upinvoiceimport_langs.ErrorProcessingResponse, 'error');
                if (typeof callback === 'function') { callback(false); }
            }
        },
        error: function(xhr, status, error) {
            showNotification(upinvoiceimport_langs.DeleteFailed + ': ' + error, 'error');
            if (typeof callback === 'function') { callback(false); }
        }
    });
}

// Handle file delete confirmation (single file). Uses the styled modal instead of
// the native browser confirm() so it matches the rest of the UI.
function confirmDeleteFile(fileId, callback) {
    // Prevenir múltiples confirmaciones simultáneas
    if (isDeleteConfirmationActive) {
        return;
    }
    isDeleteConfirmationActive = true;

    // A file that has gone through the AI (processed/error/stuck) or any finished
    // invoice already consumed its credit, which is NOT refunded on delete: warn.
    var state = $('.file-card[data-file-id="' + fileId + '"]').attr('data-state');
    var creditConsumed = (typeof upinvoiceimport_active_tab !== 'undefined' && upinvoiceimport_active_tab === 'finished')
        || (state && state !== 'pending');
    var confirmMsg = creditConsumed
        ? (upinvoiceimport_langs.DeleteProcessedCreditWarning || upinvoiceimport_langs.ConfirmDeleteFile)
        : upinvoiceimport_langs.ConfirmDeleteFile;

    UpInvoiceModal.confirm(confirmMsg, function(ok) {
        if (!ok) {
            isDeleteConfirmationActive = false;
            if (typeof callback === 'function') { callback(false); }
            return;
        }

        // Optimistic feedback: fade the card/row out immediately while the AJAX runs.
        // Covers both the pending grid (.file-card) and the finished table (<tr>).
        var $target = $('.file-card[data-file-id="' + fileId + '"]');
        if (!$target.length) {
            $target = $('.delete-file-btn[data-file-id="' + fileId + '"]').closest('tr');
        }
        $target.css('pointer-events', 'none').fadeTo(200, 0.35);

        deleteFileRequest(fileId, function(success, message) {
            isDeleteConfirmationActive = false;
            if (success) {
                $target.slideUp(150, function() { $(this).remove(); });
                showNotification(message, 'success');
                if (typeof upinvoiceLoadFilesListFunction === 'function') {
                    upinvoiceLoadFilesListFunction();
                }
            } else {
                // Restore the card if the delete failed
                $target.stop(true).css('pointer-events', '').fadeTo(150, 1);
            }
            if (typeof callback === 'function') { callback(success); }
        });
    });
}

// Toggle file preview modal usando el nuevo sistema de modales
function toggleFilePreview(fileId, fileName, fileType, filePath) {
    UpInvoiceModal.showDocumentPreview(filePath, fileType, fileName);
}

// Function to create a thumbnail for file preview
function createThumbnail(fileId, fileType, filePath) {
    var thumbnailHtml = '';
    
    if (fileType.includes('pdf')) {
        thumbnailHtml = `<div class="file-thumbnail pdf-thumbnail" data-file-id="${fileId}" data-file-path="${filePath}">
            <i class="fas fa-file-pdf fa-3x"></i>
        </div>`;
    } else if (fileType.includes('image')) {
        thumbnailHtml = `<div class="file-thumbnail image-thumbnail" data-file-id="${fileId}" data-file-path="${filePath}">
            <img src="${filePath}" class="thumbnail-image">
        </div>`;
    } else {
        thumbnailHtml = `<div class="file-thumbnail" data-file-id="${fileId}" data-file-path="${filePath}">
            <i class="fas fa-file fa-3x"></i>
        </div>`;
    }
    
    return thumbnailHtml;
}

/**
 * Sistema unificado para gestionar modales con jQuery UI
 */
var UpInvoiceModal = {
    /**
     * Inicializa el sistema de modales
     */
    init: function() {
        // Crear el div de diálogo si no existe
        if ($('#upinvoice-dialog-container').length === 0) {
            $('body').append('<div id="upinvoice-dialog-container" style="display:none;"></div>');
        }
        
        // Añadir manejadores de eventos para los botones que abren modales
        this.setupEventHandlers();
    },

    /**
     * Styled confirmation dialog (replaces the native confirm()).
     * @param {string}   message Text shown to the user
     * @param {function} cb      Called with true (confirmed) or false (cancelled)
     */
    confirm: function(message, cb) {
        var done = false;
        function finish(ok) {
            if (done) return;
            done = true;
            if (typeof cb === 'function') { cb(ok); }
        }

        var $c = $('#upinvoice-confirm-container');
        if ($c.length === 0) {
            $c = $('<div id="upinvoice-confirm-container" style="display:none;"></div>').appendTo('body');
        }
        $c.text(message);

        var buttons = {};
        // Resolve the result BEFORE closing: dialog('close') fires the `close`
        // callback synchronously (which calls finish(false)), so resolving first
        // and relying on the done-guard keeps the user's real choice.
        buttons[upinvoiceimport_langs.ConfirmDelete || 'OK'] = function() {
            finish(true);
            $(this).dialog('close');
        };
        buttons[upinvoiceimport_langs.Cancel || 'Cancel'] = function() {
            finish(false);
            $(this).dialog('close');
        };

        $c.dialog({
            title: upinvoiceimport_langs.ConfirmTitle || 'Confirm',
            modal: true,
            resizable: false,
            draggable: false,
            width: Math.min($(window).width() * 0.9, 420),
            buttons: buttons,
            close: function() { finish(false); }
        });
    },

    /**
     * Configurar manejadores de eventos
     */
    setupEventHandlers: function() {
        // Botón de vista previa de documento
        $(document).on('click', '#preview-doc-btn, .file-thumbnail', function(e) {
            e.preventDefault();
            
            var fileId = $(this).data('file-id') || '';
            var fileName = $(this).data('file-name') || 'Documento';
            var fileType = $(this).data('file-type') || '';
            var filePath = $(this).data('file-path') || '';
            
            // Si no hay ruta pero tenemos un botón de vista previa, intentar obtenerla del modal existente
            if (!filePath && $(this).attr('id') === 'preview-doc-btn') {
                var $modal = $('.upinvoiceimport-modal');
                if ($modal.length) {
                    var $iframe = $modal.find('.upinvoiceimport-pdf-preview');
                    if ($iframe.length) {
                        filePath = $iframe.attr('src');
                        fileType = 'application/pdf';
                    }
                    
                    var $img = $modal.find('.upinvoiceimport-img-preview');
                    if ($img.length) {
                        filePath = $img.attr('src');
                        fileType = 'image/jpeg'; // Asumimos jpeg por defecto
                    }
                    
                    fileName = $modal.find('h2').text() || 'Vista previa';
                }
            }
            
            // También intentar obtener la ruta desde los inputs ocultos en caso de estar disponibles
            if (!filePath) {
                var hiddenPath = $('#document-preview-path').val();
                var hiddenType = $('#document-preview-type').val();
                if (hiddenPath) {
                    filePath = hiddenPath;
                    fileType = hiddenType || fileType;
                }
            }
            
            if (filePath) {
                UpInvoiceModal.showDocumentPreview(filePath, fileType, fileName);
            }
        });
    },
    
    /**
     * Muestra la vista previa de un documento en un diálogo
     * @param {string} url - URL del documento
     * @param {string} type - Tipo MIME del documento
     * @param {string} title - Título del diálogo
     */
    showDocumentPreview: function(url, type, title) {
        var $dialogContainer = $('#upinvoice-dialog-container');
        var dialogContent = '';
        var dialogButtons = {};
        
        // Determinar contenido según el tipo de archivo
        if (type && type.indexOf('pdf') !== -1) {
            dialogContent = '<iframe src="' + url + '" style="width:100%; height:100%; border:none;"></iframe>';
        } else if (type && (type.indexOf('image') !== -1 || url.match(/\.(jpe?g|png|gif|bmp|webp)$/i))) {
            dialogContent = '<img src="' + url + '" style="max-width:100%; max-height:90vh; margin:0 auto; display:block;" />';
            
            // Para imágenes, añadimos botones específicos
            var rotation = 0;
            
            dialogButtons = {
                "Tamaño original": function() {
                    $(this).find('img').css({
                        'max-height': 'none',
                        'max-width': 'none'
                    });
                },
                "Girar 90°": function() {
                    rotation += 90;
                    $(this).find('img').css('transform', 'rotate(' + rotation + 'deg)');
                },
                "Cerrar": function() {
                    $(this).dialog('close');
                }
            };
        } else {
            dialogContent = '<div class="error">Vista previa no disponible</div>';
            dialogButtons = {
                "Cerrar": function() {
                    $(this).dialog('close');
                }
            };
        }
        
        // Establecer el contenido del diálogo
        $dialogContainer.html(dialogContent);
        
        // Calcular dimensiones adecuadas para el diálogo
        var winWidth = $(window).width();
        var winHeight = $(window).height();
        var dialogWidth = Math.min(winWidth * 0.9, 900);
        var dialogHeight = Math.min(winHeight * 0.9, 700);
        
        // Mostrar el diálogo
        $dialogContainer.dialog({
            title: title || 'Vista previa',
            width: dialogWidth,
            height: dialogHeight,
            modal: true,
            draggable: true,
            resizable: true,
            buttons: dialogButtons,
            close: function() {
                $(this).html(''); // Limpiar contenido al cerrar
            }
        });
        
        // Ajustes específicos para PDFs
        if (type && type.indexOf('pdf') !== -1) {
            $dialogContainer.css('padding', '0');
            $dialogContainer.parent().find('.ui-dialog-buttonpane').css('margin-top', '0');
        }
    }
};

// Function to load and display files list.
// Pass silent=true (used by the auto-refresh poller) to skip the loading indicator
// so the periodic refresh does not flicker.
// Current page of the finished-files list (server-side pagination)
var upinvFinishedPage = 1;

function loadFilesList(silent) {
    if (typeof upinvoiceimport_active_tab === 'undefined') {
        // Estamos en otra página (invoice.php o supplier.php), no necesitamos cargar la lista
        return;
    }

    // Guard: do not run when the emails tab is active
    if (upinvoiceimport_active_tab === 'emails') {
        return;
    }

    // Mostrar un indicador de carga pero sin eliminar el contenido existente
    var loadingIndicator = '<div class="info-box" style="margin-bottom:10px;"><i class="fas fa-sync fa-spin"></i> ' +
                          upinvoiceimport_langs.Loading + '...</div>';

    if (!silent) {
        if (upinvoiceimport_active_tab === 'pending') {
            // Añadir el indicador sin eliminar el contenido existente
            $('.info-box').remove(); // Eliminar cualquier indicador previo
            $('#pending-files-list').prepend(loadingIndicator);
        } else {
            // Para la tabla de finalizados, podemos añadir una fila con el indicador
            $('.loading-indicator').remove(); // Eliminar cualquier indicador previo
            $('#finished-files-list').prepend('<tr class="loading-indicator"><td colspan="8" class="center"><i class="fas fa-sync fa-spin"></i> ' +
                                            upinvoiceimport_langs.Loading + '...</td></tr>');
        }
    }

    $.ajax({
        url: upinvoiceimport_root + '/ajax/list_files.php',
        type: 'GET',
        data: {
            token: upinvoiceimport_token,
            file_type: upinvoiceimport_active_tab, // 'pending' or 'finished'
            page: (upinvoiceimport_active_tab === 'finished') ? upinvFinishedPage : 1
        },
        success: function(response) {
            try {
                // Eliminar indicadores de carga
                $('.info-box, .loading-indicator').remove();
                
                var result = JSON.parse(response);
                if (result.status === 'success') {
                    // Solo reemplazar el contenido si recibimos HTML válido
                    if (result.html && result.html.trim() !== '' && result.html !== 'undefined') {
                        if (upinvoiceimport_active_tab === 'pending') {
                            $('#pending-files-list').html(result.html);
                        } else {
                            $('#finished-files-list').html(result.html);
                        }
                        
                        // Setup process button events - Use delegated events to avoid duplicates
                        $('#pending-files-list').off('click', '.process-file-btn').on('click', '.process-file-btn', function() {
                            var fileId = $(this).data('file-id');
                            if (!isProcessingActive) {
                                processFile(fileId);
                            } else {
                                showNotification('<i class="fas fa-info-circle"></i> ' + upinvoiceimport_langs.ProcessingInProgress, 'info');
                            }
                        });
                        
                        // Setup delete button events - Use delegated events to avoid duplicates
                        $('#pending-files-list, #finished-files-list').off('click', '.delete-file-btn').on('click', '.delete-file-btn', function(e) {
                            e.preventDefault();
                            var fileId = $(this).data('file-id');
                            confirmDeleteFile(fileId);
                        });
                        
                        // Setup thumbnail click events - Use delegated events
                        $('#pending-files-list, #finished-files-list').off('click', '.file-thumbnail').on('click', '.file-thumbnail', function() {
                            var fileId = $(this).data('file-id');
                            var fileName = $(this).data('file-name') || upinvoiceimport_langs.FilePreview;
                            var fileType = $(this).data('file-type') || '';
                            var filePath = $(this).data('file-path');
                            
                            toggleFilePreview(fileId, fileName, fileType, filePath);
                        });
                        
                        if (upinvoiceimport_active_tab === 'pending') {
                            // Update the count badge on the tab
                            updatePendingCount(typeof result.count !== 'undefined' ? result.count : null);
                            // Re-apply the active search / status filter / sort to the fresh list
                            applyPendingFilters();
                        } else {
                            // Sync the current page with the server (it clamps out-of-range pages)
                            if (typeof result.page !== 'undefined') {
                                upinvFinishedPage = result.page;
                            }
                            renderFinishedPagination(result);
                        }

                        // Start automatic processing if we're in the pending tab and not already processing
                        if (upinvoiceimport_active_tab === 'pending' && !isProcessingActive) {
                            findAndProcessNextFile();
                        }
                    }
                } else {
                    // Si hay un error, mostrarlo pero mantener el contenido anterior
                    var errorMessage = '<div class="error-notification"><i class="fas fa-exclamation-circle"></i> ' + 
                        (result.message || upinvoiceimport_langs.ErrorProcessingResponse) + '</div>';
                    
                    if (upinvoiceimport_active_tab === 'pending') {
                        $('#pending-files-list').prepend(errorMessage);
                    } else {
                        $('#finished-files-list').prepend('<tr><td colspan="8" class="error-notification">' + 
                            errorMessage + '</td></tr>');
                    }
                }
            } catch (e) {
                console.error('Error parsing response', e);
                // Mostrar mensaje de error pero mantener el contenido anterior
                var errorMessage = '<div class="error-notification"><i class="fas fa-exclamation-circle"></i> ' + 
                    upinvoiceimport_langs.ErrorProcessingResponse + '</div>';
                
                if (upinvoiceimport_active_tab === 'pending') {
                    $('#pending-files-list').prepend(errorMessage);
                } else {
                    $('#finished-files-list').prepend('<tr><td colspan="8" class="error-notification">' + 
                        errorMessage + '</td></tr>');
                }
                
                // Eliminar los indicadores de carga
                $('.info-box, .loading-indicator').remove();
            }
        },
        error: function(xhr, status, error) {
            console.error('Load files failed', error);
            // Mostrar mensaje de error pero mantener el contenido anterior
            var errorMessage = '<div class="error-notification"><i class="fas fa-exclamation-circle"></i> ' + 
                upinvoiceimport_langs.LoadFilesFailed + ': ' + error + '</div>';
            
            if (upinvoiceimport_active_tab === 'pending') {
                $('#pending-files-list').prepend(errorMessage);
            } else {
                $('#finished-files-list').prepend('<tr><td colspan="8" class="error-notification">' + 
                    errorMessage + '</td></tr>');
            }
            
            // Eliminar los indicadores de carga
            $('.info-box, .loading-indicator').remove();
        }
    });
}

// Render prev/next + page indicator for the finished-files table
function renderFinishedPagination(result) {
    var $p = $('#finished-files-pagination');
    if (!$p.length) return;

    var page = result.page || 1;
    var pages = result.total_pages || 1;
    var total = typeof result.total !== 'undefined' ? result.total : null;

    if (pages <= 1) { $p.empty().hide(); return; }

    var html = '<button type="button" class="button button-pagelist" data-page="' + (page - 1) + '"'
        + (page <= 1 ? ' disabled' : '') + ' aria-label="prev"><i class="fas fa-chevron-left"></i></button>';
    html += '<span style="margin:0 10px;">' + page + ' / ' + pages
        + (total !== null ? ' <span class="opacitymedium">(' + total + ')</span>' : '') + '</span>';
    html += '<button type="button" class="button button-pagelist" data-page="' + (page + 1) + '"'
        + (page >= pages ? ' disabled' : '') + ' aria-label="next"><i class="fas fa-chevron-right"></i></button>';

    $p.html(html).show();
    $p.off('click', '.button-pagelist').on('click', '.button-pagelist', function() {
        var target = parseInt($(this).data('page'), 10);
        if (isNaN(target) || target < 1 || target > pages || target === upinvFinishedPage) return;
        upinvFinishedPage = target;
        loadFilesList();
    });
}

// =============================================================================
// Pending tab: count badge, search/filter/sort, selection, bulk + queue controls
// =============================================================================

// Small debounce helper
function _debounce(fn, wait) {
    var t = null;
    return function() {
        var ctx = this, args = arguments;
        clearTimeout(t);
        t = setTimeout(function() { fn.apply(ctx, args); }, wait);
    };
}

// Update the count badge shown on the "Pending files" tab
function updatePendingCount(count) {
    var $b = $('#pending-count-badge');
    if (!$b.length) return;
    $b.text((count && count > 0) ? count : '');
}

// Apply the active search text, status filter and sort to the loaded cards.
// All client-side over the already-loaded grid (no extra request).
function applyPendingFilters() {
    var $grid = $('#pending-files-list .files-grid');
    if (!$grid.length) { $('#pending-no-match').hide(); return; }

    var q = ($('#files-search').val() || '').trim().toLowerCase();
    var status = $('#files-status-filters .button-statusfilter.active').data('status') || 'all';
    var sort = $('#files-sort').val() || 'date_desc';

    var $cards = $grid.children('.file-card');
    var visible = 0;
    $cards.each(function() {
        var $c = $(this);
        var name = $c.attr('data-filename') || '';
        var state = $c.attr('data-state') || 'pending';
        var matchQ = !q || name.indexOf(q) !== -1;
        var matchS = (status === 'all')
            || (status === 'pending' && (state === 'pending' || state === 'processing'))
            || (status === 'processed' && state === 'processed')
            || (status === 'error' && (state === 'error' || state === 'stuck'));
        var show = matchQ && matchS;
        $c.toggle(show);
        if (show) { visible++; }
    });

    // Reorder the DOM according to the chosen sort
    var sorted = $cards.get().sort(function(a, b) {
        var $a = $(a), $b = $(b);
        switch (sort) {
            case 'date_asc':   return (+$a.attr('data-date')) - (+$b.attr('data-date'));
            case 'name_asc':   return ($a.attr('data-filename') || '').localeCompare($b.attr('data-filename') || '');
            case 'size_desc':  return (+$b.attr('data-size')) - (+$a.attr('data-size'));
            case 'status_asc': return ($a.attr('data-state') || '').localeCompare($b.attr('data-state') || '');
            case 'date_desc':
            default:           return (+$b.attr('data-date')) - (+$a.attr('data-date'));
        }
    });
    $.each(sorted, function(i, el) { $grid.append(el); });

    $('#pending-no-match').toggle($cards.length > 0 && visible === 0);
}

// Process an explicit list of ids one after another (used by retry-errors).
// The global auto-queue is paused for the duration so the two drivers never
// fight over the same files.
function processFilesSequentially(ids, index, onDone) {
    index = index || 0;
    if (index >= ids.length) {
        if (typeof onDone === 'function') { onDone(); }
        if (typeof upinvoiceLoadFilesListFunction === 'function') { upinvoiceLoadFilesListFunction(); }
        return;
    }
    var id = ids[index];
    var $card = $('.file-card[data-file-id="' + id + '"]');
    var state = $card.attr('data-state');
    if (!$card.length || state === 'processed' || state === 'processing') {
        processFilesSequentially(ids, index + 1, onDone);
        return;
    }
    processFile(id, function() {
        processFilesSequentially(ids, index + 1, onDone);
    });
}

// Retry every card currently in error / stuck state
function retryErrors() {
    var ids = $('#pending-files-list .file-card[data-state="error"], #pending-files-list .file-card[data-state="stuck"]')
        .map(function() { return $(this).data('file-id'); }).get();
    if (!ids.length) { showNotification('<i class="fas fa-info-circle"></i> ' + upinvoiceimport_langs.NoFilesToProcess, 'info'); return; }
    var prevPaused = isQueuePaused;
    isQueuePaused = true;
    processFilesSequentially(ids, 0, function() {
        isQueuePaused = prevPaused;
        if (!isQueuePaused) { findAndProcessNextFile(); }
    });
}

// Pause / resume the automatic processing queue
function setQueuePaused(paused) {
    isQueuePaused = paused;
    var $btn = $('#queue-toggle-btn');
    if (paused) {
        $btn.addClass('is-paused').html('<i class="fas fa-play"></i> <span class="label">' + (upinvoiceimport_langs.ResumeQueue || 'Resume') + '</span>');
        showNotification('<i class="fas fa-pause"></i> ' + (upinvoiceimport_langs.QueuePausedNotice || ''), 'warning');
    } else {
        $btn.removeClass('is-paused').html('<i class="fas fa-pause"></i> <span class="label">' + (upinvoiceimport_langs.PauseQueue || 'Pause') + '</span>');
        findAndProcessNextFile();
    }
}

// Auto-refresh poller for the pending tab
function startPendingPolling() {
    stopPendingPolling();
    if (!$('#pending-files-list').length) return;
    _pendingPollTimer = setInterval(function() {
        if (typeof upinvoiceimport_active_tab === 'undefined' || upinvoiceimport_active_tab !== 'pending') return;
        if (document.hidden) return;            // tab not visible
        if (isProcessingActive) return;          // don't disrupt a card mid-process
        if (isDeleteConfirmationActive) return;  // a dialog is open
        loadFilesList(true);                     // silent refresh
    }, 20000);
}
function stopPendingPolling() {
    if (_pendingPollTimer) { clearInterval(_pendingPollTimer); _pendingPollTimer = null; }
}

// Wire up the pending-tab toolbar + selection (delegated, bound once)
function initPendingToolbar() {
    if (!$('#pending-toolbar').length) return;

    $('#files-search').on('input', _debounce(applyPendingFilters, 200));

    $('#files-status-filters').on('click', '.button-statusfilter', function() {
        $('#files-status-filters .button-statusfilter').removeClass('active');
        $(this).addClass('active');
        applyPendingFilters();
    });

    $('#files-sort').on('change', applyPendingFilters);

    $('#queue-toggle-btn').on('click', function() { setQueuePaused(!isQueuePaused); });
    $('#retry-errors-btn').on('click', retryErrors);
    $('#refresh-files-btn').on('click', function() { loadFilesList(); });

    // Keyboard access for the thumbnail preview (Enter / Space)
    $(document).on('keydown', '.file-thumbnail', function(e) {
        if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
            e.preventDefault();
            $(this).trigger('click');
        }
    });
}

// Document ready handler
$(document).ready(function() {
    // Initialize modal system
    UpInvoiceModal.init();

    // Register the loadFilesList function globally so it can be called from other scripts
    upinvoiceLoadFilesListFunction = loadFilesList;

    // Wire the pending toolbar (no-op on pages without it)
    initPendingToolbar();

    // Initial load of files list - this will trigger automatic processing if needed
    loadFilesList();

    // Periodic background refresh of the pending list
    startPendingPolling();

    // Load credits bar on page mount (uses PHP session cache, no forced refresh)
    upinvoiceLoadCreditsBar(false);

});
// =============================================================================
// UpInvoice Emails Tab functions
// =============================================================================

// Unix timestamp (seconds) of the data currently shown in the email list.
var _emailsLastTs = 0;

/**
 * Refresh the "Updated X ago" hint from _emailsLastTs. Called on load and by the ticker.
 */
function updateEmailsHint() {
    var $hint = $('#emails-cache-hint');
    if (!$hint.length || !_emailsLastTs) return;

    var diff = Math.max(0, Math.floor(Date.now() / 1000) - _emailsLastTs);
    var txt;
    if (diff < 60) {
        txt = upinvoiceimport_langs.EmailUpdatedJustNow || 'Updated just now';
    } else if (diff < 3600) {
        txt = (upinvoiceimport_langs.EmailUpdatedMinAgo || 'Updated %s min ago').replace('%s', Math.floor(diff / 60));
    } else {
        txt = (upinvoiceimport_langs.EmailUpdatedHAgo || 'Updated %s h ago').replace('%s', Math.floor(diff / 3600));
    }
    $hint.html('<i class="fas fa-clock"></i> ' + txt);
}

/**
 * Load or refresh the email list.
 * @param {boolean} force  If true, bypass the PHP session cache.
 */
function loadEmailsList(force) {
    if (typeof upinvoiceEmailsUrl === 'undefined') return;

    var $list = $('#emails-list');
    var $hint = $('#emails-cache-hint');
    $list.html('<div class="upinvoice-empty-state"><i class="fas fa-spinner fa-spin"></i><p>' + (upinvoiceimport_langs.LoadingEmails || 'Loading…') + '</p></div>');
    $hint.text('');

    $.ajax({
        url: upinvoiceEmailsUrl + (force ? '?force=1' : ''),
        type: 'GET',
        dataType: 'json',
        success: function(resp) {
            if (resp && resp.status === 'success') {
                $list.html(resp.html);
                _emailsLastTs = resp.ts ? parseInt(resp.ts, 10) : Math.floor(Date.now() / 1000);
                updateEmailsHint();
                _bindEmailListHandlers();
                applyEmailFilters();
            } else {
                var msg = (resp && resp.message) ? resp.message : (upinvoiceimport_langs.EmailListError || 'Error loading emails');
                $list.html('<div class="upinvoice-empty-state is-error"><i class="fas fa-exclamation-triangle"></i><p>' + msg + '</p></div>');
            }
        },
        error: function() {
            $list.html('<div class="upinvoice-empty-state is-error"><i class="fas fa-exclamation-triangle"></i><p>' + (upinvoiceimport_langs.EmailListError || 'Error loading emails') + '</p></div>');
        }
    });
}

/**
 * Apply the search + status filters to the currently rendered email rows.
 * Runs client-side over the table already in the DOM (no IMAP round-trip).
 */
function applyEmailFilters() {
    var $search = $('#emails-search');
    if (!$search.length) return; // emails tab not present

    var q = ($search.val() || '').toLowerCase().trim();
    var status = $('#emails-filters .button-statusfilter.active').data('status') || 'all';

    var $rows = $('#emails-list table tr').not('.liste_titre');
    var visible = 0;

    $rows.each(function() {
        var $r = $(this);
        var rowStatus = $r.attr('data-status') || '';
        if (!rowStatus) return; // skip rows without status (placeholders, etc.)

        var matchStatus = (status === 'all')
            || (status === 'pending' && (rowStatus === 'new' || rowStatus === 'partial'))
            || (status === rowStatus);
        var matchSearch = (q === '') || (($r.attr('data-search') || '').indexOf(q) !== -1);

        if (matchStatus && matchSearch) {
            $r.show();
            visible++;
        } else {
            $r.hide();
        }
    });

    // No-results placeholder (only when there are rows but none match)
    var $noRes = $('#emails-no-results');
    if (visible === 0 && $rows.filter('[data-status]').length > 0) {
        if (!$noRes.length) {
            $('#emails-list').append('<p id="emails-no-results" class="opacitymedium" style="margin-top:8px"></p>');
            $noRes = $('#emails-no-results');
        }
        $noRes.text(upinvoiceimport_langs.NoEmailsMatchFilter || 'No emails match the filter').show();
    } else if ($noRes.length) {
        $noRes.hide();
    }
}

/**
 * Attach event handlers to the dynamically rendered email list (delegation safe).
 */
function _bindEmailListHandlers() {
    var $list = $('#emails-list');

    // Refresh button
    $('#refresh-emails-btn').off('click').on('click', function() {
        loadEmailsList(true);
    });

    // Delete imported attachment chip
    $list.off('click', '.upinvoice-chip-delete-btn').on('click', '.upinvoice-chip-delete-btn', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var fileId = $btn.data('file-id');
        var confirmMsg = (upinvoiceimport_langs && upinvoiceimport_langs.ConfirmDeleteFile) ? upinvoiceimport_langs.ConfirmDeleteFile : 'Delete this attachment from the processing queue?';
        if (!confirm(confirmMsg)) return;

        $.ajax({
            url: upinvoiceDeleteFileUrl,
            type: 'POST',
            dataType: 'json',
            data: { token: upinvoiceimport_token, file_id: fileId },
            success: function(resp) {
                if (resp && resp.status === 'success') {
                    showNotification('<i class="fas fa-check-circle"></i> ' + (resp.message || 'Deleted'), 'success');
                    loadEmailsList(true);
                } else {
                    var errMsg = (resp && resp.message) ? resp.message : 'Error deleting file';
                    showNotification('<i class="fas fa-exclamation-circle"></i> ' + errMsg, 'error');
                }
            },
            error: function() {
                showNotification('<i class="fas fa-exclamation-circle"></i> Error deleting file', 'error');
            }
        });
    });

    // Import button
    $list.off('click', '.import-email-btn').on('click', '.import-email-btn', function() {
        var $btn = $(this);
        if ($btn.prop('disabled')) return;

        var uid         = $btn.data('uid');
        var folder      = $btn.data('folder');
        var uidvalidity = $btn.data('uidvalidity');
        var filenames   = $btn.data('filenames'); // restrict to non-blacklisted importables

        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');

        var importData = {
            token: upinvoiceimport_token,
            uid: uid,
            folder: folder,
            uidvalidity: uidvalidity
        };
        if (Array.isArray(filenames) && filenames.length) {
            importData.filenames = filenames;
        }

        $.ajax({
            url: upinvoiceImportEmailUrl,
            type: 'POST',
            dataType: 'json',
            data: importData,
            success: function(resp) {
                if (resp && resp.status === 'success') {
                    var msg = (upinvoiceimport_langs.ImportedNAttachments || 'Queued %s attachment(s)').replace('%s', resp.queued);
                    showNotification('<i class="fas fa-check-circle"></i> ' + msg, 'success');
                    // Refresh list so badges update
                    loadEmailsList(true);
                } else if (resp && resp.code === 'stale_list') {
                    showNotification('<i class="fas fa-exclamation-triangle"></i> ' + (upinvoiceimport_langs.StaleListRefresh || 'List outdated, refreshing…'), 'warning');
                    loadEmailsList(true);
                } else {
                    var errMsg = (resp && resp.message) ? resp.message : 'Error';
                    showNotification('<i class="fas fa-exclamation-circle"></i> ' + errMsg, 'error');
                    $btn.prop('disabled', false).html('<i class="fas fa-inbox"></i> ' + (upinvoiceimport_langs.ImportToProcessing || 'Import'));
                }
            },
            error: function() {
                showNotification('<i class="fas fa-exclamation-circle"></i> Error', 'error');
                $btn.prop('disabled', false).html('<i class="fas fa-inbox"></i> ' + (upinvoiceimport_langs.ImportToProcessing || 'Import'));
            }
        });
    });

    // Per-attachment import: queue only one attachment from an email
    $list.off('click', '.upinvoice-chip-import-btn').on('click', '.upinvoice-chip-import-btn', function(e) {
        e.preventDefault();
        var $btn = $(this);
        if ($btn.hasClass('is-busy')) return;
        var $icon = $btn.find('i');
        $btn.addClass('is-busy');
        $icon.removeClass('fa-file-import').addClass('fa-spinner fa-spin');

        $.ajax({
            url: upinvoiceImportEmailUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                token: upinvoiceimport_token,
                uid: $btn.data('uid'),
                folder: $btn.data('folder'),
                uidvalidity: $btn.data('uidvalidity'),
                filenames: [$btn.data('filename')]
            },
            success: function(resp) {
                if (resp && resp.status === 'success') {
                    var msg = (upinvoiceimport_langs.ImportedNAttachments || 'Queued %s attachment(s)').replace('%s', resp.queued);
                    showNotification('<i class="fas fa-check-circle"></i> ' + msg, 'success');
                    loadEmailsList(true);
                } else if (resp && resp.code === 'stale_list') {
                    showNotification('<i class="fas fa-exclamation-triangle"></i> ' + (upinvoiceimport_langs.StaleListRefresh || 'List outdated, refreshing…'), 'warning');
                    loadEmailsList(true);
                } else {
                    var errMsg = (resp && resp.message) ? resp.message : 'Error';
                    showNotification('<i class="fas fa-exclamation-circle"></i> ' + errMsg, 'error');
                    $btn.removeClass('is-busy');
                    $icon.removeClass('fa-spinner fa-spin').addClass('fa-file-import');
                }
            },
            error: function() {
                showNotification('<i class="fas fa-exclamation-circle"></i> Error', 'error');
                $btn.removeClass('is-busy');
                $icon.removeClass('fa-spinner fa-spin').addClass('fa-file-import');
            }
        });
    });

    // Bulk import: queue every pending email sequentially (reuses the per-email endpoint)
    $list.off('click', '.upinvoice-import-all-btn').on('click', '.upinvoice-import-all-btn', function() {
        var $btn = $(this);
        if ($btn.prop('disabled')) return;

        // Respect the active search/status filter: only import the visible pending rows
        var jobs = $('#emails-list tr:visible .import-email-btn').map(function() {
            var $r = $(this);
            return {
                uid: $r.data('uid'),
                folder: $r.data('folder'),
                uidvalidity: $r.data('uidvalidity'),
                filenames: $r.data('filenames') // non-blacklisted importables only
            };
        }).get();
        if (!jobs.length) return;

        $btn.prop('disabled', true);
        var total = jobs.length;
        var done = 0;
        var totalQueued = 0;

        function next() {
            if (done >= total) {
                var doneMsg = (upinvoiceimport_langs.BulkImportQueued || 'Queued %s attachment(s)').replace('%s', totalQueued);
                showNotification('<i class="fas fa-check-circle"></i> ' + doneMsg, 'success');
                loadEmailsList(true);
                return;
            }
            var job = jobs[done];
            $btn.html('<i class="fas fa-spinner fa-spin"></i> ' + (done + 1) + '/' + total);
            var jobData = {
                token: upinvoiceimport_token,
                uid: job.uid,
                folder: job.folder,
                uidvalidity: job.uidvalidity
            };
            if (Array.isArray(job.filenames) && job.filenames.length) {
                jobData.filenames = job.filenames;
            }
            $.ajax({
                url: upinvoiceImportEmailUrl,
                type: 'POST',
                dataType: 'json',
                data: jobData,
                complete: function(xhr) {
                    var resp = xhr.responseJSON;
                    if (resp && resp.status === 'success' && resp.queued) {
                        totalQueued += resp.queued;
                    }
                    done++;
                    next();
                }
            });
        }
        next();
    });
}

// Two rule sets share the same form/table machinery and the same server endpoint,
// differing only by DOM ids and the 'target' sent to email_rules.php.
//   import    -> auto-import rules (affect the collector)
//   blacklist -> listing-only hide rules (never affect the collector)
var RULESETS = {
    import: {
        target: 'import', editId: null, fmtClass: 'rule-format-chk', testTplKey: 'TestRuleResult',
        list: '#email-rules-list', badge: '#rules-count-badge',
        sender: '#rule-sender', subject: '#rule-subject', filename: '#rule-filename',
        add: '#add-rule-btn', test: '#test-rule-btn', cancel: '#cancel-rule-edit', testRes: '#rule-test-result'
    },
    blacklist: {
        target: 'blacklist', editId: null, fmtClass: 'bl-format-chk', testTplKey: 'TestBlacklistResult',
        list: '#blacklist-list', badge: '#blacklist-count-badge',
        sender: '#bl-sender', subject: '#bl-subject', filename: '#bl-filename',
        add: '#add-bl-btn', test: '#test-bl-btn', cancel: '#cancel-bl-edit', testRes: '#bl-test-result'
    }
};

/**
 * Load both rule lists (import + blacklist). Kept under the original name because
 * upload.php calls loadEmailRules() on tab activation.
 */
function loadEmailRules() {
    if (typeof upinvoiceEmailRulesUrl === 'undefined') return;
    loadRuleSet(RULESETS.import);
    loadRuleSet(RULESETS.blacklist);
}

function loadRuleSet(cfg) {
    $.ajax({
        url: upinvoiceEmailRulesUrl,
        type: 'POST',
        dataType: 'json',
        data: { token: upinvoiceimport_token, op: 'list', target: cfg.target },
        success: function(resp) {
            if (resp && resp.status === 'success') {
                $(cfg.list).html(resp.html);
                _bindRuleHandlers(cfg);
                var n = $(cfg.list + ' table tr').not('.liste_titre').length;
                $(cfg.badge).text(n > 0 ? '(' + n + ')' : '');
            }
        }
    });
}

function _resetRuleForm(cfg) {
    cfg.editId = null;
    $(cfg.sender).val('');
    $(cfg.subject).val('');
    $(cfg.filename).val('');
    $('.' + cfg.fmtClass).prop('checked', false);
    $('.' + cfg.fmtClass + '[value="pdf"]').prop('checked', true);
    $(cfg.add).html('<i class="fas fa-plus"></i> ' + (upinvoiceimport_langs.AddRule || 'Add rule'));
    $(cfg.cancel).hide();
}

function _ruleSelectedFormats(cfg) {
    var formats = [];
    $('.' + cfg.fmtClass + ':checked').each(function() { formats.push($(this).val()); });
    if (formats.length === 0) formats = ['pdf'];
    return formats;
}

function _bindRuleHandlers(cfg) {
    var $rulesDiv = $(cfg.list);

    // Add or update (same button, mode depends on cfg.editId)
    $(cfg.add).off('click').on('click', function() {
        var data = {
            token: upinvoiceimport_token,
            op: cfg.editId ? 'update' : 'add',
            target: cfg.target,
            sender_contains: $(cfg.sender).val(),
            subject_contains: $(cfg.subject).val(),
            filename_pattern: $(cfg.filename).val(),
            'formats[]': _ruleSelectedFormats(cfg)
        };
        if (cfg.editId) data.rule_id = cfg.editId;

        $.ajax({
            url: upinvoiceEmailRulesUrl,
            type: 'POST',
            dataType: 'json',
            data: data,
            success: function(resp) {
                if (resp && resp.status === 'success') {
                    $rulesDiv.html(resp.html);
                    _resetRuleForm(cfg);
                    _bindRuleHandlers(cfg);
                    var n = $(cfg.list + ' table tr').not('.liste_titre').length;
                    $(cfg.badge).text(n > 0 ? '(' + n + ')' : '');
                    showNotification('<i class="fas fa-check-circle"></i> ' + (resp.message || 'OK'), 'success');
                    // Blacklist changes alter which emails are shown
                    if (cfg.target === 'blacklist') { loadEmailsList(true); }
                } else {
                    showNotification('<i class="fas fa-exclamation-circle"></i> ' + ((resp && resp.message) ? resp.message : 'Error'), 'error');
                }
            }
        });
    });

    // Cancel edit
    $(cfg.cancel).off('click').on('click', function(e) {
        e.preventDefault();
        _resetRuleForm(cfg);
    });

    // Test: dry-run the current form values against the loaded email list
    $(cfg.test).off('click').on('click', function() {
        var $res = $(cfg.testRes);
        $res.show().removeClass('is-error').html('<i class="fas fa-spinner fa-spin"></i>');

        $.ajax({
            url: upinvoiceEmailRulesUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                token: upinvoiceimport_token,
                op: 'test',
                target: cfg.target,
                sender_contains: $(cfg.sender).val(),
                subject_contains: $(cfg.subject).val(),
                filename_pattern: $(cfg.filename).val(),
                'formats[]': _ruleSelectedFormats(cfg)
            },
            success: function(resp) {
                if (resp && resp.status === 'success') {
                    var tpl = upinvoiceimport_langs[cfg.testTplKey] || 'Matches %1$s attachment(s) in %2$s email(s)';
                    var txt = tpl.replace('%1$s', resp.att).replace('%2$s', resp.emails);
                    if (resp.samples && resp.samples.length) {
                        txt += ': ' + resp.samples.join(', ') + (resp.att > resp.samples.length ? '…' : '');
                    }
                    $res.html('<i class="fas fa-flask"></i> ' + txt);
                } else {
                    var m = (resp && resp.message) ? resp.message : (upinvoiceimport_langs.TestRuleNoList || 'Refresh the email list first');
                    $res.addClass('is-error').html('<i class="fas fa-exclamation-circle"></i> ' + m);
                }
            },
            error: function() {
                $res.addClass('is-error').html('<i class="fas fa-exclamation-circle"></i> Error');
            }
        });
    });

    // Edit: load values into the form and switch the button to "save" mode
    $rulesDiv.off('click', '.rule-edit-btn').on('click', '.rule-edit-btn', function() {
        var $b = $(this);
        cfg.editId = $b.data('rule-id');
        $(cfg.sender).val($b.attr('data-sender') || '');
        $(cfg.subject).val($b.attr('data-subject') || '');
        $(cfg.filename).val($b.attr('data-filename') || '');
        var firstFmt = $.trim(('' + ($b.attr('data-formats') || 'pdf')).split(',')[0]) || 'pdf';
        $('.' + cfg.fmtClass).prop('checked', false);
        var $fmtRadio = $('.' + cfg.fmtClass + '[value="' + firstFmt + '"]');
        ($fmtRadio.length ? $fmtRadio : $('.' + cfg.fmtClass + '[value="pdf"]')).prop('checked', true);
        $(cfg.add).html('<i class="fas fa-save"></i> ' + (upinvoiceimport_langs.SaveRule || 'Save'));
        $(cfg.cancel).show();
        $(cfg.sender).focus();
    });

    // Delete
    $rulesDiv.off('click', '.rule-delete-btn').on('click', '.rule-delete-btn', function() {
        var ruleId = $(this).data('rule-id');
        var confirmMsg = upinvoiceimport_langs.ConfirmDeleteRule || 'Delete this rule?';
        if (!confirm(confirmMsg)) return;

        $.ajax({
            url: upinvoiceEmailRulesUrl,
            type: 'POST',
            dataType: 'json',
            data: { token: upinvoiceimport_token, op: 'delete', target: cfg.target, rule_id: ruleId },
            success: function(resp) {
                if (resp && resp.status === 'success') {
                    $rulesDiv.html(resp.html);
                    _bindRuleHandlers(cfg);
                    var n = $(cfg.list + ' table tr').not('.liste_titre').length;
                    $(cfg.badge).text(n > 0 ? '(' + n + ')' : '');
                    showNotification('<i class="fas fa-check-circle"></i> ' + (resp.message || 'Deleted'), 'success');
                    if (cfg.target === 'blacklist') { loadEmailsList(true); }
                }
            }
        });
    });

    // Toggle status
    $rulesDiv.off('click', '.rule-toggle-btn').on('click', '.rule-toggle-btn', function() {
        var ruleId = $(this).data('rule-id');
        var status = $(this).data('status');

        $.ajax({
            url: upinvoiceEmailRulesUrl,
            type: 'POST',
            dataType: 'json',
            data: { token: upinvoiceimport_token, op: 'toggle', target: cfg.target, rule_id: ruleId, status: status },
            success: function(resp) {
                if (resp && resp.status === 'success') {
                    $rulesDiv.html(resp.html);
                    _bindRuleHandlers(cfg);
                    showNotification('<i class="fas fa-check-circle"></i> ' + (resp.message || 'Updated'), 'success');
                    if (cfg.target === 'blacklist') { loadEmailsList(true); }
                }
            }
        });
    });
}
