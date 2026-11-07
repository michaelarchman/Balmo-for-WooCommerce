<?php

namespace Balmo\Service;

if (!defined('ABSPATH')) exit;

/**
 * Turns wholesaler product IDs saved during the import (meta _balmo_related_ext)
 * into WooCommerce upsells. Runs after the import, because a target product can be in a later batch.
 */
class RelatedResolver
{
    public const ACTION     = 'balmo_resolve_related';
    private const CHUNK     = 200;
    private const META_EXT  = '_balmo_related_ext';
    private const META_IMPORT = '_balmo_related_import';

    // Queue the resolving after a finished full import whose profile maps "related"
    public static function schedule(int $import_id): void
    {
        global $wpdb;

        $log = $wpdb->get_row($wpdb->prepare(
            "SELECT mapping_id, import_mode FROM " . \Balmo\table_import_log() . " WHERE id = %d", $import_id
        ));
        if (!$log || $log->import_mode === 'update' || !$log->mapping_id) return;

        $column_map = $wpdb->get_var($wpdb->prepare(
            "SELECT column_map FROM " . \Balmo\table_mappings() . " WHERE id = %d", (int) $log->mapping_id
        ));
        $fields = array_values(json_decode((string) $column_map, true) ?: []);
        if (!in_array('_related_ext', $fields, true)) return;

        if (!in_array('_product_id_ext', $fields, true)) {
            $wpdb->insert(\Balmo\table_import_errors(), [
                'import_id'     => $import_id,
                'row_number'    => 0,
                'row_data'      => null,
                'error_message' => __('Produkty powiązane pominięte — profil nie mapuje product_id na ID zewnętrzne.', 'balmo'),
            ], ['%d', '%d', '%s', '%s']);
            return;
        }

        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + 2, self::ACTION, [$import_id, 0], 'balmo');
        }
    }

    // Resolve one chunk of products of the import; queue the next chunk when the chunk was full
    public static function run(int $import_id, int $after_id): void
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT e.post_id, e.meta_value
             FROM {$wpdb->postmeta} e
             INNER JOIN {$wpdb->postmeta} i
                     ON i.post_id = e.post_id AND i.meta_key = %s AND i.meta_value = %s
             WHERE e.meta_key = %s AND e.post_id > %d
             ORDER BY e.post_id ASC
             LIMIT %d",
            self::META_IMPORT, (string) $import_id, self::META_EXT, $after_id, self::CHUNK
        ));
        if (!$rows) return;

        $wanted = [];
        foreach ($rows as $row) {
            foreach (self::parseIds($row->meta_value) as $ext_id) {
                $wanted[$ext_id] = true;
            }
        }
        $targets = self::resolveTargets(array_keys($wanted));

        foreach ($rows as $row) {
            $upsells = [];
            foreach (self::parseIds($row->meta_value) as $ext_id) {
                $target = $targets[$ext_id] ?? 0;
                if ($target > 0 && $target !== (int) $row->post_id) {
                    $upsells[$target] = $target;
                }
            }
            update_post_meta((int) $row->post_id, '_upsell_ids', array_values($upsells));
        }

        if (count($rows) === self::CHUNK) {
            $last = (int) end($rows)->post_id;
            as_schedule_single_action(time() + 2, self::ACTION, [$import_id, $last], 'balmo');
        }
    }

    // "590, 644,abc" => [590, 644]
    private static function parseIds(string $list): array
    {
        $ids = [];
        foreach (explode(',', $list) as $part) {
            $id = (int) trim($part);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    // External ID => ID of a published product; a variation resolves to its parent
    private static function resolveTargets(array $ext_ids): array
    {
        global $wpdb;

        if (empty($ext_ids)) return [];

        $placeholders = implode(',', array_fill(0, count($ext_ids), '%s'));
        $found = $wpdb->get_results($wpdb->prepare(
            "SELECT m.meta_value AS ext_id, p.ID, p.post_type, p.post_parent
             FROM {$wpdb->postmeta} m
             INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
             WHERE m.meta_key = '_product_id_ext'
               AND m.meta_value IN ($placeholders)
               AND p.post_type IN ('product', 'product_variation')
             ORDER BY p.ID ASC",
            array_map('strval', $ext_ids)
        ));

        $targets = [];
        foreach ($found as $row) {
            $ext_id = (int) $row->ext_id;
            if (isset($targets[$ext_id])) continue;

            $product_id = $row->post_type === 'product_variation' ? (int) $row->post_parent : (int) $row->ID;
            if ($product_id > 0 && get_post_status($product_id) === 'publish') {
                $targets[$ext_id] = $product_id;
            }
        }

        return $targets;
    }
}
