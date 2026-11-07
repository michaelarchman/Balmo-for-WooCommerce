<?php

namespace Balmo\Service;

if (!defined('ABSPATH')) exit;

/**
 * Bulk removal of products by integration or by import, in the background (Action Scheduler).
 * Progress is kept in balmo_import_log with import_mode = 'delete'.
 */
class ProductRemover
{
    public const ACTION = 'balmo_delete_batch';
    public const MODE   = 'delete';
    public const BY_INTEGRATION = 'integration';
    public const BY_IMPORT      = 'import';

    private const CHUNK = 50;

    // Products matching the filter that are not in the trash yet
    public static function count(string $by, string $value): int
    {
        global $wpdb;

        [$join, $where, $args] = self::filterSql($by, $value);
        if ($join === '') return 0;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p {$join}
             WHERE p.post_type = 'product' AND p.post_status <> 'trash' AND {$where}",
            $args
        ));
    }

    // Log record + first Action Scheduler action. Returns the log ID, 0 when there is nothing to do.
    public static function schedule(string $by, string $value, bool $force): int
    {
        global $wpdb;

        $total = self::count($by, $value);
        if ($total === 0 || !function_exists('as_schedule_single_action')) return 0;

        $wpdb->insert(\Balmo\table_import_log(), [
            'source_url'  => self::describe($by, $value, $force),
            'import_mode' => self::MODE,
            'status'      => 'pending',
            'total_rows'  => $total,
        ], ['%s', '%s', '%s', '%d']);
        $log_id = (int) $wpdb->insert_id;
        if (!$log_id) return 0;

        as_schedule_single_action(time() + 2, self::ACTION, [$log_id, $by, $value, $force], 'balmo');

        return $log_id;
    }

    // One chunk of products; queues the next chunk while products remain
    public static function runBatch(int $log_id, string $by, string $value, bool $force): void
    {
        global $wpdb;

        $log = $wpdb->get_row($wpdb->prepare(
            "SELECT status FROM " . \Balmo\table_import_log() . " WHERE id = %d", $log_id
        ));
        if (!$log || in_array($log->status, ['done', 'error', 'cancelled'], true)) return;

        if ($log->status === 'pending') {
            $wpdb->update(\Balmo\table_import_log(),
                ['status' => 'running', 'started_at' => current_time('mysql')],
                ['id' => $log_id], ['%s', '%s'], ['%d']
            );
        }

        $ids = self::nextIds($by, $value, self::CHUNK);

        $deleted = 0;
        $failed  = 0;
        $started = time();

        wp_defer_term_counting(true);
        foreach ($ids as $product_id) {
            if (time() - $started >= BALMO_BATCH_TIME_LIMIT) break;

            try {
                self::deleteProduct($product_id, $force);
                $deleted++;
            } catch (\Throwable $e) {
                $failed++;
                $wpdb->insert(\Balmo\table_import_errors(), [
                    'import_id'     => $log_id,
                    'row_number'    => $product_id,
                    'row_data'      => null,
                    'error_message' => 'Product ' . $product_id . ': ' . $e->getMessage(),
                ], ['%d', '%d', '%s', '%s']);
            }
        }
        wp_defer_term_counting(false);

        $wpdb->query($wpdb->prepare(
            "UPDATE " . \Balmo\table_import_log() . "
             SET processed_rows = processed_rows + %d, error_rows = error_rows + %d
             WHERE id = %d",
            $deleted, $failed, $log_id
        ));

        $remaining = !empty(self::nextIds($by, $value, 1));

        // Failed products stay selectable, so a batch without any deletion would repeat forever
        if ($remaining && $deleted === 0 && !empty($ids)) {
            $wpdb->update(\Balmo\table_import_log(),
                ['status' => 'error', 'finished_at' => current_time('mysql')],
                ['id' => $log_id], ['%s', '%s'], ['%d']
            );
            return;
        }

        if (!$remaining) {
            $wpdb->update(\Balmo\table_import_log(),
                ['status' => 'done', 'finished_at' => current_time('mysql')],
                ['id' => $log_id], ['%s', '%s'], ['%d']
            );
            return;
        }

        wp_cache_flush();
        as_schedule_single_action(time() + 2, self::ACTION, [$log_id, $by, $value, $force], 'balmo');
    }

    // Trash (default) or permanently delete one product with its variations.
    // Permanent delete also removes Balmo external-image attachments that no other post uses.
    public static function deleteProduct(int $product_id, bool $force): void
    {
        $post = get_post($product_id);
        if (!$post || !in_array($post->post_type, ['product', 'product_variation'], true)) return;

        $variation_ids = array_map('intval', get_posts([
            'post_type'      => 'product_variation',
            'post_parent'    => $product_id,
            'post_status'    => 'any',
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ]));

        if (!$force) {
            foreach ($variation_ids as $variation_id) {
                wp_trash_post($variation_id);
            }
            if (!wp_trash_post($product_id)) {
                throw new \RuntimeException('wp_trash_post failed');
            }
            return;
        }

        $family      = array_merge([$product_id], $variation_ids);
        $attachments = self::ownedAttachments($family);

        foreach ($variation_ids as $variation_id) {
            wp_delete_post($variation_id, true);
        }
        if (!wp_delete_post($product_id, true)) {
            throw new \RuntimeException('wp_delete_post failed');
        }
        foreach ($attachments as $attachment_id) {
            wp_delete_attachment($attachment_id, true);
        }
    }

    // Product IDs matching the filter, not in the trash; always re-queried (no offset)
    private static function nextIds(string $by, string $value, int $limit): array
    {
        global $wpdb;

        [$join, $where, $args] = self::filterSql($by, $value);
        if ($join === '') return [];

        $args[] = $limit;

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT p.ID FROM {$wpdb->posts} p {$join}
             WHERE p.post_type = 'product' AND p.post_status <> 'trash' AND {$where}
             ORDER BY p.ID ASC LIMIT %d",
            $args
        )));
    }

    // [JOIN sql, WHERE sql, args]; JOIN is '' for an invalid filter
    private static function filterSql(string $by, string $value): array
    {
        global $wpdb;

        if (!ctype_digit($value) || (int) $value <= 0) return ['', '', []];

        if ($by === self::BY_INTEGRATION) {
            return [
                "INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id",
                "tt.taxonomy = %s AND tt.term_id = %d",
                [Integration::TAXONOMY, (int) $value],
            ];
        }

        if ($by === self::BY_IMPORT) {
            return [
                "INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID",
                "pm.meta_key = '_balmo_created_import' AND pm.meta_value = %s",
                [(string) (int) $value],
            ];
        }

        return ['', '', []];
    }

    // Label of the job, shown in the history
    private static function describe(string $by, string $value, bool $force): string
    {
        if ($by === self::BY_INTEGRATION) {
            $term  = get_term((int) $value, Integration::TAXONOMY);
            $label = $term instanceof \WP_Term ? $term->name : '#' . $value;
        } else {
            $label = sprintf(__('import #%s', 'balmo'), $value);
        }

        return $force ? sprintf(__('%s (trwale)', 'balmo'), $label) : $label;
    }

    // Balmo external attachments of the products (parent or featured/gallery image) that no other post uses
    private static function ownedAttachments(array $family): array
    {
        global $wpdb;

        $in_family = implode(',', array_map('intval', $family));

        $candidates = array_map('intval', $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_parent IN ({$in_family})"
        ));
        $refs = $wpdb->get_results(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta}
             WHERE post_id IN ({$in_family}) AND meta_key IN ('_thumbnail_id', '_product_image_gallery')"
        );
        foreach ($refs as $ref) {
            foreach (explode(',', (string) $ref->meta_value) as $id) {
                $candidates[] = (int) $id;
            }
        }

        $candidates = array_values(array_unique(array_filter($candidates)));
        if (empty($candidates)) return [];

        $in_candidates = implode(',', $candidates);
        $external = array_map('intval', $wpdb->get_col(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_balmo_external_url' AND post_id IN ({$in_candidates})"
        ));
        if (empty($external)) return [];

        $used = [];
        $in_external = implode(',', $external);
        foreach ($wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_thumbnail_id' AND meta_value IN ({$in_external})
               AND post_id NOT IN ({$in_family})"
        ) as $id) {
            $used[(int) $id] = true;
        }

        $likes = [];
        foreach ($external as $id) {
            $likes[] = "FIND_IN_SET({$id}, meta_value)";
        }
        foreach ($wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_product_image_gallery' AND post_id NOT IN ({$in_family})
               AND (" . implode(' OR ', $likes) . ")"
        ) as $list) {
            foreach (explode(',', (string) $list) as $id) {
                $used[(int) $id] = true;
            }
        }

        return array_values(array_filter($external, fn(int $id): bool => !isset($used[$id])));
    }
}
