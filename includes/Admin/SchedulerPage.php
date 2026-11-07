<?php

namespace Balmo\Admin;

if (!defined('ABSPATH')) exit;

use Balmo\Cron\ImportScheduler;

class SchedulerPage
{
    public static function init(): void
    {
        add_action('wp_ajax_balmo_save_schedule',    [self::class, 'ajaxSaveSchedule']);
        add_action('wp_ajax_balmo_delete_schedule',  [self::class, 'ajaxDeleteSchedule']);
        add_action('wp_ajax_balmo_run_schedule_now', [self::class, 'ajaxRunNow']);
    }

    public static function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Brak uprawnień.', 'balmo'));
        }

        $schedules = ImportScheduler::getAll();
        $mappings  = self::getMappingOptions();

        // Następne uruchomienia WP-Cron
        $next_runs = [];
        foreach ($schedules as $s) {
            $hook      = 'balmo_cron_import_' . $s['id'];
            $timestamp = wp_next_scheduled($hook);
            $next_runs[$s['id']] = $timestamp ? get_date_from_gmt(gmdate('Y-m-d H:i:s', $timestamp), 'Y-m-d H:i:s') : '—';
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Harmonogram importu', 'balmo'); ?></h1>
            <p class="description">
                <?php esc_html_e('Cykliczny import uruchamiany przez WP-Cron. Każdy harmonogram to osobna reguła z własnym URL, profilem mapowania i interwałem.', 'balmo'); ?>
            </p>

            <!-- Lista harmonogramów -->
            <?php if (empty($schedules)): ?>
                <p><?php esc_html_e('Brak harmonogramów. Dodaj poniżej.', 'balmo'); ?></p>
            <?php else: ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Nazwa', 'balmo'); ?></th>
                            <th><?php esc_html_e('URL', 'balmo'); ?></th>
                            <th><?php esc_html_e('Typ', 'balmo'); ?></th>
                            <th><?php esc_html_e('Format', 'balmo'); ?></th>
                            <th><?php esc_html_e('Profil', 'balmo'); ?></th>
                            <th><?php esc_html_e('Interwał', 'balmo'); ?></th>
                            <th><?php esc_html_e('Godzina', 'balmo'); ?></th>
                            <th><?php esc_html_e('Następne uruch.', 'balmo'); ?></th>
                            <th><?php esc_html_e('Akcje', 'balmo'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($schedules as $s): ?>
                            <tr>
                                <td><strong><?php echo esc_html($s['name']); ?></strong></td>
                                <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;">
                                    <small><?php echo esc_html($s['url']); ?></small>
                                </td>
                                <td><?php echo esc_html(strtoupper($s['source_type'])); ?></td>
                                <td><?php echo esc_html(strtoupper($s['format'] ?? 'csv')); ?></td>
                                <td><?php echo esc_html($s['mapping_name'] ?? '—'); ?></td>
                                <td><?php echo esc_html(ImportScheduler::getIntervalLabel($s['interval'])); ?></td>
                                <td><?php echo esc_html($s['run_time']); ?></td>
                                <td><?php echo esc_html($next_runs[$s['id']] ?? '—'); ?></td>
                                <td>
                                    <button type="button" class="button balmo-btn-run-now"
                                            data-id="<?php echo esc_attr($s['id']); ?>">
                                        <?php esc_html_e('Uruchom teraz', 'balmo'); ?>
                                    </button>
                                    <button type="button" class="button balmo-btn-delete-schedule"
                                            data-id="<?php echo esc_attr($s['id']); ?>"
                                            data-name="<?php echo esc_attr($s['name']); ?>">
                                        <?php esc_html_e('Usuń', 'balmo'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <hr>

            <!-- Formularz nowego harmonogramu -->
            <h2><?php esc_html_e('Dodaj harmonogram', 'balmo'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="balmo-sched-name"><?php esc_html_e('Nazwa', 'balmo'); ?></label></th>
                    <td><input type="text" id="balmo-sched-name" class="regular-text" placeholder="np. Colibra codzienny"></td>
                </tr>
                <tr>
                    <th><label for="balmo-sched-url"><?php esc_html_e('URL pliku', 'balmo'); ?></label></th>
                    <td>
                        <input type="url" id="balmo-sched-url" class="large-text"
                               placeholder="https://drop.seltu.pl/products/colibra/csv/">
                    </td>
                </tr>
                <tr>
                    <th><label for="balmo-sched-source-type"><?php esc_html_e('Typ pliku', 'balmo'); ?></label></th>
                    <td>
                        <select id="balmo-sched-source-type">
                            <option value="all"><?php esc_html_e('ALL — pełne dane', 'balmo'); ?></option>
                            <option value="ica"><?php esc_html_e('ICA — cena/stan/aktywność', 'balmo'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="balmo-sched-mapping"><?php esc_html_e('Profil mapowania', 'balmo'); ?></label></th>
                    <td>
                        <select id="balmo-sched-mapping">
                            <option value=""><?php esc_html_e('— brak —', 'balmo'); ?></option>
                            <?php foreach ($mappings as $m): ?>
                                <option value="<?php echo esc_attr($m->id); ?>">
                                    <?php echo esc_html($m->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="balmo-sched-interval"><?php esc_html_e('Częstotliwość', 'balmo'); ?></label></th>
                    <td>
                        <select id="balmo-sched-interval">
                            <option value="hourly"><?php esc_html_e('Co godzinę', 'balmo'); ?></option>
                            <option value="twicedaily"><?php esc_html_e('Co 12 godzin', 'balmo'); ?></option>
                            <option value="daily" selected><?php esc_html_e('Codziennie', 'balmo'); ?></option>
                            <option value="weekly"><?php esc_html_e('Co tydzień', 'balmo'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="balmo-sched-time"><?php esc_html_e('Godzina uruchomienia', 'balmo'); ?></label></th>
                    <td>
                        <input type="time" id="balmo-sched-time" value="06:00">
                        <p class="description">
                            <?php esc_html_e('Dla interwałów krótszych niż doba godzina jest ignorowana — import uruchamia się od razu po aktywacji, potem co interwał.', 'balmo'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="balmo-sched-import-mode"><?php esc_html_e('Tryb importu', 'balmo'); ?></label></th>
                    <td>
                        <select id="balmo-sched-import-mode">
                            <option value="update"><?php esc_html_e('Tylko aktualizuj istniejące produkty', 'balmo'); ?></option>
                            <option value="full"><?php esc_html_e('Pełny (utwórz nowe + aktualizuj)', 'balmo'); ?></option>
                        </select>
                    </td>
                </tr>
            </table>
            <button type="button" id="balmo-btn-save-schedule" class="button button-primary">
                <?php esc_html_e('Zapisz harmonogram', 'balmo'); ?>
            </button>
            <span id="balmo-schedule-save-result" style="margin-left:10px;"></span>
        </div>
        <?php
    }

    public static function ajaxSaveSchedule(): void
    {
        check_ajax_referer('balmo_ajax', 'nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('Brak uprawnień.');

        $name        = sanitize_text_field($_POST['name']        ?? '');
        $url         = esc_url_raw(trim($_POST['url']            ?? ''));
        $source_type = sanitize_text_field($_POST['source_type'] ?? 'all');
        $mapping_id  = (int) ($_POST['mapping_id']               ?? 0);
        $interval    = sanitize_text_field($_POST['interval']    ?? 'daily');
        $run_time    = sanitize_text_field($_POST['run_time']     ?? '06:00');
        $import_mode = sanitize_text_field($_POST['import_mode'] ?? 'update');

        if (empty($name) || empty($url)) {
            wp_send_json_error(__('Podaj nazwę i URL.', 'balmo'));
            return;
        }

        $id = ImportScheduler::save([
            'name'        => $name,
            'url'         => $url,
            'source_type' => $source_type,
            'mapping_id'  => $mapping_id ?: null,
            'interval'    => $interval,
            'run_time'    => $run_time,
            'import_mode' => $import_mode,
        ]);

        wp_send_json_success(['id' => $id, 'message' => __('Harmonogram zapisany.', 'balmo')]);
    }

    public static function ajaxDeleteSchedule(): void
    {
        check_ajax_referer('balmo_ajax', 'nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('Brak uprawnień.');

        $id = (int) ($_POST['id'] ?? 0);
        if (!$id) wp_send_json_error(__('Brak ID.', 'balmo'));

        ImportScheduler::delete($id);
        wp_send_json_success();
    }

    public static function ajaxRunNow(): void
    {
        check_ajax_referer('balmo_ajax', 'nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('Brak uprawnień.');

        $id = (int) ($_POST['id'] ?? 0);
        if (!$id) wp_send_json_error(__('Brak ID.', 'balmo'));

        $result = ImportScheduler::runNow($id);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
            return;
        }

        wp_send_json_success(['import_id' => $result]);
    }

    private static function getMappingOptions(): array
    {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT id, name, source_type FROM " . \Balmo\table_mappings() . " ORDER BY name ASC"
        ) ?: [];
    }
}
