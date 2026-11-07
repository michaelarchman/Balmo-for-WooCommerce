<?php

namespace Balmo\Cron;

if (!defined('ABSPATH')) exit;

use Balmo\Service\CsvValidator;
use Balmo\Service\FeedLoader;

class ImportScheduler
{
    private const OPTION_KEY = 'balmo_schedules';

    public static function init(): void
    {
        // Rejestruj własne interwały WP-Cron
        add_filter('cron_schedules', [self::class, 'addCronIntervals']);

        // Rejestruj hooki dla zapisanych harmonogramów
        foreach (self::getAll() as $schedule) {
            $hook = 'balmo_cron_import_' . $schedule['id'];
            add_action($hook, function () use ($schedule): void {
                self::execute($schedule);
            });
        }
    }

    public static function addCronIntervals(array $schedules): array
    {
        $schedules['balmo_weekly'] = [
            'interval' => WEEK_IN_SECONDS,
            'display'  => __('Co tydzień (Balmo)', 'balmo'),
        ];
        return $schedules;
    }

    // Pobierz wszystkie harmonogramy z options
    public static function getAll(): array
    {
        $schedules = get_option(self::OPTION_KEY, []);
        if (!is_array($schedules)) return [];

        // Dołącz nazwę i format profilu mapowania (bez profilu: CSV)
        global $wpdb;
        foreach ($schedules as &$s) {
            $s['mapping_name'] = null;
            $s['format']       = 'csv';
            if (!empty($s['mapping_id'])) {
                $profile = $wpdb->get_row($wpdb->prepare(
                    "SELECT name, format FROM " . \Balmo\table_mappings() . " WHERE id = %d",
                    $s['mapping_id']
                ));
                if ($profile) {
                    $s['mapping_name'] = $profile->name;
                    $s['format']       = FeedLoader::normalizeFormat((string) $profile->format);
                }
            }
        }
        unset($s);
        return $schedules;
    }

    // Zapisz nowy harmonogram i zaplanuj WP-Cron
    public static function save(array $data): int
    {
        $schedules = get_option(self::OPTION_KEY, []);
        if (!is_array($schedules)) $schedules = [];

        // Generuj ID
        $max_id = 0;
        foreach ($schedules as $s) {
            $max_id = max($max_id, (int) ($s['id'] ?? 0));
        }
        $id = $max_id + 1;

        $schedule = [
            'id'          => $id,
            'name'        => sanitize_text_field($data['name']),
            'url'         => esc_url_raw($data['url']),
            'source_type' => in_array($data['source_type'], ['all', 'ica'], true) ? $data['source_type'] : 'all',
            'mapping_id'  => $data['mapping_id'] ? (int) $data['mapping_id'] : null,
            'interval'    => self::sanitizeInterval($data['interval']),
            'run_time'    => sanitize_text_field($data['run_time'] ?? '06:00'),
            'import_mode' => in_array($data['import_mode'], ['full', 'update'], true) ? $data['import_mode'] : 'update',
        ];

        $schedules[] = $schedule;
        update_option(self::OPTION_KEY, $schedules, false);

        // Zaplanuj WP-Cron
        self::scheduleWpCron($schedule);

        return $id;
    }

    // Usuń harmonogram i odplanuj WP-Cron
    public static function delete(int $id): void
    {
        $schedules = get_option(self::OPTION_KEY, []);
        if (!is_array($schedules)) return;

        $hook = 'balmo_cron_import_' . $id;
        wp_clear_scheduled_hook($hook);

        $schedules = array_filter($schedules, fn($s) => (int) ($s['id'] ?? 0) !== $id);
        update_option(self::OPTION_KEY, array_values($schedules), false);
    }

    // Uruchom natychmiast (tworzy import_log, zwraca import_id)
    public static function runNow(int $id): int|\WP_Error
    {
        $schedules = get_option(self::OPTION_KEY, []);
        $schedule  = null;
        foreach ($schedules as $s) {
            if ((int) ($s['id'] ?? 0) === $id) {
                $schedule = $s;
                break;
            }
        }

        if (!$schedule) {
            return new \WP_Error('not_found', __('Harmonogram nie istnieje.', 'balmo'));
        }

        return self::execute($schedule);
    }

    // Wyczyść wszystkie harmonogramy (przy deaktywacji)
    public static function clearAllSchedules(): void
    {
        foreach (self::getAll() as $schedule) {
            $hook = 'balmo_cron_import_' . $schedule['id'];
            wp_clear_scheduled_hook($hook);
        }
    }

    public static function getIntervalLabel(string $interval): string
    {
        $labels = [
            'hourly'     => __('Co godzinę', 'balmo'),
            'twicedaily' => __('Co 12 godzin', 'balmo'),
            'daily'      => __('Codziennie', 'balmo'),
            'weekly'     => __('Co tydzień', 'balmo'),
            'balmo_weekly' => __('Co tydzień', 'balmo'),
        ];
        return $labels[$interval] ?? $interval;
    }

    // --- Prywatne ---

    private static function scheduleWpCron(array $schedule): void
    {
        $hook = 'balmo_cron_import_' . $schedule['id'];
        wp_clear_scheduled_hook($hook);

        $interval = $schedule['interval'];
        $run_time = $schedule['run_time'] ?? '06:00';

        // Oblicz timestamp pierwszego uruchomienia
        [$h, $m] = array_map('intval', explode(':', $run_time));
        $tz      = wp_timezone();
        $now     = new \DateTimeImmutable('now', $tz);

        $first = $now->setTime($h, $m, 0);
        if ($first <= $now) {
            $first = $first->modify('+1 day');
        }

        // Dla interwałów krótszych niż dobowy — uruchom od razu
        if (in_array($interval, ['hourly', 'twicedaily'], true)) {
            $first = $now->modify('+1 minute');
        }

        $timestamp = $first->getTimestamp();

        $wp_interval = $interval === 'weekly' ? 'balmo_weekly' : $interval;
        wp_schedule_event($timestamp, $wp_interval, $hook);
    }

    private static function sanitizeInterval(string $interval): string
    {
        $valid = ['hourly', 'twicedaily', 'daily', 'weekly'];
        return in_array($interval, $valid, true) ? $interval : 'daily';
    }

    // Główna logika wykonania importu przez cron
    private static function execute(array $schedule): int|\WP_Error
    {
        global $wpdb;

        // Pobierz profil mapowania — określa format pliku
        $mapping = null;
        if (!empty($schedule['mapping_id'])) {
            $mapping = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM " . \Balmo\table_mappings() . " WHERE id = %d",
                (int) $schedule['mapping_id']
            ));
        }
        $format = $mapping ? FeedLoader::normalizeFormat((string) ($mapping->format ?? 'csv')) : 'csv';

        // Pobierz plik (JSON jest konwertowany do CSV)
        $fetcher_result = FeedLoader::fromUrl($schedule['url'], $schedule['source_type'], $format);
        if (is_wp_error($fetcher_result)) {
            return $fetcher_result;
        }

        // Szybka walidacja
        $validation = CsvValidator::validate(
            $fetcher_result['file_path'],
            $fetcher_result['delimiter'],
            $schedule['source_type']
        );
        if (!empty($validation['errors'])) {
            @unlink($fetcher_result['file_path']);
            return new \WP_Error('validation_failed', implode('; ', $validation['errors']));
        }

        // Utwórz rekord importu
        $wpdb->insert(
            \Balmo\table_import_log(),
            [
                'mapping_id'  => $schedule['mapping_id'] ?? null,
                'source_url'  => $schedule['url'],
                'source_type' => $schedule['source_type'],
                'import_mode' => $schedule['import_mode'],
                'match_by'    => $mapping ? $mapping->match_by : 'sku',
                'status'      => 'running',
                'total_rows'  => $fetcher_result['total_rows'],
                'started_at'  => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s']
        );
        $import_id = (int) $wpdb->insert_id;

        if (!$import_id) {
            @unlink($fetcher_result['file_path']);
            return new \WP_Error('db_error', __('Nie można utworzyć rekordu importu.', 'balmo'));
        }

        // Przetwarzaj w transzach synchronicznie (cron ma więcej czasu)
        $log      = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . \Balmo\table_import_log() . " WHERE id = %d", $import_id));
        $importer = new \Balmo\Service\ProductImporter($import_id, $log, $mapping);
        $batch    = \Balmo\get_background_batch_size();
        $offset   = 0;
        $total    = $fetcher_result['total_rows'];

        while ($offset < $total) {
            // Sprawdź czy nie anulowano
            $current_status = $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM " . \Balmo\table_import_log() . " WHERE id = %d", $import_id
            ));
            if ($current_status !== 'running') break;

            $result = $importer->processBatch($fetcher_result['file_path'], $fetcher_result['delimiter'], $offset, $batch);

            $next_offset = $result['next_offset'];

            $wpdb->query($wpdb->prepare(
                "UPDATE " . \Balmo\table_import_log() . "
                 SET processed_rows = processed_rows + %d,
                     created_rows   = created_rows   + %d,
                     updated_rows   = updated_rows   + %d,
                     skipped_rows   = skipped_rows   + %d,
                     error_rows     = error_rows     + %d,
                     current_offset = %d
                 WHERE id = %d",
                $result['processed'], $result['created'], $result['updated'],
                $result['skipped'],   $result['errors'],  $next_offset,
                $import_id
            ));

            $offset = $next_offset;
        }

        // Zakończ import
        $current_status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM " . \Balmo\table_import_log() . " WHERE id = %d", $import_id
        ));
        if ($current_status === 'running') {
            $wpdb->update(
                \Balmo\table_import_log(),
                ['status' => 'done', 'finished_at' => current_time('mysql')],
                ['id' => $import_id],
                ['%s', '%s'],
                ['%d']
            );
        }

        @unlink($fetcher_result['file_path']);

        return $import_id;
    }
}
