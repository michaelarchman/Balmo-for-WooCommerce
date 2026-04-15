/**
 * Balmo for WooCommerce — Admin JS
 * Obsługuje: Import CSV (pobieranie, walidacja, transzowanie),
 *            Mapowanie kolumn, Harmonogram, Historia.
 */
jQuery(function ($) {
    'use strict';

    var ajaxUrl = balmoAjax.ajaxUrl;
    var nonce   = balmoAjax.nonce;

    // =========================================================
    // STRONA: IMPORT CSV
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

    // --- Krok 1: Pobierz i zwaliduj plik ---

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

                // Pokaż przycisk bezpośredniego importu jeśli jest profil
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

    // --- Przejdź do mapowania ---
    $('#balmo-btn-to-mapping').on('click', function () {
        // Przekieruj do strony mapowania z parametrami w sessionStorage
        sessionStorage.setItem('balmo_import_state', JSON.stringify(importState));
        window.location.href = 'admin.php?page=balmo-mapping&from_import=1';
    });

    // --- Krok 2: Uruchom import ---
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
            source_url:  $('#balmo_csv_url').val() || '',
            import_mode: 'full'
        }, function (res) {
            $('#balmo-btn-start-import').prop('disabled', false).text('Uruchom import (wybrany profil)');

            if (!res.success) { alert('Błąd: ' + res.data); return; }

            importState.importId = res.data.import_id;

            $('#balmo-step-3').show();
            $('#balmo-prog-total').text(importState.totalRows);
            $('#balmo-prog-status').text('Oczekuje na Action Scheduler…');

            // Import działa w tle (Action Scheduler) — polluj status co 3s
            startPolling();
        });
    }

    var pollTimer = null;

    function startPolling() {
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(pollStatus, 3000);
        pollStatus(); // od razu pierwsze odpytanie
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
    // STRONA: MAPOWANIE KOLUMN
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

    // Auto-mapuj kolumny drop.seltu.pl
    $('#balmo-btn-automap').on('click', function () {
        if ($mapBody.find('tr').length > 0) {
            if (!confirm('Zastąpić istniejące mapowanie auto-mapą?')) return;
        }
        $mapBody.empty();
        var autoMap = window.balmoAutoMap || {};
        $.each(autoMap, function (csvCol, wcField) {
            addMappingRow(csvCol, wcField, '');
        });
    });

    // Dodaj wiersz ręcznie
    $('#balmo-btn-add-column').on('click', function () {
        addMappingRow('', '', '');
    });

    // Usuń wiersz
    $mapBody.on('click', '.balmo-btn-remove-row', function () {
        $(this).closest('tr').remove();
    });

    // Podgląd formuły
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

    // Zapisz profil
    $('#balmo-btn-save-mapping').on('click', function () {
        var id          = $('#balmo-mapping-id').val();
        var name        = $('#balmo-mapping-name').val().trim();
        var sourceType  = $('#balmo-mapping-source-type').val();
        var matchBy     = $('#balmo-mapping-match-by').val();
        var formula     = $('#balmo-price-formula').val().trim();

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

        $.post(ajaxUrl, {
            action:        'balmo_save_mapping',
            nonce:         nonce,
            id:            id,
            name:          name,
            source_type:   sourceType,
            match_by:      matchBy,
            price_formula: formula,
            column_map:    columnMap,
            overrides:     overrides,
            update_fields: updateFields
        }, function (res) {
            if (res.success) {
                $('#balmo-mapping-id').val(res.data.id);
                $('#balmo-mapping-form-title').text('Edytujesz: ' + name);
                $('#balmo-mapping-save-result').text('✓ ' + res.data.message);
                setTimeout(function () { location.reload(); }, 1000);
            } else {
                $('#balmo-mapping-save-result').text('✗ ' + res.data);
            }
        });
    });

    // Edytuj profil
    $(document).on('click', '.balmo-btn-edit-mapping', function () {
        var id = $(this).data('id');
        $.post(ajaxUrl, { action: 'balmo_load_mapping', nonce: nonce, id: id }, function (res) {
            if (!res.success) return;
            var d = res.data;
            $('#balmo-mapping-id').val(d.id);
            $('#balmo-mapping-name').val(d.name);
            $('#balmo-mapping-source-type').val(d.source_type);
            $('#balmo-mapping-match-by').val(d.match_by);
            $('#balmo-price-formula').val(d.price_formula || '');
            $('#balmo-mapping-form-title').text('Edytujesz: ' + d.name);

            $mapBody.empty();
            $.each(d.column_map, function (csvCol, wcField) {
                addMappingRow(csvCol, wcField, d.overrides[csvCol] || '');
            });

            $('input[name="balmo_update_fields[]"]').each(function () {
                $(this).prop('checked', d.update_fields.indexOf($(this).val()) !== -1);
            });

            $('html, body').animate({ scrollTop: $('#balmo-mapping-form-title').offset().top - 50 }, 300);
        });
    });

    // Usuń profil
    $(document).on('click', '.balmo-btn-delete-mapping', function () {
        var id   = $(this).data('id');
        var name = $(this).data('name');
        if (!confirm('Usunąć profil "' + name + '"?')) return;
        $.post(ajaxUrl, { action: 'balmo_delete_mapping', nonce: nonce, id: id }, function (res) {
            if (res.success) location.reload();
        });
    });

    // =========================================================
    // STRONA: HARMONOGRAM
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
    // STRONA: HISTORIA
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
