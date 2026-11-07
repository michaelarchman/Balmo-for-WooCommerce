/**
 * Balmo for WooCommerce — Admin JS
 * Handles: CSV import (fetch, validation, batching),
 *            Column mapping, Schedule, History.
 */
jQuery(function ($) {
    'use strict';

    var ajaxUrl = balmoAjax.ajaxUrl;
    var nonce   = balmoAjax.nonce;

    // =========================================================
    // PAGE: CSV IMPORT
    // =========================================================

    var importState = {
        filePath:   '',
        delimiter:  ',',
        sourceType: 'all',
        totalRows:  0,
        mappingId:  0,
        importId:   0,
        offset:     0,
        created:    0,
        updated:    0,
        skipped:    0,
        errors:     0
    };

    // --- Step 1: fetch and validate file ---

    $('#balmo-btn-fetch').on('click', function () {
        var url        = $('#balmo_csv_url').val().trim();
        var file       = $('#balmo_csv_file')[0];
        var sourceType = $('input[name="balmo_source_type"]:checked').val() || 'all';

        if (!url && (!file || !file.files.length)) {
            alert('Podaj URL lub wybierz plik CSV.');
            return;
        }

        var formData = new FormData();
        formData.append('action',      'balmo_fetch_csv');
        formData.append('nonce',       nonce);
        formData.append('source_type', sourceType);

        formData.append('mapping_id', $('#balmo_mapping_id').val() || '0');

        if (file && file.files.length) {
            formData.append('csv_file', file.files[0]);
        } else {
            formData.append('csv_url', url);
        }

        $('#balmo-fetch-spinner').css('visibility', 'visible');
        $('#balmo-btn-fetch').prop('disabled', true);
        $('#balmo-step-2').hide();
        $('#balmo-step-3').hide();

        $.ajax({
            url:         ajaxUrl,
            type:        'POST',
            data:        formData,
            processData: false,
            contentType: false,
            success: function (res) {
                $('#balmo-fetch-spinner').css('visibility', 'hidden');
                $('#balmo-btn-fetch').prop('disabled', false);

                if (!res.success) {
                    alert('Błąd: ' + res.data);
                    return;
                }

                var d = res.data;
                importState.filePath   = d.file_path;
                importState.delimiter  = d.delimiter;
                importState.sourceType = d.source_type;
                importState.totalRows  = d.total_rows;
                importState.mappingId  = parseInt($('#balmo_mapping_id').val()) || 0;

                renderValidationResult(d.validation, d.total_rows, d.columns);
                renderPreviewTable(d.columns, d.preview);

                $('#balmo-step-2').show();
                $('#balmo-step-2-actions').show();

                // Show direct import button if a profile is selected
                if (importState.mappingId > 0) {
                    $('#balmo-btn-start-import').show();
                } else {
                    $('#balmo-btn-start-import').hide();
                }
            },
            error: function () {
                $('#balmo-fetch-spinner').css('visibility', 'hidden');
                $('#balmo-btn-fetch').prop('disabled', false);
                alert('Błąd komunikacji z serwerem.');
            }
        });
    });

    function renderValidationResult(validation, totalRows, columns) {
        var html = '<p><strong>Wierszy danych:</strong> ' + totalRows
                 + ' | <strong>Kolumn:</strong> ' + columns.length + '</p>';

        if (validation.errors.length) {
            html += '<div class="notice notice-error inline"><p><strong>Błędy:</strong></p><ul>';
            validation.errors.forEach(function (e) { html += '<li>' + escHtml(e) + '</li>'; });
            html += '</ul></div>';
        }
        if (validation.warnings.length) {
            html += '<div class="notice notice-warning inline"><p><strong>Ostrzeżenia:</strong></p><ul>';
            validation.warnings.forEach(function (w) { html += '<li>' + escHtml(w) + '</li>'; });
            html += '</ul></div>';
        }
        if (!validation.errors.length && !validation.warnings.length) {
            html += '<div class="notice notice-success inline"><p>Plik wygląda poprawnie.</p></div>';
        }
        if (validation.info.length) {
            html += '<p class="description">' + escHtml(validation.info[0]) + '</p>';
        }

        $('#balmo-validation-result').html(html);
    }

    function renderPreviewTable(columns, preview) {
        if (!preview || !preview.length) {
            $('#balmo-preview-wrap').html('');
            return;
        }
        var html = '<h3>Podgląd (pierwsze ' + preview.length + ' wierszy)</h3>'
                 + '<table class="widefat striped"><thead><tr>';
        columns.forEach(function (col) {
            html += '<th>' + escHtml(col) + '</th>';
        });
        html += '</tr></thead><tbody>';
        preview.forEach(function (row) {
            html += '<tr>';
            columns.forEach(function (col) {
                var val = row[col] || '';
                if (val.length > 80) val = val.substr(0, 80) + '…';
                html += '<td>' + escHtml(val) + '</td>';
            });
            html += '</tr>';
        });
        html += '</tbody></table>';
        $('#balmo-preview-wrap').html(html);
    }

    // --- Go to mapping ---
    $('#balmo-btn-to-mapping').on('click', function () {
        // Redirect to the mapping page; state passed via sessionStorage
        sessionStorage.setItem('balmo_import_state', JSON.stringify(importState));
        window.location.href = 'admin.php?page=balmo-mapping&from_import=1';
    });

    // --- Step 2: start import ---
    $('#balmo-btn-start-import').on('click', function () {
        if (!importState.filePath) { alert('Brak pliku.'); return; }
        if (!importState.mappingId) { alert('Wybierz profil mapowania.'); return; }
        startImport();
    });

    function startImport() {
        $('#balmo-btn-start-import').prop('disabled', true).text('Startowanie…');

        $.post(ajaxUrl, {
            action:      'balmo_start_import',
            nonce:       nonce,
            file_path:   importState.filePath,
            delimiter:   importState.delimiter,
            source_type: importState.sourceType,
            mapping_id:  importState.mappingId,
            total_rows:  importState.totalRows,
            start_row:   parseInt($('#balmo_row_start').val(), 10) || 1,
            end_row:     parseInt($('#balmo_row_end').val(), 10) || 0,
            source_url:  $('#balmo_csv_url').val() || '',
            import_mode: 'full'
        }, function (res) {
            $('#balmo-btn-start-import').prop('disabled', false).text('Uruchom import (wybrany profil)');

            if (!res.success) { alert('Błąd: ' + res.data); return; }

            importState.importId = res.data.import_id;

            $('#balmo-step-3').show();
            $('#balmo-prog-total').text(importState.totalRows);
            $('#balmo-prog-status').text('Oczekuje na Action Scheduler…');

            // Import runs in the background (Action Scheduler): poll status every 3 s
            startPolling();
        });
    }

    var pollTimer = null;

    function startPolling() {
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(pollStatus, 3000);
        pollStatus(); // poll right away
    }

    function pollStatus() {
        if (!importState.importId) return;

        $.post(ajaxUrl, {
            action:    'balmo_import_status',
            nonce:     nonce,
            import_id: importState.importId
        }, function (res) {
            if (!res.success) return;
            var d = res.data;

            var total   = d.total_rows     || importState.totalRows || 1;
            var done    = d.processed_rows || 0;
            var pct     = Math.round(done / total * 100);
            var labels  = { pending:'Oczekuje…', running:'W toku…', done:'Zakończony ✓', error:'Błąd', cancelled:'Anulowany' };

            $('#balmo-prog-total').text(total);
            $('#balmo-prog-done').text(done);
            $('#balmo-prog-created').text(d.created_rows || 0);
            $('#balmo-prog-updated').text(d.updated_rows || 0);
            $('#balmo-prog-skipped').text(d.skipped_rows || 0);
            $('#balmo-prog-errors').text(d.error_rows    || 0);
            $('#balmo-prog-status').text(labels[d.status] || d.status);
            $('#balmo-progress-bar').val(pct);
            $('#balmo-progress-pct').text(pct + '%');

            if (d.status === 'done' || d.status === 'error' || d.status === 'cancelled') {
                clearInterval(pollTimer);
                pollTimer = null;
                if ((d.error_rows || 0) > 0) {
                    $('#balmo-import-errors').show();
                    loadImportErrors(importState.importId);
                }
            }
        });
    }

    function loadImportErrors(importId) {
        $.post(ajaxUrl, {
            action:    'balmo_history_errors',
            nonce:     nonce,
            import_id: importId
        }, function (res) {
            if (!res.success) return;
            var html = '<table class="widefat striped"><thead><tr><th>Wiersz</th><th>Błąd</th></tr></thead><tbody>';
            res.data.errors.forEach(function (e) {
                html += '<tr><td>' + e.row_number + '</td><td>' + escHtml(e.error_message) + '</td></tr>';
            });
            html += '</tbody></table>';
            if (!res.data.errors.length) html = '<p>Brak błędów.</p>';
            $('#balmo-errors-list').html(html);
        });
    }

    // =========================================================
    // PAGE: COLUMN MAPPING
    // =========================================================

    var $mapBody = $('#balmo-column-map-body');
    var rowTpl   = $('#balmo-column-row-tpl').html() || '';

    function addMappingRow(csvCol, wcField, override) {
        var html = rowTpl
            .replace('{{csv_col}}', escHtml(csvCol || ''));
        var $row = $(html);
        if (wcField) {
            $row.find('.balmo-wc-field').val(wcField);
        }
        if (override) {
            $row.find('.balmo-override').val(override);
        }
        $mapBody.append($row);
    }

    // Auto-map drop.seltu.pl columns (CSV, JSON or XML, depending on the selected format)
    $('#balmo-btn-automap').on('click', function () {
        if ($mapBody.find('tr').length > 0) {
            if (!confirm('Zastąpić istniejące mapowanie auto-mapą?')) return;
        }
        var format  = $('#balmo-mapping-format').val() || 'csv';
        var autoMap = (format === 'json' || format === 'xml' ? window.balmoAutoMapJson : window.balmoAutoMap) || {};
        $mapBody.empty();
        $.each(autoMap, function (csvCol, wcField) {
            addMappingRow(csvCol, wcField, '');
        });
    });

    $('#balmo-btn-add-column').on('click', function () {
        addMappingRow('', '', '');
    });

    $mapBody.on('click', '.balmo-btn-remove-row', function () {
        $(this).closest('tr').remove();
    });

    $('#balmo-btn-preview-formula').on('click', function () {
        var formula = $('#balmo-price-formula').val().trim();
        if (!formula) return;
        $.post(ajaxUrl, {
            action:     'balmo_preview_formula',
            nonce:      nonce,
            formula:    formula,
            test_price: 64.58,
            test_vat:   23
        }, function (res) {
            if (res.success) {
                $('#balmo-formula-preview').text(' → ' + res.data.result);
            } else {
                $('#balmo-formula-preview').text(' ✗ ' + res.data);
            }
        });
    });

    $('#balmo-mapping-integration').on('change', function () {
        $('#balmo-mapping-integration-new').toggle($(this).val() === 'new');
    });

    $('#balmo-btn-save-mapping').on('click', function () {
        var id          = $('#balmo-mapping-id').val();
        var name            = $('#balmo-mapping-name').val().trim();
        var sourceType      = $('#balmo-mapping-source-type').val();
        var matchBy         = $('#balmo-mapping-match-by').val();
        var formula         = $('#balmo-price-formula').val().trim();
        var imageUrlPrefix  = $('#balmo-image-url-prefix').val().trim();
        var sourceCurrency  = $('#balmo-source-currency').val();
        var groupVariants   = $('#balmo-group-variants').is(':checked') ? 1 : 0;
        var variantSuffixes = $('#balmo-variant-suffixes').val();
        var integrationId   = $('#balmo-mapping-integration').val();
        var integrationNew  = $('#balmo-mapping-integration-new').val().trim();

        if (!name) { alert('Podaj nazwę profilu.'); return; }

        var columnMap   = {};
        var overrides   = {};
        var updateFields = [];

        $mapBody.find('.balmo-map-row').each(function () {
            var csvCol   = $(this).find('.balmo-csv-col').val().trim();
            var wcField  = $(this).find('.balmo-wc-field').val();
            var override = $(this).find('.balmo-override').val().trim();

            if (csvCol && wcField) {
                columnMap[csvCol] = wcField;
                if (override !== '') {
                    overrides[csvCol] = override;
                }
            }
        });

        $('input[name="balmo_update_fields[]"]:checked').each(function () {
            updateFields.push($(this).val());
        });

        var format = $('#balmo-mapping-format').val() || 'csv';

        $.post(ajaxUrl, {
            action:        'balmo_save_mapping',
            nonce:         nonce,
            id:            id,
            name:          name,
            format:        format,
            source_type:   sourceType,
            match_by:      matchBy,
            price_formula:    formula,
            image_url_prefix: imageUrlPrefix,
            column_map:       columnMap,
            overrides:        overrides,
            update_fields:    updateFields,
            source_currency:  sourceCurrency,
            group_variants:   groupVariants,
            variant_suffixes: variantSuffixes,
            integration_id:   integrationId,
            integration_new:  integrationNew
        }, function (res) {
            if (res.success) {
                if (typeof res.data.id !== 'undefined') {
                    $('#balmo-mapping-id').val(res.data.id);
                    window.location.href = 'admin.php?page=balmo-mapping&action=edit&id=' + res.data.id;
                    return;
                }
                $('#balmo-mapping-save-result').text('✓ ' + res.data.message);
            } else {
                $('#balmo-mapping-save-result').text('✗ ' + res.data);
            }
        });
    });

    // Load existing mapping on the edit page (data injected by PHP)
    if (window.balmoEditMapping) {
        var d = window.balmoEditMapping;
        $.each(d.column_map, function (csvCol, wcField) {
            addMappingRow(csvCol, wcField, d.overrides[csvCol] || '');
        });
    }

    $(document).on('click', '.balmo-btn-delete-mapping', function () {
        var id   = $(this).data('id');
        var name = $(this).data('name');
        if (!confirm('Usunąć profil "' + name + '"?')) return;
        $.post(ajaxUrl, { action: 'balmo_delete_mapping', nonce: nonce, id: id }, function (res) {
            if (res.success) location.reload();
        });
    });

    // =========================================================
    // PAGE: SCHEDULE
    // =========================================================

    $('#balmo-btn-save-schedule').on('click', function () {
        var name       = $('#balmo-sched-name').val().trim();
        var url        = $('#balmo-sched-url').val().trim();
        var sourceType = $('#balmo-sched-source-type').val();
        var mappingId  = $('#balmo-sched-mapping').val();
        var interval   = $('#balmo-sched-interval').val();
        var runTime    = $('#balmo-sched-time').val();
        var importMode = $('#balmo-sched-import-mode').val();

        if (!name || !url) { alert('Podaj nazwę i URL.'); return; }

        $.post(ajaxUrl, {
            action:      'balmo_save_schedule',
            nonce:       nonce,
            name:        name,
            url:         url,
            source_type: sourceType,
            mapping_id:  mappingId,
            interval:    interval,
            run_time:    runTime,
            import_mode: importMode
        }, function (res) {
            if (res.success) {
                $('#balmo-schedule-save-result').text('✓ ' + res.data.message);
                setTimeout(function () { location.reload(); }, 800);
            } else {
                $('#balmo-schedule-save-result').text('✗ ' + res.data);
            }
        });
    });

    $(document).on('click', '.balmo-btn-run-now', function () {
        var id = $(this).data('id');
        var $btn = $(this);
        $btn.prop('disabled', true).text('Uruchamianie…');
        $.post(ajaxUrl, { action: 'balmo_run_schedule_now', nonce: nonce, id: id }, function (res) {
            if (res.success) {
                alert('Import uruchomiony (ID: ' + res.data.import_id + '). Zobacz historię.');
            } else {
                alert('Błąd: ' + res.data);
            }
            $btn.prop('disabled', false).text('Uruchom teraz');
        });
    });

    $(document).on('click', '.balmo-btn-delete-schedule', function () {
        var id   = $(this).data('id');
        var name = $(this).data('name');
        if (!confirm('Usunąć harmonogram "' + name + '"?')) return;
        $.post(ajaxUrl, { action: 'balmo_delete_schedule', nonce: nonce, id: id }, function (res) {
            if (res.success) location.reload();
        });
    });

    // =========================================================
    // PAGE: HISTORY
    // =========================================================

    $(document).on('click', '.balmo-btn-show-errors', function () {
        var importId = $(this).data('id');
        $('#balmo-modal-import-id').text('#' + importId);
        $('#balmo-modal-errors-list').html('<p>Ładowanie…</p>');
        $('#balmo-modal-overlay').show();
        $('#balmo-errors-modal').show();
        loadImportErrors(importId);
    });

    function loadImportErrors(importId) {
        $.post(ajaxUrl, { action: 'balmo_history_errors', nonce: nonce, import_id: importId }, function (res) {
            if (!res.success) return;
            var html = '<table class="widefat striped"><thead><tr><th>Wiersz</th><th>Błąd</th></tr></thead><tbody>';
            res.data.errors.forEach(function (e) {
                html += '<tr><td>' + e.row_number + '</td><td>' + escHtml(e.error_message) + '</td></tr>';
            });
            html += '</tbody></table>';
            if (!res.data.errors.length) html = '<p>Brak błędów.</p>';
            $('#balmo-modal-errors-list').html(html);
        });
    }

    $('#balmo-modal-close, #balmo-modal-overlay').on('click', function () {
        $('#balmo-errors-modal').hide();
        $('#balmo-modal-overlay').hide();
    });

    $(document).on('click', '.balmo-btn-cancel', function () {
        var id = $(this).data('id');
        if (!confirm('Anulować ten import?')) return;
        $.post(ajaxUrl, { action: 'balmo_cancel_import', nonce: nonce, import_id: id }, function (res) {
            if (res.success) location.reload();
        });
    });

    $('#balmo-btn-purge-logs').on('click', function () {
        if (!confirm('Usunąć stare logi?')) return;
        $.post(ajaxUrl, { action: 'balmo_purge_logs', nonce: nonce }, function (res) {
            if (res.success) location.reload();
        });
    });

    // =========================================================
    // HELPERS
    // =========================================================

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
});
