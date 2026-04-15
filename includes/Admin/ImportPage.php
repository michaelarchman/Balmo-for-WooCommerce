<?php

namespace Balmo\Admin;

if (!defined('ABSPATH')) exit;

use Balmo\Service\CsvFetcher;
use Balmo\Service\CsvValidator;

class ImportPage
{
    public static function init(): void
    {
        add_action('wp_ajax_balmo_fetch_csv',    [self::class, 'ajaxFetchCsv']);
        add_action('wp_ajax_balmo_start_import', [self::class, 'ajaxStartImport']);
        add_action('wp_ajax_balmo_import_status',[self::class, 'ajaxImportStatus']);
    }

    public static function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Brak uprawnień.', 'balmo'));
        }

        $mappings = self::getMappingOptions();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Import CSV — Balmo', 'balmo'); ?></h1>

            <!-- KROK 1: Źródło pliku -->
            <div id="balmo-step-1" class="balmo-step">
                <h2><?php esc_html_e('Krok 1: Źródło pliku CSV', 'balmo'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><?php esc_html_e('Typ pliku', 'balmo'); ?></th>
                        <td>
                            <label>
                                <input type="radio" name="balmo_source_type" value="all" checked>
                                <?php esc_html_e('ALL — pełne dane produktów', 'balmo'); ?>
                            </label>
                            &nbsp;&nbsp;
                            <label>
                                <input type="radio" name="balmo_source_type" value="ica">
                                <?php esc_html_e('ICA — tylko cena/stan/aktywność', 'balmo'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('URL pliku', 'balmo'); ?></th>
                        <td>
                            <input type="url" id="balmo_csv_url" class="regular-text"
                                   placeholder="https://drop.seltu.pl/products/colibra/csv/">
                            <p class="description"><?php esc_html_e('Lub prześlij plik poniżej.', 'balmo'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Prześlij plik', 'balmo'); ?></th>
                        <td>
                            <input type="file" id="balmo_csv_file" accept=".csv,.txt">
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Profil mapowania', 'balmo'); ?></th>
                        <td>
                            <select id="balmo_mapping_id">
                                <option value=""><?php esc_html_e('— brak (wybierz po załadowaniu) —', 'balmo'); ?></option>
                                <?php foreach ($mappings as $m): ?>
                                    <option value="<?php echo esc_attr($m->id); ?>">
                                        <?php echo esc_html($m->name); ?>
                                        (<?php echo esc_html(strtoupper($m->source_type)); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
                <button type="button" id="balmo-btn-fetch" class="button button-primary">
                    <?php esc_html_e('Pobierz i sprawdź plik', 'balmo'); ?>
                </button>
                <span id="balmo-fetch-spinner" class="spinner" style="float:none;visibility:hidden;"></span>
            </div>

            <!-- KROK 2: Wynik walidacji + podgląd -->
            <div id="balmo-step-2" class="balmo-step" style="display:none;">
                <h2><?php esc_html_e('Krok 2: Wynik walidacji', 'balmo'); ?></h2>
                <div id="balmo-validation-result"></div>
                <div id="balmo-preview-wrap" style="overflow-x:auto;"></div>
                <div id="balmo-step-2-actions" style="margin-top:15px;display:none;">
                    <button type="button" id="balmo-btn-to-mapping" class="button">
                        <?php esc_html_e('Przejdź do mapowania', 'balmo'); ?>
                    </button>
                    &nbsp;
                    <button type="button" id="balmo-btn-start-import" class="button button-primary" style="display:none;">
                        <?php esc_html_e('Uruchom import (wybrany profil)', 'balmo'); ?>
                    </button>
                </div>
            </div>

            <!-- KROK 3: Postęp importu -->
            <div id="balmo-step-3" class="balmo-step" style="display:none;">
                <h2><?php esc_html_e('Krok 3: Import w toku', 'balmo'); ?></h2>
                <table class="widefat striped" style="max-width:500px;">
                    <tr>
                        <td><?php esc_html_e('Łącznie wierszy', 'balmo'); ?></td>
                        <td><strong id="balmo-prog-total">—</strong></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e('Przetworzono', 'balmo'); ?></td>
                        <td><strong id="balmo-prog-done">0</strong></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e('Utworzono', 'balmo'); ?></td>
                        <td><strong id="balmo-prog-created">0</strong></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e('Zaktualizowano', 'balmo'); ?></td>
                        <td><strong id="balmo-prog-updated">0</strong></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e('Pominięto', 'balmo'); ?></td>
                        <td><strong id="balmo-prog-skipped">0</strong></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e('Błędy', 'balmo'); ?></td>
                        <td><strong id="balmo-prog-errors">0</strong></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e('Status', 'balmo'); ?></td>
                        <td><strong id="balmo-prog-status">—</strong></td>
                    </tr>
                </table>
                <div style="margin-top:10px;">
                    <progress id="balmo-progress-bar" value="0" max="100" style="width:100%;max-width:500px;"></progress>
                    <span id="balmo-progress-pct">0%</span>
                </div>
                <div id="balmo-import-errors" style="margin-top:10px;display:none;">
                    <h3><?php esc_html_e('Błędy importu', 'balmo'); ?></h3>
                    <div id="balmo-errors-list" style="max-height:200px;overflow-y:auto;"></div>
                </div>
                <div style="margin-top:15px;">
                    <a id="balmo-history-link" href="<?php echo esc_url(admin_url('admin.php?page=balmo-history')); ?>" class="button">
                        <?php esc_html_e('Zobacz historię', 'balmo'); ?>
                    </a>
                </div>
            </div>

        </div><!-- .wrap -->
        <?php
    }

    // --- AJAX: Pobierz i zwaliduj plik CSV ---

    public static function ajaxFetchCsv(): void
    {
        check_ajax_referer('balmo_ajax', 'nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('Brak uprawnień.');

        $source_type = sanitize_text_field($_POST['source_type'] ?? 'all');
        $url         = esc_url_raw(trim($_POST['csv_url'] ?? ''));

        // Upload lub URL
        if (!empty($_FILES['csv_file']['tmp_name'])) {
            $result = CsvFetcher::fromUpload($_FILES['csv_file'], $source_type);
        } elseif (!empty($url)) {
            $result = CsvFetcher::fromUrl($url, $source_type);
        } else {
            wp_send_json_error(__('Podaj URL lub prześlij plik CSV.', 'balmo'));
            return;
        }

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
            return;
        }

        // Walidacja
        $validation = CsvValidator::validate($result['file_path'], $result['delimiter'], $source_type);

        wp_send_json_success([
            'file_path'   => $result['file_path'],
            'delimiter'   => $result['delimiter'],
            'source_type' => $source_type,
            'total_rows'  => $result['total_rows'],
            'columns'     => $result['columns'],
            'preview'     => $result['preview'],
            'validation'  => $validation,
        ]);
    }

    // --- AJAX: Rozpocznij import ---
    // Zapisuje rekord do DB, zleca przetwarzanie WP-Cron. Przeglądarka tylko monitoruje.

    public static function ajaxStartImport(): void
    {
        check_ajax_referer('balmo_ajax', 'nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('Brak uprawnień.');

        global $wpdb;

        $file_path   = sanitize_text_field($_POST['file_path']   ?? '');
        $delimiter   = sanitize_text_field($_POST['delimiter']   ?? ',');
        $source_type = sanitize_text_field($_POST['source_type'] ?? 'all');
        $mapping_id  = (int) ($_POST['mapping_id'] ?? 0);
        $match_by    = sanitize_text_field($_POST['match_by']    ?? 'sku');
        $import_mode = sanitize_text_field($_POST['import_mode'] ?? 'full');
        $total_rows  = (int) ($_POST['total_rows'] ?? 0);

        // Weryfikacja ścieżki — tylko pliki w katalogu uploads/balmo/
        if (!self::isAllowedFilePath($file_path)) {
            wp_send_json_error(__('Nieprawidłowa ścieżka pliku.', 'balmo'));
            return;
        }

        // Pobierz match_by z profilu jeśli jest
        if ($mapping_id) {
            $mapping_match = $wpdb->get_var($wpdb->prepare(
                "SELECT match_by FROM " . \Balmo\table_mappings() . " WHERE id = %d", $mapping_id
            ));
            if ($mapping_match) $match_by = $mapping_match;
        }

        $wpdb->insert(
            \Balmo\table_import_log(),
            [
                'mapping_id'  => $mapping_id ?: null,
                'source_url'  => sanitize_text_field($_POST['source_url'] ?? ''),
                'source_type' => $source_type,
                'import_mode' => $import_mode,
                'match_by'    => $match_by,
                'status'      => 'pending',
                'total_rows'  => $total_rows,
                'file_path'   => $file_path,
                'delimiter'   => $delimiter,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );

        $import_id = (int) $wpdb->insert_id;
        if (!$import_id) {
            $db_error = $wpdb->last_error ?: __('Nieznany błąd bazy danych.', 'balmo');
            wp_send_json_error(sprintf(__('Nie można utworzyć rekordu importu: %s', 'balmo'), $db_error));
            return;
        }

        // Sprawdź czy Action Scheduler jest dostępny (bundlowany z WooCommerce)
        if (!function_exists('as_enqueue_async_action')) {
            $wpdb->update(table_import_log(), ['status' => 'error'], ['id' => $import_id]);
            wp_send_json_error(__('Brak Action Scheduler. Wymagane WooCommerce 3.5+.', 'balmo'));
            return;
        }

        // Enqueue pierwszej transzy — AS przetwarza w tle, przeglądarka tylko monitoruje
        as_enqueue_async_action('balmo_as_batch', [$import_id, 0], 'balmo');

        wp_send_json_success([
            'import_id'  => $import_id,
            'total_rows' => $total_rows,
        ]);
    }

    // --- AJAX: Przetwórz jedną transzę (dane pliku czytane z DB) ---

    public static function ajaxProcessBatch(): void
    {
        check_ajax_referer('balmo_ajax', 'nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('Brak uprawnień.');

        $import_id = (int) ($_POST['import_id'] ?? 0);
        $offset    = (int) ($_POST['offset']    ?? 0);

        if (!$import_id) {
            wp_send_json_error(__('Brak import_id.', 'balmo'));
            return;
        }

        global $wpdb;
        $log = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . \Balmo\table_import_log() . " WHERE id = %d", $import_id
        ));

        if (!$log) {
            wp_send_json_error(__('Import nie istnieje.', 'balmo'));
            return;
        }
        if ($log->status === 'cancelled') {
            wp_send_json_error(__('Import został anulowany.', 'balmo'));
            return;
        }

        // Przy pierwszej transzy ustaw status na running
        if ($log->status === 'pending') {
            $wpdb->update(
                \Balmo\table_import_log(),
                ['status' => 'running', 'started_at' => current_time('mysql')],
                ['id' => $import_id],
                ['%s', '%s'], ['%d']
            );
            $log->status = 'running';
        }

        $file_path = $log->file_path ?? '';
        $delimiter = $log->delimiter ?? ',';

        if (!self::isAllowedFilePath($file_path)) {
            $wpdb->update(\Balmo\table_import_log(), ['status' => 'error', 'finished_at' => current_time('mysql')], ['id' => $import_id]);
            wp_send_json_error(__('Plik CSV nie istnieje lub ścieżka jest nieprawidłowa. Pobierz plik ponownie.', 'balmo'));
            return;
        }

        $mapping = null;
        if ($log->mapping_id) {
            $mapping = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM " . \Balmo\table_mappings() . " WHERE id = %d", (int) $log->mapping_id
            ));
        }

        $batch_size = (int) get_option('balmo_batch_size', BALMO_BATCH_SIZE);
        $importer   = new \Balmo\Service\ProductImporter($import_id, $log, $mapping);
        $result     = $importer->processBatch($file_path, $delimiter, $offset, $batch_size);

        $next_offset = $result['next_offset'];
        $is_done     = $next_offset >= (int) $log->total_rows;

        $wpdb->query($wpdb->prepare(
            "UPDATE " . \Balmo\table_import_log() . "
             SET processed_rows = processed_rows + %d,
                 created_rows   = created_rows   + %d,
                 updated_rows   = updated_rows   + %d,
                 skipped_rows   = skipped_rows   + %d,
                 error_rows     = error_rows     + %d,
                 current_offset = %d,
                 status         = %s,
                 finished_at    = %s
             WHERE id = %d",
            $result['processed'], $result['created'], $result['updated'],
            $result['skipped'],   $result['errors'],  $next_offset,
            $is_done ? 'done' : 'running',
            $is_done ? current_time('mysql') : null,
            $import_id
        ));

        if ($is_done) {
            @unlink($file_path);
        }

        wp_send_json_success([
            'import_id'   => $import_id,
            'next_offset' => $next_offset,
            'is_done'     => $is_done,
            'processed'   => $result['processed'],
            'created'     => $result['created'],
            'updated'     => $result['updated'],
            'skipped'     => $result['skipped'],
            'errors'      => $result['errors'],
        ]);
    }

    // Weryfikacja ścieżki pliku — tylko katalog uploads/balmo/
    private static function isAllowedFilePath(string $file_path): bool
    {
        if (empty($file_path)) return false;
        $real = realpath($file_path);
        if (!$real) return false;
        $upload_dir = wp_upload_dir();
        $allowed    = realpath($upload_dir['basedir'] . '/balmo');
        if (!$allowed) return false;
        // Normalizuj separatory — Windows używa \, Linux /
        $real    = str_replace('\\', '/', $real);
        $allowed = str_replace('\\', '/', $allowed);
        return strpos($real, $allowed) === 0;
    }

    // --- AJAX: Status importu (do pollingu z harmonogramu) ---

    public static function ajaxImportStatus(): void
    {
        check_ajax_referer('balmo_ajax', 'nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('Brak uprawnień.');

        $import_id = (int) ($_POST['import_id'] ?? 0);

        global $wpdb;
        $log = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM " . \Balmo\table_import_log() . " WHERE id = %d", $import_id)
        );

        if (!$log) {
            wp_send_json_error(__('Import nie istnieje.', 'balmo'));
            return;
        }

        if ($log->status === 'running' && function_exists('\Balmo\get_import_heartbeat')) {
            $heartbeat = \Balmo\get_import_heartbeat($import_id);
            $last_seen = (int) ($heartbeat['time'] ?? 0);

            if ($last_seen > 0 && (time() - $last_seen) > \Balmo\BALMO_HEARTBEAT_TTL) {
                $resume_offset = isset($heartbeat['offset'])
                    ? (int) $heartbeat['offset']
                    : (int) $log->current_offset;

                if (function_exists('as_enqueue_async_action')) {
                    \Balmo\mark_import_heartbeat($import_id, $resume_offset, $heartbeat);
                    as_enqueue_async_action('balmo_as_batch', [$import_id, $resume_offset], 'balmo');
                }
            }
        }

        wp_send_json_success([
            'status'         => $log->status,
            'total_rows'     => (int) $log->total_rows,
            'processed_rows' => (int) $log->processed_rows,
            'created_rows'   => (int) $log->created_rows,
            'updated_rows'   => (int) $log->updated_rows,
            'skipped_rows'   => (int) $log->skipped_rows,
            'error_rows'     => (int) $log->error_rows,
        ]);
    }

    // --- Helpers ---

    private static function getMappingOptions(): array
    {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT id, name, source_type FROM " . \Balmo\table_mappings() . " ORDER BY name ASC"
        ) ?: [];
    }
}
