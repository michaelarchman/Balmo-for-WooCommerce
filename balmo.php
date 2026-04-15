<?php
/**
 * Plugin Name: Balmo for WooCommerce
 * Description: Import produktów CSV z mapowaniem kolumn, formułami cenowymi i harmonogramem. Obsługa do 20 000 produktów w transzach.
 * Version:     1.0.0
 * Author:      Michał Łuczak
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * WC requires at least: 4.0
 * Text Domain: balmo
 */

namespace Balmo;

if (!defined('ABSPATH')) exit;

use Balmo\Admin\ImportPage;
use Balmo\Admin\MappingPage;
use Balmo\Admin\SchedulerPage;
use Balmo\Admin\HistoryPage;
use Balmo\Admin\Settings;
use Balmo\Cron\ImportScheduler;

define('BALMO_VERSION',     '1.0.0');
define('BALMO_PLUGIN_FILE', __FILE__);
define('BALMO_PLUGIN_DIR',  plugin_dir_path(__FILE__));
define('BALMO_PLUGIN_URL',  plugin_dir_url(__FILE__));

// Rozmiar pojedynczej transzy przy imporcie (wiersze CSV)
define('BALMO_BATCH_SIZE', 200);
define('BALMO_ASYNC_BATCH_SIZE', 25);
define('BALMO_BATCH_TIME_LIMIT', 20);
define('BALMO_HEARTBEAT_TTL', 180);

spl_autoload_register(function (string $class): void {
    if (strpos($class, 'Balmo\\') !== 0) return;
    $file = BALMO_PLUGIN_DIR . 'includes/' . str_replace(['Balmo\\', '\\'], ['', DIRECTORY_SEPARATOR], $class) . '.php';
    if (file_exists($file)) require_once $file;
});

// --- Tabele bazy danych ---

function table_import_log(): string
{
    global $wpdb;
    return $wpdb->prefix . 'balmo_import_log';
}

function table_import_errors(): string
{
    global $wpdb;
    return $wpdb->prefix . 'balmo_import_errors';
}

function table_mappings(): string
{
    global $wpdb;
    return $wpdb->prefix . 'balmo_mappings';
}

function activate(): void
{
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();

    // Historia importów — jeden rekord na każde uruchomienie
    dbDelta("
        CREATE TABLE " . table_import_log() . " (
            id             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
            mapping_id     BIGINT UNSIGNED  NULL,
            source_url     TEXT             NULL,
            source_type    VARCHAR(10)      NOT NULL DEFAULT 'all',
            import_mode    VARCHAR(10)      NOT NULL DEFAULT 'full',
            match_by       VARCHAR(10)      NOT NULL DEFAULT 'sku',
            status         VARCHAR(10)      NOT NULL DEFAULT 'pending',
            total_rows     INT UNSIGNED     NOT NULL DEFAULT 0,
            processed_rows INT UNSIGNED     NOT NULL DEFAULT 0,
            created_rows   INT UNSIGNED     NOT NULL DEFAULT 0,
            updated_rows   INT UNSIGNED     NOT NULL DEFAULT 0,
            skipped_rows   INT UNSIGNED     NOT NULL DEFAULT 0,
            error_rows     INT UNSIGNED     NOT NULL DEFAULT 0,
            current_offset INT UNSIGNED     NOT NULL DEFAULT 0,
            file_path      TEXT             NULL,
            delimiter      VARCHAR(5)       NOT NULL DEFAULT ',',
            started_at     DATETIME         NULL,
            finished_at    DATETIME         NULL,
            created_at     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status (status),
            KEY idx_created_at (created_at)
        ) {$charset};
    ");

    // Błędy per wiersz CSV — szczegółowy log
    dbDelta("
        CREATE TABLE " . table_import_errors() . " (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            import_id     BIGINT UNSIGNED NOT NULL,
            row_number    INT UNSIGNED    NOT NULL,
            row_data      LONGTEXT        NULL,
            error_message TEXT            NOT NULL,
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_import_id (import_id)
        ) {$charset};
    ");

    // Profile mapowania kolumn
    dbDelta("
        CREATE TABLE " . table_mappings() . " (
            id            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
            name          VARCHAR(255)     NOT NULL,
            source_type   VARCHAR(10)      NOT NULL DEFAULT 'all',
            column_map    LONGTEXT         NOT NULL,
            overrides     LONGTEXT         NULL,
            price_formula VARCHAR(500)     NULL,
            match_by      VARCHAR(10)      NOT NULL DEFAULT 'sku',
            update_fields LONGTEXT         NULL,
            created_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) {$charset};
    ");

    // Folder na tymczasowe pliki CSV
    $upload_dir = wp_upload_dir();
    $balmo_dir  = $upload_dir['basedir'] . '/balmo';
    if (!file_exists($balmo_dir)) {
        wp_mkdir_p($balmo_dir);
        file_put_contents($balmo_dir . '/.htaccess', 'deny from all');
    }

    // Domyślne ustawienia
    add_option('balmo_batch_size',       200);
    add_option('balmo_image_import',     '0');
    add_option('balmo_default_status',   'publish');
    add_option('balmo_log_retention',    30);
}
register_activation_hook(BALMO_PLUGIN_FILE, __NAMESPACE__ . '\activate');

function deactivate(): void
{
    ImportScheduler::clearAllSchedules();
}
register_deactivation_hook(BALMO_PLUGIN_FILE, __NAMESPACE__ . '\deactivate');

/**
 * Migracja schematu DB — dodaje brakujące kolumny do istniejących tabel.
 * Uruchamiana przy każdym plugins_loaded jeśli wersja DB jest stara.
 * Bezpieczniejsza niż dbDelta przy UPDATE kolumn.
 */
function maybe_migrate_db(): void
{
    $db_version = get_option('balmo_db_version', '0');
    if (version_compare($db_version, '1.1', '>=')) return;

    global $wpdb;
    $table = table_import_log();

    // Sprawdź czy kolumny istnieją
    $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`", 0);

    if (!in_array('file_path', $columns, true)) {
        $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `file_path` TEXT NULL AFTER `current_offset`");
    }
    if (!in_array('delimiter', $columns, true)) {
        $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `delimiter` VARCHAR(5) NOT NULL DEFAULT ',' AFTER `file_path`");
    }

    update_option('balmo_db_version', '1.1');
}

function get_background_batch_size(): int
{
    $configured = (int) get_option('balmo_batch_size', BALMO_BATCH_SIZE);
    return max(1, min($configured, BALMO_ASYNC_BATCH_SIZE));
}

function heartbeat_key(int $import_id): string
{
    return 'balmo_import_heartbeat_' . $import_id;
}

function attempt_key(int $import_id, int $offset): string
{
    return 'balmo_import_attempt_' . $import_id . '_' . $offset;
}

function mark_import_heartbeat(int $import_id, int $offset, array $context = []): void
{
    set_transient(heartbeat_key($import_id), array_merge([
        'offset' => $offset,
        'time'   => time(),
    ], $context), BALMO_HEARTBEAT_TTL * 2);
}

function get_import_heartbeat(int $import_id): ?array
{
    $value = get_transient(heartbeat_key($import_id));
    return is_array($value) ? $value : null;
}

function clear_import_heartbeat(int $import_id): void
{
    delete_transient(heartbeat_key($import_id));
}

function get_offset_attempts(int $import_id, int $offset): int
{
    return (int) get_transient(attempt_key($import_id, $offset));
}

function bump_offset_attempts(int $import_id, int $offset): int
{
    $attempts = get_offset_attempts($import_id, $offset) + 1;
    set_transient(attempt_key($import_id, $offset), $attempts, DAY_IN_SECONDS);
    return $attempts;
}

function clear_offset_attempts(int $import_id, int $offset): void
{
    delete_transient(attempt_key($import_id, $offset));
}

// --- Inicjalizacja ---

/**
 * Action Scheduler: przetwórz jedną transzę importu.
 * Każda akcja obsługuje BALMO_BATCH_SIZE wierszy, po czym kolejkuje następną.
 * Rejestrujemy poza is_admin() — AS odpala poza kontekstem admina.
 */
add_action('balmo_as_batch', function (int $import_id, int $offset): void {
    global $wpdb;

    $attempts  = bump_offset_attempts($import_id, $offset);
    $heartbeat = get_import_heartbeat($import_id);

    if (is_array($heartbeat) && isset($heartbeat['offset']) && (int) $heartbeat['offset'] > $offset) {
        $offset = (int) $heartbeat['offset'];
    }

    $log = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . table_import_log() . " WHERE id = %d", $import_id
    ));

    if (!$log || in_array($log->status, ['done', 'error', 'cancelled'], true)) return;

    if ($attempts >= 3 && (!is_array($heartbeat) || (int) ($heartbeat['offset'] ?? $offset) <= $offset)) {
        $row_number = (int) ($heartbeat['row_number'] ?? ($offset + 1));
        $identifier = trim((string) ($heartbeat['sku'] ?? $heartbeat['product_id'] ?? ''));

        $wpdb->insert(table_import_errors(), [
            'import_id'     => $import_id,
            'row_number'    => $row_number,
            'row_data'      => null,
            'error_message' => 'Skipped after repeated batch stalls at offset ' . $offset
                               . ($identifier !== '' ? ' [' . $identifier . ']' : ''),
        ], ['%d', '%d', '%s', '%s']);

        $next_offset = $offset + 1;
        $is_done     = $next_offset >= (int) $log->total_rows;

        $wpdb->query($wpdb->prepare(
            "UPDATE " . table_import_log() . "
             SET processed_rows = processed_rows + 1,
                 skipped_rows   = skipped_rows + 1,
                 error_rows     = error_rows + 1,
                 current_offset = %d,
                 status         = %s,
                 finished_at    = %s
             WHERE id = %d",
            $next_offset,
            $is_done ? 'done' : 'running',
            $is_done ? current_time('mysql') : null,
            $import_id
        ));

        clear_offset_attempts($import_id, $offset);
        mark_import_heartbeat($import_id, $next_offset, ['row_number' => $row_number + 1]);

        if ($is_done) {
            clear_import_heartbeat($import_id);
            return;
        }

        as_enqueue_async_action('balmo_as_batch', [$import_id, $next_offset], 'balmo');
        return;
    }

    $file_path = $log->file_path ?? '';
    $delimiter = $log->delimiter ?? ',';

    // Pierwsze wywołanie — ustaw status running
    if ($log->status === 'pending') {
        if (empty($file_path) || !file_exists($file_path)) {
            $wpdb->update(table_import_log(),
                ['status' => 'error', 'finished_at' => current_time('mysql')],
                ['id' => $import_id], ['%s', '%s'], ['%d']
            );
            clear_import_heartbeat($import_id);
            return;
        }
        $wpdb->update(table_import_log(),
            ['status' => 'running', 'started_at' => current_time('mysql')],
            ['id' => $import_id], ['%s', '%s'], ['%d']
        );
        $log->status = 'running';
    }

    // Pobierz profil mapowania
    $mapping = null;
    if ($log->mapping_id) {
        $mapping = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . table_mappings() . " WHERE id = %d", (int) $log->mapping_id
        ));
    }

    $batch_size  = get_background_batch_size();

    try {
        $importer = new \Balmo\Service\ProductImporter($import_id, $log, $mapping);
        $result   = $importer->processBatch($file_path, $delimiter, $offset, $batch_size);
    } catch (\Throwable $e) {
        $wpdb->insert(table_import_errors(), [
            'import_id'     => $import_id,
            'row_number'    => $offset,
            'row_data'      => null,
            'error_message' => 'BATCH CRASH at offset ' . $offset . ': ' . $e->getMessage()
                               . ' in ' . $e->getFile() . ':' . $e->getLine(),
        ], ['%d', '%d', '%s', '%s']);

        $wpdb->update(table_import_log(),
            ['status' => 'error', 'finished_at' => current_time('mysql')],
            ['id' => $import_id], ['%s', '%s'], ['%d']
        );
        clear_import_heartbeat($import_id);
        return;
    }
    $next_offset = $result['next_offset'];
    $is_done     = $next_offset >= (int) $log->total_rows;
    clear_offset_attempts($import_id, $offset);

    if ($is_done) {
        $wpdb->query($wpdb->prepare(
            "UPDATE " . table_import_log() . "
             SET processed_rows = processed_rows + %d,
                 created_rows   = created_rows   + %d,
                 updated_rows   = updated_rows   + %d,
                 skipped_rows   = skipped_rows   + %d,
                 error_rows     = error_rows     + %d,
                 current_offset = %d,
                 status         = 'done',
                 finished_at    = %s
             WHERE id = %d",
            $result['processed'], $result['created'], $result['updated'],
            $result['skipped'],   $result['errors'],  $next_offset,
            current_time('mysql'),
            $import_id
        ));
    } else {
        $wpdb->query($wpdb->prepare(
            "UPDATE " . table_import_log() . "
             SET processed_rows = processed_rows + %d,
                 created_rows   = created_rows   + %d,
                 updated_rows   = updated_rows   + %d,
                 skipped_rows   = skipped_rows   + %d,
                 error_rows     = error_rows     + %d,
                 current_offset = %d,
                 status         = 'running'
             WHERE id = %d",
            $result['processed'], $result['created'], $result['updated'],
            $result['skipped'],   $result['errors'],  $next_offset,
            $import_id
        ));
    }

    if ($is_done) {
        @unlink($file_path);
        clear_import_heartbeat($import_id);
        return;
    }

    // Kolejkuj następną transzę natychmiast
    mark_import_heartbeat($import_id, $next_offset);
    as_enqueue_async_action('balmo_as_batch', [$import_id, $next_offset], 'balmo');

}, 10, 2);

add_action('plugins_loaded', function (): void {
    maybe_migrate_db();

    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function (): void {
            echo '<div class="notice notice-error"><p>'
                . esc_html__('Balmo for WooCommerce wymaga aktywnej wtyczki WooCommerce.', 'balmo')
                . '</p></div>';
        });
        return;
    }

    ImportScheduler::init();

    if (is_admin()) {
        ImportPage::init();
        MappingPage::init();
        SchedulerPage::init();
        HistoryPage::init();
        Settings::init();
    }
});
