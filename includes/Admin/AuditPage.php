<?php

namespace Balmo\Admin;

use Balmo\Service\Integration;

if (!defined('ABSPATH')) exit;

/**
 * Read-only audit of imported products per integration.
 * Counts are computed on demand (one aggregate query) and cached in a transient.
 */
class AuditPage
{
    public const PAGE_SLUG = 'balmo-audit';

    private const TRANSIENT   = 'balmo_audit';
    private const PARAM       = 'balmo_audit';
    private const NONCE       = 'balmo_ajax';
    private const CSV_CHUNK   = 1000;
    private const CSV_DELIM   = ';';

    // Audit columns; each one is a condition on a product
    private const COLUMNS = ['all', 'publish', 'draft', 'no_image', 'not_in_full', 'no_price', 'out_of_stock', 'no_category'];

    public static function init(): void
    {
        add_action('wp_ajax_balmo_audit_export', [self::class, 'ajaxExport']);
        add_action('pre_get_posts',              [self::class, 'applyListFilter']);
    }

    public static function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Brak uprawnień.', 'balmo'));
        }

        if (isset($_POST['balmo_audit_recalc'])) {
            check_admin_referer('balmo_audit_recalc');
            set_transient(self::TRANSIENT, ['time' => time(), 'rows' => self::compute()], HOUR_IN_SECONDS);
        }

        $cache = get_transient(self::TRANSIENT);
        $cache = is_array($cache) && isset($cache['rows']) ? $cache : null;
        $labels = self::columnLabels();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Audyt produktów', 'balmo'); ?></h1>

            <form method="post">
                <?php wp_nonce_field('balmo_audit_recalc'); ?>
                <p>
                    <button type="submit" name="balmo_audit_recalc" value="1" class="button button-primary">
                        <?php esc_html_e('Przelicz', 'balmo'); ?>
                    </button>
                    <?php if ($cache): ?>
                        <?php echo esc_html(sprintf(
                            __('Obliczono: %s', 'balmo'),
                            wp_date('Y-m-d H:i', (int) $cache['time'])
                        )); ?>
                    <?php endif; ?>
                    <a href="<?php echo esc_url(add_query_arg('page', ToolsPage::PAGE_SLUG, admin_url('admin.php'))); ?>">
                        <?php esc_html_e('Narzędzia (usuwanie produktów)', 'balmo'); ?>
                    </a>
                </p>
            </form>

            <?php if (!$cache): ?>
                <p><?php esc_html_e('Brak wyniku. Kliknij „Przelicz”.', 'balmo'); ?></p>
            <?php else: ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Integracja', 'balmo'); ?></th>
                            <?php foreach ($labels as $label): ?>
                                <th><?php echo esc_html($label); ?></th>
                            <?php endforeach; ?>
                            <th><?php esc_html_e('Ostatnie sprzątanie', 'balmo'); ?></th>
                            <th><?php esc_html_e('Ostatni import', 'balmo'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cache['rows'] as $term_id => $row): ?>
                            <tr>
                                <td><?php echo esc_html($row['name']); ?></td>
                                <?php foreach (array_keys($labels) as $column): ?>
                                    <td><?php self::renderCount((int) $term_id, $column, $row['counts'][$column] ?? null); ?></td>
                                <?php endforeach; ?>
                                <td><?php echo esc_html($row['sweep']); ?></td>
                                <td><?php echo esc_html($row['last_import']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    // --- Counting ---

    // [term_id (0 = no integration) => row]; one aggregate query for the counts
    private static function compute(): array
    {
        global $wpdb;

        $full_imports = self::lastFullImports();

        $select = [];
        foreach (self::COLUMNS as $column) {
            if ($column === 'not_in_full') {
                $whens = '';
                foreach ($full_imports as $term_id => $log) {
                    $whens .= ' WHEN ' . (int) $term_id . ' THEN ' . self::condition($column, 'p', (int) $log->id);
                }
                $select[] = $whens === ''
                    ? "NULL AS c_{$column}"
                    : "SUM(CASE COALESCE(i.term_id, 0){$whens} ELSE NULL END) AS c_{$column}";
                continue;
            }
            $select[] = 'SUM(' . self::condition($column, 'p') . ") AS c_{$column}";
        }

        $results = $wpdb->get_results(
            'SELECT COALESCE(i.term_id, 0) AS term_id, ' . implode(', ', $select)
            . " FROM {$wpdb->posts} p " . self::integrationJoin('p')
            . ' WHERE ' . self::scope('p')
            . ' GROUP BY COALESCE(i.term_id, 0)'
        ) ?: [];
        $counts = [];
        foreach ($results as $result) {
            $counts[(int) $result->term_id] = $result;
        }

        $last_imports = self::lastImports();

        $names = [0 => __('Bez integracji', 'balmo')] + Integration::terms();
        $rows  = [];
        foreach ($names as $term_id => $name) {
            $result = $counts[$term_id] ?? null;

            $row_counts = [];
            foreach (self::COLUMNS as $column) {
                $value = $result ? ($result->{'c_' . $column} ?? null) : 0;
                $row_counts[$column] = $value === null ? null : (int) $value;
            }

            $full = $full_imports[$term_id] ?? null;
            $sweep = '—';
            if ($full) {
                $sweep = $full->sweep_note
                    ?: sprintf(__('%1$d (%2$s)', 'balmo'), (int) $full->swept_rows, substr((string) $full->created_at, 0, 16));
            }

            $last = $last_imports[$term_id] ?? null;

            $rows[$term_id] = [
                'name'        => $name,
                'counts'      => $row_counts,
                'sweep'       => $sweep,
                'last_import' => $last
                    ? sprintf('%s, %s', substr((string) $last->created_at, 0, 16), HistoryPage::statusLabel((string) $last->status))
                    : '—',
            ];
        }

        return $rows;
    }

    // term_id => import log row of the latest finished full import (whole file) of the integration
    private static function lastFullImports(): array
    {
        global $wpdb;

        $log      = \Balmo\table_import_log();
        $mappings = \Balmo\table_mappings();

        $ids = $wpdb->get_results(
            "SELECT m.integration_id, MAX(l.id) AS log_id
             FROM {$log} l INNER JOIN {$mappings} m ON m.id = l.mapping_id
             WHERE l.import_mode = 'full' AND l.status = 'done' AND l.start_offset = 0 AND m.integration_id > 0
             GROUP BY m.integration_id"
        ) ?: [];
        if (empty($ids)) return [];

        $in   = implode(',', array_map(fn($r): int => (int) $r->log_id, $ids));
        $logs = [];
        foreach ($wpdb->get_results(
            "SELECT id, created_at, swept_rows, sweep_note FROM {$log} WHERE id IN ({$in})"
        ) ?: [] as $row) {
            $logs[(int) $row->id] = $row;
        }

        $result = [];
        foreach ($ids as $row) {
            if (isset($logs[(int) $row->log_id])) {
                $result[(int) $row->integration_id] = $logs[(int) $row->log_id];
            }
        }
        return $result;
    }

    // term_id => import log row of the import that touched the integration's products last
    private static function lastImports(): array
    {
        global $wpdb;

        $latest = $wpdb->get_results(
            "SELECT COALESCE(i.term_id, 0) AS term_id, MAX(CAST(pm.meta_value AS UNSIGNED)) AS log_id
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_balmo_last_import'
             " . self::integrationJoin('p') . '
             WHERE ' . self::scope('p') . '
             GROUP BY COALESCE(i.term_id, 0)'
        ) ?: [];
        if (empty($latest)) return [];

        $in   = implode(',', array_map(fn($r): int => (int) $r->log_id, $latest));
        $logs = [];
        foreach ($wpdb->get_results(
            "SELECT id, created_at, status FROM " . \Balmo\table_import_log() . " WHERE id IN ({$in})"
        ) ?: [] as $row) {
            $logs[(int) $row->id] = $row;
        }

        $result = [];
        foreach ($latest as $row) {
            if (isset($logs[(int) $row->log_id])) {
                $result[(int) $row->term_id] = $logs[(int) $row->log_id];
            }
        }
        return $result;
    }

    // --- SQL pieces, shared by the counts, the Products list filter and the CSV export ---

    // Products of the shop that are not in the trash
    private static function scope(string $alias): string
    {
        return "{$alias}.post_type = 'product' AND {$alias}.post_status NOT IN ('trash', 'auto-draft')";
    }

    // Adds i.term_id: the integration of a product, NULL when it has none
    private static function integrationJoin(string $alias): string
    {
        global $wpdb;

        $taxonomy = esc_sql(Integration::TAXONOMY);

        return "LEFT JOIN (
                    SELECT r.object_id, MIN(t.term_id) AS term_id
                    FROM {$wpdb->term_relationships} r
                    INNER JOIN {$wpdb->term_taxonomy} t ON t.term_taxonomy_id = r.term_taxonomy_id
                    WHERE t.taxonomy = '{$taxonomy}'
                    GROUP BY r.object_id
                ) i ON i.object_id = {$alias}.ID";
    }

    // Boolean SQL expression for an audit column; null when it cannot be evaluated (no full import)
    private static function condition(string $column, string $alias, int $full_import_id = 0): ?string
    {
        global $wpdb;

        $id   = "{$alias}.ID";
        $meta = fn(string $key, string $extra): string =>
            "(SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = {$id} AND m.meta_key = '{$key}' AND {$extra})";

        switch ($column) {
            case 'all':
                return '1 = 1';
            case 'publish':
                return "{$alias}.post_status = 'publish'";
            case 'draft':
                return "{$alias}.post_status = 'draft'";
            case 'no_image':
                return 'NOT EXISTS ' . $meta('_thumbnail_id', "m.meta_value NOT IN ('', '0')");
            case 'not_in_full':
                return $full_import_id > 0
                    ? 'NOT EXISTS ' . $meta('_balmo_seen_full', "m.meta_value = '" . $full_import_id . "'")
                    : null;
            case 'no_price':
                return 'NOT EXISTS ' . $meta('_price', "m.meta_value <> '' AND CAST(m.meta_value AS DECIMAL(20,6)) > 0");
            case 'out_of_stock':
                return 'EXISTS ' . $meta('_stock_status', "m.meta_value = 'outofstock'");
            case 'no_category':
                $default = (int) get_option('default_product_cat');
                return "NOT EXISTS (SELECT 1 FROM {$wpdb->term_relationships} r
                        INNER JOIN {$wpdb->term_taxonomy} t ON t.term_taxonomy_id = r.term_taxonomy_id
                        WHERE r.object_id = {$id} AND t.taxonomy = 'product_cat' AND t.term_id <> {$default})";
        }

        return null;
    }

    private static function columnLabels(): array
    {
        return [
            'all'          => __('Wszystkie', 'balmo'),
            'publish'      => __('Opublikowane', 'balmo'),
            'draft'        => __('Szkice', 'balmo'),
            'no_image'     => __('Bez zdjęcia', 'balmo'),
            'not_in_full'  => __('Spoza ostatniego pełnego importu', 'balmo'),
            'no_price'     => __('Bez ceny', 'balmo'),
            'out_of_stock' => __('Stan 0', 'balmo'),
            'no_category'  => __('Bez kategorii', 'balmo'),
        ];
    }

    // --- Count cell: link to the Products list and to the CSV ---

    private static function renderCount(int $term_id, string $column, ?int $count): void
    {
        if ($count === null) {
            echo '—';
            return;
        }
        if ($count === 0) {
            echo '0';
            return;
        }

        $list_url = add_query_arg([
            'post_type'                => 'product',
            'balmo_integration_filter' => $term_id > 0 ? $term_id : 'none',
            self::PARAM                => $column,
        ], admin_url('edit.php'));

        $csv_url = add_query_arg([
            'action'      => 'balmo_audit_export',
            'nonce'       => wp_create_nonce(self::NONCE),
            'integration' => $term_id,
            'column'      => $column,
        ], admin_url('admin-ajax.php'));

        printf(
            '<a href="%s">%d</a> <small><a href="%s">CSV</a></small>',
            esc_url($list_url),
            $count,
            esc_url($csv_url)
        );
    }

    // --- Products list: ?balmo_audit=<column> together with the integration filter ---

    public static function applyListFilter(\WP_Query $query): void
    {
        global $pagenow;

        if ($pagenow !== 'edit.php' || !$query->is_main_query() || $query->get('post_type') !== 'product') return;

        $column = sanitize_key($_GET[self::PARAM] ?? '');
        if (!in_array($column, self::COLUMNS, true)) return;

        $integration = sanitize_text_field(wp_unslash($_GET['balmo_integration_filter'] ?? ''));
        $term_id     = ctype_digit($integration) ? (int) $integration : 0;

        $sql = self::columnWhere($column, $term_id);

        $filter = null;
        $filter = function (string $where, \WP_Query $q) use (&$filter, $query, $sql): string {
            if ($q !== $query) return $where;
            remove_filter('posts_where', $filter, 10);
            return $where . ' AND (' . $sql . ')';
        };
        add_filter('posts_where', $filter, 10, 2);
    }

    // Condition on the main posts table; '1 = 0' when the column cannot be evaluated
    private static function columnWhere(string $column, int $term_id): string
    {
        global $wpdb;

        $full = self::lastFullImports()[$term_id] ?? null;
        $sql  = self::condition($column, $wpdb->posts, $full ? (int) $full->id : 0);

        return $sql ?? '1 = 0';
    }

    // --- CSV export ---

    public static function ajaxExport(): void
    {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('manage_woocommerce')) wp_die(esc_html__('Brak uprawnień.', 'balmo'), '', ['response' => 403]);

        global $wpdb;

        $column  = sanitize_key($_GET['column'] ?? '');
        $term_id = absint($_GET['integration'] ?? 0);
        if (!in_array($column, self::COLUMNS, true)) wp_die(esc_html__('Nieprawidłowa kolumna.', 'balmo'));

        $full      = self::lastFullImports()[$term_id] ?? null;
        $condition = self::condition($column, 'p', $full ? (int) $full->id : 0);
        if ($condition === null) wp_die(esc_html__('Brak pełnego importu tej integracji.', 'balmo'));

        $integration_name = $term_id > 0
            ? (string) (Integration::terms()[$term_id] ?? '#' . $term_id)
            : __('Bez integracji', 'balmo');
        $reason = self::columnLabels()[$column];

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name('balmo-audit-' . $column . '-' . $term_id . '.csv') . '"');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM: spreadsheets read the file as UTF-8
        fputcsv($out, ['ID', 'SKU', 'Nazwa', 'Status', 'Integracja', 'Powód'], self::CSV_DELIM, '"', '\\');

        $after_id = 0;
        do {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT p.ID, p.post_title, p.post_status,
                        (SELECT s.meta_value FROM {$wpdb->postmeta} s
                         WHERE s.post_id = p.ID AND s.meta_key = '_sku' LIMIT 1) AS sku
                 FROM {$wpdb->posts} p " . self::integrationJoin('p') . '
                 WHERE ' . self::scope('p') . " AND COALESCE(i.term_id, 0) = %d AND p.ID > %d AND {$condition}
                 ORDER BY p.ID ASC LIMIT %d",
                $term_id, $after_id, self::CSV_CHUNK
            )) ?: [];

            foreach ($rows as $row) {
                fputcsv($out, [
                    (int) $row->ID,
                    self::csvCell((string) $row->sku),
                    self::csvCell((string) $row->post_title),
                    $row->post_status,
                    self::csvCell($integration_name),
                    $reason,
                ], self::CSV_DELIM, '"', '\\');
                $after_id = (int) $row->ID;
            }
        } while (count($rows) === self::CSV_CHUNK);

        fclose($out);
        exit;
    }

    // Spreadsheets run a cell starting with = + - @ as a formula
    private static function csvCell(string $value): string
    {
        return $value !== '' && strpbrk($value[0], '=+-@') !== false ? "'" . $value : $value;
    }
}
