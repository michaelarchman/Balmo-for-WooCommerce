<?php

namespace Balmo\Service;

if (!defined('ABSPATH')) exit;

/**
 * Mark and sweep for full imports.
 * Mark: ProductImporter stamps every product of the file with _balmo_seen_full = import ID.
 * Sweep: after a healthy full import, products of the integration without that stamp are
 * trashed, hidden (draft) or deleted, depending on the profile option missing_action.
 */
class IntegrationSweeper
{
    public const ACTION      = 'balmo_sweep_batch';
    public const META_SEEN   = '_balmo_seen_full';
    public const META_HIDDEN = '_balmo_hidden';
    public const ACTIONS     = ['none', 'draft', 'trash', 'delete'];

    private const CHUNK           = 50;
    private const MIN_FEED_SHARE  = 0.5;  // the file must hold at least this share of the integration's products
    private const MAX_ERROR_SHARE = 0.1;  // more error rows than this share of the file blocks the sweep

    public static function actionFor(?object $mapping): string
    {
        $action = $mapping ? (string) ($mapping->missing_action ?? 'none') : 'none';
        return in_array($action, self::ACTIONS, true) ? $action : 'none';
    }

    // A range import covers only a part of the file, so it must never sweep
    public static function markPartial(int $import_id): void
    {
        set_transient(self::partialKey($import_id), 1, DAY_IN_SECONDS);
    }

    // Queue the sweep after a finished import when the profile asks for it and the import is healthy
    public static function schedule(int $import_id): void
    {
        global $wpdb;

        $log = $wpdb->get_row($wpdb->prepare(
            "SELECT mapping_id, import_mode, status, total_rows, processed_rows, error_rows, start_offset
             FROM " . \Balmo\table_import_log() . " WHERE id = %d", $import_id
        ));
        if (!$log || $log->import_mode !== 'full' || !$log->mapping_id) return;

        $mapping = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . \Balmo\table_mappings() . " WHERE id = %d", (int) $log->mapping_id
        ));
        if (self::actionFor($mapping) === 'none') return;

        $integration_id = Integration::forMapping($mapping);
        $reason = $integration_id === 0
            ? __('Sprzątanie pominięte — profil nie ma integracji.', 'balmo')
            : self::unsafeReason($log, $integration_id, $import_id);

        if ($reason !== null) {
            self::note($import_id, $reason);
            return;
        }

        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + 2, self::ACTION, [$import_id, 0], 'balmo');
        }
    }

    // One chunk of products and variations missing from the file; queues the next chunk while any remain
    public static function runBatch(int $import_id, int $after_id): void
    {
        global $wpdb;

        $log = $wpdb->get_row($wpdb->prepare(
            "SELECT mapping_id FROM " . \Balmo\table_import_log() . " WHERE id = %d", $import_id
        ));
        if (!$log || !$log->mapping_id) return;

        $mapping = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . \Balmo\table_mappings() . " WHERE id = %d", (int) $log->mapping_id
        ));
        $action         = self::actionFor($mapping);
        $integration_id = Integration::forMapping($mapping);
        if ($action === 'none' || $integration_id === 0) return;

        $ids     = self::nextIds($integration_id, $import_id, $after_id, self::CHUNK, $action === 'draft');
        $swept   = 0;
        $parents = [];
        $last    = $after_id;
        $more    = count($ids) === self::CHUNK;
        $started = time();

        wp_defer_term_counting(true);
        foreach ($ids as $post_id) {
            if (time() - $started >= BALMO_BATCH_TIME_LIMIT) {
                $more = true;
                break;
            }
            $last = $post_id;

            // Already gone with its parent earlier in this chunk
            $status = get_post_status($post_id);
            if ($status === false || $status === 'trash') continue;

            try {
                $parent_id = (int) wp_get_post_parent_id($post_id);
                if (get_post_type($post_id) === 'product_variation' && $parent_id > 0) {
                    $parents[$parent_id] = true;
                }
                self::apply($post_id, $action);
                $swept++;
            } catch (\Throwable $e) {
                $wpdb->insert(\Balmo\table_import_errors(), [
                    'import_id'     => $import_id,
                    'row_number'    => 0,
                    'row_data'      => null,
                    'error_message' => mb_substr('Sweep, product ' . $post_id . ': ' . $e->getMessage(), 0, 500),
                ], ['%d', '%d', '%s', '%s']);
            }
        }
        wp_defer_term_counting(false);

        foreach (array_keys($parents) as $parent_id) {
            if (get_post_status($parent_id) !== false && get_post_status($parent_id) !== 'trash') {
                \WC_Product_Variable::sync($parent_id);
            }
        }

        if ($swept > 0) {
            $wpdb->query($wpdb->prepare(
                "UPDATE " . \Balmo\table_import_log() . " SET swept_rows = swept_rows + %d WHERE id = %d",
                $swept, $import_id
            ));
        }

        if ($more) {
            wp_cache_flush();
            as_schedule_single_action(time() + 2, self::ACTION, [$import_id, $last], 'balmo');
        }
    }

    // Trash, delete or hide one product; ProductRemover handles variations and attachments
    private static function apply(int $post_id, string $action): void
    {
        if ($action === 'trash' || $action === 'delete') {
            ProductRemover::deleteProduct($post_id, $action === 'delete');
            return;
        }

        // A hidden variation is 'private', a hidden product is 'draft'
        $hidden_status = get_post_type($post_id) === 'product_variation' ? 'private' : 'draft';
        $result        = wp_update_post(['ID' => $post_id, 'post_status' => $hidden_status], true);
        if (is_wp_error($result)) {
            throw new \RuntimeException($result->get_error_message());
        }
        update_post_meta($post_id, self::META_HIDDEN, 'missing');
    }

    // Reason not to sweep, null when the import is safe to sweep
    private static function unsafeReason(object $log, int $integration_id, int $import_id): ?string
    {
        $total = (int) $log->total_rows;

        if ($log->status !== 'done') {
            return __('Sprzątanie pominięte — import nie zakończył się poprawnie.', 'balmo');
        }
        if ((int) $log->start_offset > 0 || get_transient(self::partialKey($import_id))) {
            return __('Sprzątanie pominięte — import obejmował tylko część pliku.', 'balmo');
        }
        if ($total < 1 || (int) $log->processed_rows < $total) {
            return __('Sprzątanie pominięte — nie przetworzono wszystkich wierszy.', 'balmo');
        }
        if ((int) $log->error_rows > $total * self::MAX_ERROR_SHARE) {
            return sprintf(
                __('Sprzątanie pominięte — błędów w %d%% wierszy lub więcej.', 'balmo'),
                (int) (self::MAX_ERROR_SHARE * 100)
            );
        }

        $existing = self::countIntegrationProducts($integration_id);
        if ($total < $existing * self::MIN_FEED_SHARE) {
            return sprintf(
                __('Sprzątanie pominięte — plik ma %1$d wierszy, a integracja %2$d produktów (poniżej %3$d%%).', 'balmo'),
                $total,
                $existing,
                (int) (self::MIN_FEED_SHARE * 100)
            );
        }

        return null;
    }

    // Products and variations of the integration that are not in the trash
    private static function countIntegrationProducts(int $integration_id): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE p.post_type IN ('product', 'product_variation') AND p.post_status <> 'trash'
               AND tt.taxonomy = %s AND tt.term_id = %d",
            Integration::TAXONOMY, $integration_id
        ));
    }

    // IDs of the integration's products and variations without the stamp of this import.
    // $skip_hidden leaves out products already hidden by an earlier sweep.
    private static function nextIds(int $integration_id, int $import_id, int $after_id, int $limit, bool $skip_hidden): array
    {
        global $wpdb;

        $hidden_join  = $skip_hidden
            ? "LEFT JOIN {$wpdb->postmeta} hidden ON hidden.post_id = p.ID AND hidden.meta_key = '" . self::META_HIDDEN . "'"
            : '';
        $hidden_where = $skip_hidden ? 'AND hidden.meta_id IS NULL' : '';

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             LEFT JOIN {$wpdb->postmeta} seen
                    ON seen.post_id = p.ID AND seen.meta_key = %s AND seen.meta_value = %s
             {$hidden_join}
             WHERE p.post_type IN ('product', 'product_variation') AND p.post_status <> 'trash'
               AND tt.taxonomy = %s AND tt.term_id = %d
               AND seen.meta_id IS NULL {$hidden_where} AND p.ID > %d
             ORDER BY p.ID ASC LIMIT %d",
            self::META_SEEN, (string) $import_id, Integration::TAXONOMY, $integration_id, $after_id, $limit
        )));
    }

    // Reason shown in the history in place of the swept count
    private static function note(int $import_id, string $reason): void
    {
        global $wpdb;

        $wpdb->update(\Balmo\table_import_log(),
            ['sweep_note' => mb_substr($reason, 0, 255)],
            ['id' => $import_id], ['%s'], ['%d']
        );
    }

    private static function partialKey(int $import_id): string
    {
        return 'balmo_partial_import_' . $import_id;
    }
}
