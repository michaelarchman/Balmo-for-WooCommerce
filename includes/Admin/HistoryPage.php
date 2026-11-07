<?php

namespace Balmo\Admin;

if (!defined('ABSPATH')) exit;

class HistoryPage
{
    private const PER_PAGE = 20;

    public static function init(): void
    {
        add_action('wp_ajax_balmo_history_errors', [self::class, 'ajaxGetErrors']);
        add_action('wp_ajax_balmo_cancel_import',  [self::class, 'ajaxCancelImport']);
        add_action('wp_ajax_balmo_purge_logs',     [self::class, 'ajaxPurgeLogs']);
    }

    public static function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Brak uprawnień.', 'balmo'));
        }

        global $wpdb;

        $page    = max(1, (int) ($_GET['paged'] ?? 1));
        $offset  = ($page - 1) * self::PER_PAGE;
        $table   = \Balmo\table_import_log();

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $rows  = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            self::PER_PAGE, $offset
        )) ?: [];

        $pages = (int) ceil($total / self::PER_PAGE);

        // Auto-purge old logs
        self::purgeOldLogs();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Historia importów', 'balmo'); ?></h1>
            <p>
                <button type="button" id="balmo-btn-purge-logs" class="button">
                    <?php esc_html_e('Wyczyść stare logi', 'balmo'); ?>
                </button>
            </p>

            <?php if (empty($rows)): ?>
                <p><?php esc_html_e('Brak historii importów.', 'balmo'); ?></p>
            <?php else: ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th><?php esc_html_e('Data', 'balmo'); ?></th>
                            <th><?php esc_html_e('Typ', 'balmo'); ?></th>
                            <th><?php esc_html_e('Tryb', 'balmo'); ?></th>
                            <th><?php esc_html_e('Status', 'balmo'); ?></th>
                            <th><?php esc_html_e('Łącznie', 'balmo'); ?></th>
                            <th><?php esc_html_e('Utw.', 'balmo'); ?></th>
                            <th><?php esc_html_e('Akt.', 'balmo'); ?></th>
                            <th><?php esc_html_e('Pomin.', 'balmo'); ?></th>
                            <th><?php esc_html_e('Błędy', 'balmo'); ?></th>
                            <th><?php esc_html_e('Czas', 'balmo'); ?></th>
                            <th><?php esc_html_e('Akcje', 'balmo'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row):
                            $duration = '—';
                            if ($row->started_at && $row->finished_at) {
                                $diff     = strtotime($row->finished_at) - strtotime($row->started_at);
                                $duration = $diff . 's';
                            }
                            $status_label = self::statusLabel($row->status);
                        ?>
                            <tr>
                                <td><?php echo (int) $row->id; ?></td>
                                <td><?php echo esc_html($row->created_at); ?></td>
                                <td><?php echo esc_html(strtoupper($row->source_type)); ?></td>
                                <td><?php echo esc_html($row->import_mode); ?></td>
                                <td>
                                    <span class="balmo-status balmo-status-<?php echo esc_attr($row->status); ?>">
                                        <?php echo esc_html($status_label); ?>
                                    </span>
                                    <?php if ($row->status === 'running'): ?>
                                        <button type="button" class="button button-small balmo-btn-cancel"
                                                data-id="<?php echo esc_attr($row->id); ?>">
                                            <?php esc_html_e('Anuluj', 'balmo'); ?>
                                        </button>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo (int) $row->total_rows; ?></td>
                                <td><?php echo (int) $row->created_rows; ?></td>
                                <td><?php echo (int) $row->updated_rows; ?></td>
                                <td><?php echo (int) $row->skipped_rows; ?></td>
                                <td>
                                    <?php echo (int) $row->error_rows; ?>
                                    <?php if ((int) $row->error_rows > 0): ?>
                                        <button type="button" class="button button-small balmo-btn-show-errors"
                                                data-id="<?php echo esc_attr($row->id); ?>">
                                            <?php esc_html_e('Szczegóły', 'balmo'); ?>
                                        </button>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($duration); ?></td>
                                <td>
                                    <?php if ($row->source_url): ?>
                                        <small><?php echo esc_html(wp_trim_words($row->source_url, 5)); ?></small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Pagination -->
                <?php if ($pages > 1): ?>
                    <div class="tablenav bottom">
                        <div class="tablenav-pages">
                            <?php
                            echo paginate_links([
                                'base'      => add_query_arg('paged', '%#%'),
                                'format'    => '',
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                                'total'     => $pages,
                                'current'   => $page,
                            ]);
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Errors modal -->
            <div id="balmo-errors-modal" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);
                background:#fff;padding:20px;z-index:9999;max-width:700px;width:90%;max-height:80vh;overflow-y:auto;
                border:1px solid #ccc;box-shadow:0 5px 20px rgba(0,0,0,.3);">
                <h3><?php esc_html_e('Błędy importu', 'balmo'); ?> <span id="balmo-modal-import-id"></span></h3>
                <button type="button" id="balmo-modal-close" class="button" style="position:absolute;top:10px;right:10px;">✕</button>
                <div id="balmo-modal-errors-list"></div>
            </div>
            <div id="balmo-modal-overlay" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:9998;"></div>
        </div>
        <?php
    }

    public static function ajaxGetErrors(): void
    {
        check_ajax_referer('balmo_ajax', 'nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('Brak uprawnień.');

        $import_id = (int) ($_POST['import_id'] ?? 0);
        if (!$import_id) wp_send_json_error();

        global $wpdb;
        $errors = $wpdb->get_results($wpdb->prepare(
            "SELECT row_number, error_message FROM " . \Balmo\table_import_errors() . "
             WHERE import_id = %d ORDER BY row_number ASC LIMIT 500",
            $import_id
        ));

        wp_send_json_success(['errors' => $errors]);
    }

    public static function ajaxCancelImport(): void
    {
        check_ajax_referer('balmo_ajax', 'nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('Brak uprawnień.');

        $import_id = (int) ($_POST['import_id'] ?? 0);
        if (!$import_id) wp_send_json_error();

        global $wpdb;
        $wpdb->update(
            \Balmo\table_import_log(),
            ['status' => 'cancelled', 'finished_at' => current_time('mysql')],
            ['id' => $import_id, 'status' => 'running'],
            ['%s', '%s'],
            ['%d', '%s']
        );

        wp_send_json_success();
    }

    public static function ajaxPurgeLogs(): void
    {
        check_ajax_referer('balmo_ajax', 'nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('Brak uprawnień.');

        self::purgeOldLogs(true);
        wp_send_json_success(['message' => __('Stare logi usunięte.', 'balmo')]);
    }

    private static function purgeOldLogs(bool $force = false): void
    {
        // Run auto-purge at most once a day
        if (!$force && get_transient('balmo_last_purge')) return;
        set_transient('balmo_last_purge', 1, DAY_IN_SECONDS);

        $retention = (int) get_option('balmo_log_retention', 30);
        $cutoff    = date('Y-m-d H:i:s', strtotime("-{$retention} days"));

        global $wpdb;

        // Delete errors of old imports
        $wpdb->query($wpdb->prepare(
            "DELETE e FROM " . \Balmo\table_import_errors() . " e
             INNER JOIN " . \Balmo\table_import_log() . " l ON e.import_id = l.id
             WHERE l.created_at < %s AND l.status IN ('done','error','cancelled')",
            $cutoff
        ));

        // Delete old logs
        $wpdb->query($wpdb->prepare(
            "DELETE FROM " . \Balmo\table_import_log() . "
             WHERE created_at < %s AND status IN ('done','error','cancelled')",
            $cutoff
        ));
    }

    private static function statusLabel(string $status): string
    {
        $labels = [
            'pending'   => __('Oczekuje', 'balmo'),
            'running'   => __('W toku', 'balmo'),
            'done'      => __('Zakończony', 'balmo'),
            'error'     => __('Błąd', 'balmo'),
            'cancelled' => __('Anulowany', 'balmo'),
        ];
        return $labels[$status] ?? $status;
    }
}
