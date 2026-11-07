<?php
/**
 * Plugin Name: Balmo for WooCommerce
 * Description: Import products from CSV, JSON and XML feeds with column mapping, price formulas and scheduling. Handles up to 20,000 products in batches.
 * Version:     1.1.0
 * Author:      Michał Łuczak
 * Requires at least: 5.0
 * Requires PHP: 8.3
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

define('BALMO_MIN_PHP', '8.3');

// PHP version guard. Keep everything down to the early return free of syntax newer than PHP 5.6
// (no scalar types, no ??, no short closures), so it can run on an old interpreter.
function php_requirement_met()
{
    return version_compare(PHP_VERSION, BALMO_MIN_PHP, '>=');
}

function block_activation_on_old_php()
{
    if (php_requirement_met()) {
        return;
    }

    deactivate_plugins(plugin_basename(__FILE__));
    wp_die(
        sprintf(
            esc_html__('Balmo for WooCommerce wymaga PHP %1$s lub nowszego. Obecna wersja: %2$s.', 'balmo'),
            BALMO_MIN_PHP,
            PHP_VERSION
        ),
        '',
        ['back_link' => true]
    );
}

// Registered before activate(), so wp_die() stops activation before any table is created.
register_activation_hook(__FILE__, __NAMESPACE__ . '\block_activation_on_old_php');

if (!php_requirement_met()) {
    // Plugin already active, then PHP downgraded: no autoloader, no classes, only a notice.
    add_action('admin_notices', function () {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="notice notice-error"><p>'
            . sprintf(
                esc_html__('Balmo for WooCommerce wymaga PHP %1$s lub nowszego. Obecna wersja: %2$s. Wtyczka jest wyłączona.', 'balmo'),
                BALMO_MIN_PHP,
                PHP_VERSION
            )
            . '</p></div>';
    });
    return;
}

define('BALMO_VERSION',   '1.1.0');
define('BALMO_PLUGIN_FILE', __FILE__);
define('BALMO_PLUGIN_DIR',  plugin_dir_path(__FILE__));
define('BALMO_PLUGIN_URL',  plugin_dir_url(__FILE__));

// Rows per import batch (CSV rows)
define('BALMO_BATCH_SIZE', 200);
define('BALMO_ASYNC_BATCH_SIZE', 10);
define('BALMO_BATCH_TIME_LIMIT', 20);
define('BALMO_HEARTBEAT_TTL', 180);
define('BALMO_MAX_GALLERY_IMAGES', 12); // additional images per product; fields _gallery_image_2 … _gallery_image_{N+1}

spl_autoload_register(function (string $class): void {
    if (strpos($class, 'Balmo\\') !== 0) return;
    $file = BALMO_PLUGIN_DIR . 'includes/' . str_replace(['Balmo\\', '\\'], ['', DIRECTORY_SEPARATOR], $class) . '.php';
    if (file_exists($file)) require_once $file;
});

// --- Database tables ---

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

    // Import history, one record per run
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
            start_offset   INT UNSIGNED     NOT NULL DEFAULT 0,
            end_offset     INT UNSIGNED     NOT NULL DEFAULT 0,
            current_offset INT UNSIGNED     NOT NULL DEFAULT 0,
            file_path      TEXT             NULL,
            variant_bases  LONGTEXT         NULL,
            delimiter      VARCHAR(5)       NOT NULL DEFAULT ',',
            started_at     DATETIME         NULL,
            finished_at    DATETIME         NULL,
            created_at     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status (status),
            KEY idx_created_at (created_at)
        ) {$charset};
    ");

    // Per-row CSV errors, detailed log
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

    // Column mapping profiles
    dbDelta("
        CREATE TABLE " . table_mappings() . " (
            id            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
            name          VARCHAR(255)     NOT NULL,
            format        VARCHAR(5)       NOT NULL DEFAULT 'csv',
            source_type   VARCHAR(10)      NOT NULL DEFAULT 'all',
            image_url_prefix VARCHAR(255)  NOT NULL DEFAULT '',
            column_map    LONGTEXT         NOT NULL,
            overrides     LONGTEXT         NULL,
            price_formula VARCHAR(500)     NULL,
            match_by      VARCHAR(10)      NOT NULL DEFAULT 'sku',
            update_fields LONGTEXT         NULL,
            group_variants TINYINT(1)      NOT NULL DEFAULT 0,
            variant_suffixes TEXT          NULL,
            created_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) {$charset};
    ");

    // Folder for temporary CSV files
    $upload_dir = wp_upload_dir();
    $balmo_dir  = $upload_dir['basedir'] . '/balmo';
    if (!file_exists($balmo_dir)) {
        wp_mkdir_p($balmo_dir);
        file_put_contents($balmo_dir . '/.htaccess', 'deny from all');
    }

    // Default settings
    add_option('balmo_batch_size',       200);
    add_option('balmo_image_mode',       'none');
    add_option('balmo_default_status',   'publish');
    add_option('balmo_log_retention',    30);
    update_option('balmo_db_version',    '1.4');
}
register_activation_hook(BALMO_PLUGIN_FILE, __NAMESPACE__ . '\activate');

function deactivate(): void
{
    ImportScheduler::clearAllSchedules();
}
register_deactivation_hook(BALMO_PLUGIN_FILE, __NAMESPACE__ . '\deactivate');

/**
 * DB schema migration: adds missing columns to existing tables.
 * Runs on every plugins_loaded when the DB version is outdated.
 * Safer than dbDelta for column updates.
 */
function maybe_migrate_db(): void
{
    global $wpdb;

    if (!($wpdb instanceof \wpdb)) {
        return;
    }

    $import_log_cols = $wpdb->get_col("SHOW COLUMNS FROM `" . table_import_log() . "`", 0);
    if (!is_array($import_log_cols)) {
        return;
    }

    if (!in_array('file_path', $import_log_cols, true)) {
        $wpdb->query("ALTER TABLE `" . table_import_log() . "` ADD COLUMN `file_path` TEXT NULL AFTER `current_offset`");
    }
    if (!in_array('delimiter', $import_log_cols, true)) {
        $wpdb->query("ALTER TABLE `" . table_import_log() . "` ADD COLUMN `delimiter` VARCHAR(5) NOT NULL DEFAULT ',' AFTER `file_path`");
    }
    if (!in_array('variant_bases', $import_log_cols, true)) {
        $wpdb->query("ALTER TABLE `" . table_import_log() . "` ADD COLUMN `variant_bases` LONGTEXT NULL");
    }
    if (!in_array('start_offset', $import_log_cols, true)) {
        $wpdb->query("ALTER TABLE `" . table_import_log() . "` ADD COLUMN `start_offset` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `error_rows`");
    }
    if (!in_array('end_offset', $import_log_cols, true)) {
        $wpdb->query("ALTER TABLE `" . table_import_log() . "` ADD COLUMN `end_offset` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `start_offset`");
    }

    $mappings_cols = $wpdb->get_col("SHOW COLUMNS FROM `" . table_mappings() . "`", 0);
    if (!is_array($mappings_cols)) {
        return;
    }

    if (!in_array('format', $mappings_cols, true)) {
        $wpdb->query("ALTER TABLE `" . table_mappings() . "` ADD COLUMN `format` VARCHAR(5) NOT NULL DEFAULT 'csv' AFTER `source_type`");
        $mappings_cols[] = 'format';
    }
    if (!in_array('image_url_prefix', $mappings_cols, true)) {
        $position = in_array('format', $mappings_cols, true) ? ' AFTER `format`' : '';
        $wpdb->query("ALTER TABLE `" . table_mappings() . "` ADD COLUMN `image_url_prefix` VARCHAR(255) NOT NULL DEFAULT ''{$position}");
    }

    if (!in_array('group_variants', $mappings_cols, true)) {
        $wpdb->query("ALTER TABLE `" . table_mappings() . "` ADD COLUMN `group_variants` TINYINT(1) NOT NULL DEFAULT 0");
    }
    if (!in_array('variant_suffixes', $mappings_cols, true)) {
        $wpdb->query("ALTER TABLE `" . table_mappings() . "` ADD COLUMN `variant_suffixes` TEXT NULL");
    }

    update_option('balmo_db_version', '1.4');
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

// --- Initialization ---

/**
 * Action Scheduler: process one import batch.
 * Each action handles BALMO_BATCH_SIZE rows, then queues the next one.
 * Registered outside is_admin(): AS runs outside the admin context.
 */
add_action(\Balmo\Service\RelatedResolver::ACTION, [\Balmo\Service\RelatedResolver::class, 'run'], 10, 2);

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

    $end_offset = isset($log->end_offset) && (int) $log->end_offset > 0
        ? (int) $log->end_offset
        : (int) $log->total_rows;

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
        $is_done     = $next_offset >= $end_offset;

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
            \Balmo\Service\RelatedResolver::schedule($import_id);
            return;
        }

        as_schedule_single_action(time() + 2, 'balmo_as_batch', [$import_id, $next_offset], 'balmo');
        return;
    }

    $file_path = $log->file_path ?? '';
    $delimiter = $log->delimiter ?? ',';

    // First call: set status to running
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

    $mapping = null;
    if ($log->mapping_id) {
        $mapping = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . table_mappings() . " WHERE id = %d", (int) $log->mapping_id
        ));
    }

    $batch_size  = get_background_batch_size();

    // Memory management before the batch
    @ini_set('memory_limit', '512M');
    wp_defer_term_counting(true);
    wp_defer_comment_counting(true);
    wp_suspend_cache_addition(true);

    // Disable Jetpack sync: it syncs every product and eats RAM
    if (class_exists('Automattic\Jetpack\Sync\Actions')) {
        \Automattic\Jetpack\Sync\Actions::mark_sync_read_only();
    }
    add_filter('jetpack_sync_sender_should_load', '__return_false');
    add_filter('jetpack_sync_prevent_sending_action', '__return_true');

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
    $is_done     = $next_offset >= $end_offset;
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
        wp_defer_term_counting(false);
        wp_defer_comment_counting(false);
        wp_suspend_cache_addition(false);
        @unlink($file_path);
        clear_import_heartbeat($import_id);
        \Balmo\Service\RelatedResolver::schedule($import_id);
        return;
    }

    // Free memory before the next batch
    wp_defer_term_counting(false);
    wp_defer_comment_counting(false);
    wp_suspend_cache_addition(false);
    wp_cache_flush();
    gc_collect_cycles();

    // Queue next batch with a delay, forces a new PHP process
    mark_import_heartbeat($import_id, $next_offset);
    as_schedule_single_action(time() + 2, 'balmo_as_batch', [$import_id, $next_offset], 'balmo');

}, 10, 2);

// --- Filters for external Balmo attachments ---
// Must always run (frontend + admin), hence outside is_admin().

add_filter('wp_get_attachment_url', function ($url, $attachment_id) {
    $attachment_id = (int) $attachment_id;
    if (!$attachment_id) return $url;
    $external = get_post_meta($attachment_id, '_balmo_external_url', true);
    return $external ?: $url;
}, 10, 2);

add_filter('wp_get_attachment_image_src', function ($image, $attachment_id, $size, $icon) {
    if ($image) return $image;
    $attachment_id = (int) $attachment_id;
    if (!$attachment_id) return $image;
    $external = get_post_meta($attachment_id, '_balmo_external_url', true);
    if ($external) return [$external, 800, 800, false];
    return $image;
}, 10, 4);

add_filter('wp_get_attachment_metadata', function ($data, $attachment_id) {
    if (!empty($data)) return $data;
    $attachment_id = (int) $attachment_id;
    if (!$attachment_id) return $data;
    $external = get_post_meta($attachment_id, '_balmo_external_url', true);
    if (!$external) return $data;
    return [
        'width'  => 800,
        'height' => 800,
        'file'   => wp_basename(wp_parse_url($external, PHP_URL_PATH) ?: 'image.jpg'),
        'sizes'  => [],
    ];
}, 10, 2);

add_filter('wp_calculate_image_srcset', function ($sources, $size_array, $image_src, $image_meta, $attachment_id) {
    $attachment_id = (int) $attachment_id;
    if ($attachment_id && get_post_meta($attachment_id, '_balmo_external_url', true)) return false;
    return $sources;
}, 10, 5);

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
