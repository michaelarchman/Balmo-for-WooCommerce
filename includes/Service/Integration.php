<?php

namespace Balmo\Service;

if (!defined('ABSPATH')) exit;

/**
 * Integration = named product supplier (e.g. Colibra), kept as a private taxonomy on products.
 * Admin-only: no archive, URL or output on the storefront.
 */
class Integration
{
    public const TAXONOMY = 'balmo_integration';

    private const FILTER_PARAM = 'balmo_integration_filter';
    private const FILTER_NONE  = 'none';

    public static function register(): void
    {
        add_action('init', [self::class, 'registerTaxonomy']);

        if (is_admin()) {
            add_action('restrict_manage_posts', [self::class, 'renderFilter']);
            add_action('pre_get_posts',         [self::class, 'applyFilter']);
        }
    }

    public static function registerTaxonomy(): void
    {
        register_taxonomy(self::TAXONOMY, ['product', 'product_variation'], [
            'labels' => [
                'name'          => __('Integracja', 'balmo'),
                'singular_name' => __('Integracja', 'balmo'),
                'menu_name'     => __('Integracje', 'balmo'),
                'all_items'     => __('Wszystkie integracje', 'balmo'),
                'edit_item'     => __('Edytuj integrację', 'balmo'),
                'update_item'   => __('Zaktualizuj integrację', 'balmo'),
                'add_new_item'  => __('Dodaj integrację', 'balmo'),
                'new_item_name' => __('Nazwa nowej integracji', 'balmo'),
                'search_items'  => __('Szukaj integracji', 'balmo'),
                'not_found'     => __('Brak integracji.', 'balmo'),
            ],
            'capabilities' => [
                'manage_terms' => 'manage_woocommerce',
                'edit_terms'   => 'manage_woocommerce',
                'delete_terms' => 'manage_woocommerce',
                'assign_terms' => 'manage_woocommerce',
            ],
            'public'             => false,
            'publicly_queryable' => false,
            'show_ui'            => true,
            'show_in_menu'       => 'edit.php?post_type=product',
            'show_admin_column'  => true,
            'show_in_nav_menus'  => false,
            'show_tagcloud'      => false,
            'show_in_rest'       => false,
            'hierarchical'       => false,
            'rewrite'            => false,
            'query_var'          => false,
        ]);
    }

    // Integration term of a profile; 0 when the profile has none or the term is gone
    public static function forMapping(?object $mapping): int
    {
        $term_id = $mapping ? (int) ($mapping->integration_id ?? 0) : 0;
        if ($term_id <= 0 || !term_exists($term_id, self::TAXONOMY)) {
            return 0;
        }

        return $term_id;
    }

    // Tag a product with its integration and the import that touched it
    public static function tag(int $post_id, int $term_id, int $import_id, bool $is_new): void
    {
        if ($term_id > 0) {
            wp_set_object_terms($post_id, [$term_id], self::TAXONOMY, false);
        }

        update_post_meta($post_id, '_balmo_last_import', (string) $import_id);
        if ($is_new) {
            update_post_meta($post_id, '_balmo_created_import', (string) $import_id);
        }
    }

    // term_id => name, sorted by name
    public static function terms(): array
    {
        $terms = get_terms(['taxonomy' => self::TAXONOMY, 'hide_empty' => false]);
        if (is_wp_error($terms)) {
            return [];
        }

        $list = [];
        foreach ($terms as $term) {
            $list[(int) $term->term_id] = $term->name;
        }
        asort($list, SORT_NATURAL | SORT_FLAG_CASE);

        return $list;
    }

    // Filter dropdown above the Products list
    public static function renderFilter(string $post_type): void
    {
        if ($post_type !== 'product') return;

        $current = self::currentFilter();
        ?>
        <select name="<?php echo esc_attr(self::FILTER_PARAM); ?>">
            <option value=""><?php esc_html_e('Wszystkie integracje', 'balmo'); ?></option>
            <option value="<?php echo esc_attr(self::FILTER_NONE); ?>" <?php selected($current, self::FILTER_NONE); ?>>
                <?php esc_html_e('Bez integracji', 'balmo'); ?>
            </option>
            <?php foreach (self::terms() as $term_id => $name): ?>
                <option value="<?php echo esc_attr((string) $term_id); ?>" <?php selected($current, (string) $term_id); ?>>
                    <?php echo esc_html($name); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public static function applyFilter(\WP_Query $query): void
    {
        global $pagenow;

        if ($pagenow !== 'edit.php' || !$query->is_main_query() || $query->get('post_type') !== 'product') return;

        $current = self::currentFilter();
        if ($current === '') return;

        $clause = $current === self::FILTER_NONE
            ? ['taxonomy' => self::TAXONOMY, 'operator' => 'NOT EXISTS']
            : ['taxonomy' => self::TAXONOMY, 'field' => 'term_id', 'terms' => [(int) $current]];

        $tax_query   = (array) $query->get('tax_query');
        $tax_query[] = $clause;
        $query->set('tax_query', $tax_query);
    }

    // '' (no filter), 'none' or a term ID as string
    private static function currentFilter(): string
    {
        $value = sanitize_text_field(wp_unslash($_GET[self::FILTER_PARAM] ?? ''));
        if ($value === self::FILTER_NONE) {
            return $value;
        }

        return ctype_digit($value) && (int) $value > 0 ? $value : '';
    }
}
