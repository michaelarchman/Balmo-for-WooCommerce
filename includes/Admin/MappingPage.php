<?php

namespace Balmo\Admin;

if (!defined('ABSPATH')) exit;

class MappingPage
{
    // Pola WooCommerce dostępne do mapowania (bez zdjęć dodatkowych — te dodaje wcFields())
    public const WC_FIELDS = [
        // Podstawowe
        'post_title'        => 'Nazwa produktu',
        'post_content'      => 'Opis (pełny)',
        'post_excerpt'      => 'Krótki opis',
        'post_status'       => 'Status (publish/draft)',
        // Meta WC
        '_sku'              => 'SKU',
        '_regular_price'    => 'Cena regularna',
        '_sale_price'       => 'Cena promocyjna',
        '_stock'            => 'Stan magazynowy',
        '_manage_stock'     => 'Zarządzaj stanem',
        '_stock_status'     => 'Status magazynowy',
        '_weight'           => 'Waga (kg)',
        // Taksonomie
        '_categories'       => 'Kategoria produktu',
        '_tags'             => 'Tagi produktu',
        '_attributes'       => 'Atrybuty produktu',
        // Zdjęcia
        '_thumbnail'        => 'Główne zdjęcie (URL)',
        // Podatek
        'tax_class'         => 'Klasa podatkowa',
        // Produkt
        '_product_id_ext'   => 'ID zewnętrzne (product_id)',
        '_ean'              => 'EAN',
        '_producer'         => 'Producent',
        '_unit'             => 'Jednostka miary',
        // Pomijanie
        '_skip'             => '— Pomiń kolumnę —',
    ];

    // Automatyczne mapowanie dla CSV drop.seltu.pl
    public const AUTO_MAP = [
        'product_id'        => '_product_id_ext',
        'product_code'      => '_sku',
        'active'            => 'post_status',
        'name'              => 'post_title',
        'price'             => '_regular_price',
        'currency'          => '_skip',
        'vat'               => 'tax_class',
        'unit'              => '_unit',
        'category'          => '_categories',
        'producer'          => '_producer',
        'weight'            => '_weight',
        'ean'               => '_ean',
        'short_description' => 'post_excerpt',
        'description'       => 'post_content',
        'stock'             => '_stock',
        'images 1'          => '_thumbnail',
        'images 2'          => '_gallery_image_2',
        'images 3'          => '_gallery_image_3',
        'images 4'          => '_gallery_image_4',
        'images 5'          => '_gallery_image_5',
        'images 6'          => '_gallery_image_6',
        'images 7'          => '_gallery_image_7',
    ];

    // Automatyczne mapowanie dla JSON drop.seltu.pl
    public const AUTO_MAP_JSON = [
        'product_id'         => '_product_id_ext',
        'product_code'       => '_sku',
        'active'             => 'post_status',
        'name'               => 'post_title',
        'price'              => '_regular_price',
        'currency'           => '_skip',
        'vat'                => 'tax_class',
        'unit'               => '_unit',
        'category'           => '_categories',
        'producer'           => '_producer',
        'weight'             => '_weight',
        'ean'                => '_ean',
        'short_description'  => 'post_excerpt',
        'description'        => 'post_content',
        'stock'              => '_stock',
        'main_image'         => '_thumbnail',
        'attributes'         => '_attributes',
    ];

    // WC_FIELDS + zdjęcia dodatkowe 2 … BALMO_MAX_GALLERY_IMAGES + 1, zaraz po zdjęciu głównym
    public static function wcFields(): array
    {
        $fields = [];
        foreach (self::WC_FIELDS as $field => $label) {
            $fields[$field] = $label;
            if ($field !== '_thumbnail') continue;
            for ($n = 2; $n <= BALMO_MAX_GALLERY_IMAGES + 1; $n++) {
                $fields['_gallery_image_' . $n] = 'Zdjęcie dodatkowe ' . $n;
            }
        }
        return $fields;
    }

    // AUTO_MAP_JSON + additional_image_1 … additional_image_{BALMO_MAX_GALLERY_IMAGES}
    public static function autoMapJson(): array
    {
        $map = self::AUTO_MAP_JSON;
        for ($n = 1; $n <= BALMO_MAX_GALLERY_IMAGES; $n++) {
            $map['additional_image_' . $n] = '_gallery_image_' . ($n + 1);
        }
        return $map;
    }

    public static function init(): void
    {
        add_action('wp_ajax_balmo_save_mapping',   [self::class, 'ajaxSaveMapping']);
        add_action('wp_ajax_balmo_delete_mapping', [self::class, 'ajaxDeleteMapping']);
        add_action('wp_ajax_balmo_load_mapping',   [self::class, 'ajaxLoadMapping']);
        add_action('wp_ajax_balmo_preview_formula',[self::class, 'ajaxPreviewFormula']);
    }

    public static function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Brak uprawnień.', 'balmo'));
        }

        $action = sanitize_key($_GET['action'] ?? 'list');
        $id     = (int) ($_GET['id'] ?? 0);

        if ($action === 'edit' && $id) {
            self::renderForm($id);
        } elseif ($action === 'new') {
            self::renderForm(0);
        } else {
            self::renderList();
        }
    }

    private static function listUrl(): string
    {
        return admin_url('admin.php?page=balmo-mapping');
    }

    private static function renderList(): void
    {
        \Balmo\maybe_migrate_db();

        global $wpdb;
        $mappings = $wpdb->get_results(
            "SELECT * FROM " . \Balmo\table_mappings() . " ORDER BY name ASC"
        ) ?: [];

        $new_url = admin_url('admin.php?page=balmo-mapping&action=new');
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Profile mapowania', 'balmo'); ?></h1>
            <a href="<?php echo esc_url($new_url); ?>" class="page-title-action">
                <?php esc_html_e('Dodaj nowy', 'balmo'); ?>
            </a>
            <hr class="wp-header-end">

            <?php if (empty($mappings)): ?>
                <p><?php esc_html_e('Brak profili. ', 'balmo'); ?>
                   <a href="<?php echo esc_url($new_url); ?>"><?php esc_html_e('Dodaj pierwszy profil.', 'balmo'); ?></a>
                </p>
            <?php else: ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Nazwa', 'balmo'); ?></th>
                            <th><?php esc_html_e('Format', 'balmo'); ?></th>
                            <th><?php esc_html_e('Typ', 'balmo'); ?></th>
                            <th><?php esc_html_e('Dopasowanie', 'balmo'); ?></th>
                            <th><?php esc_html_e('Formuła cenowa', 'balmo'); ?></th>
                            <th><?php esc_html_e('Zaktualizowano', 'balmo'); ?></th>
                            <th><?php esc_html_e('Akcje', 'balmo'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mappings as $m):
                            $edit_url = admin_url('admin.php?page=balmo-mapping&action=edit&id=' . (int) $m->id);
                        ?>
                            <tr>
                                <td><a href="<?php echo esc_url($edit_url); ?>"><strong><?php echo esc_html($m->name); ?></strong></a></td>
                                <td><?php echo esc_html(strtoupper($m->format ?? 'csv')); ?></td>
                                <td><?php echo esc_html(strtoupper($m->source_type)); ?></td>
                                <td><?php echo esc_html($m->match_by === 'sku' ? 'SKU' : 'ID'); ?></td>
                                <td>
                                    <?php if ($m->price_formula): ?>
                                        <code><?php echo esc_html($m->price_formula); ?></code>
                                    <?php else: ?>
                                        <em><?php esc_html_e('brak', 'balmo'); ?></em>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($m->updated_at); ?></td>
                                <td>
                                    <a href="<?php echo esc_url($edit_url); ?>" class="button">
                                        <?php esc_html_e('Edytuj', 'balmo'); ?>
                                    </a>
                                    <button type="button" class="button balmo-btn-delete-mapping"
                                            data-id="<?php echo esc_attr($m->id); ?>"
                                            data-name="<?php echo esc_attr($m->name); ?>">
                                        <?php esc_html_e('Usuń', 'balmo'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function renderForm(int $id): void
    {
        \Balmo\maybe_migrate_db();

        global $wpdb;

        $m = null;
        if ($id) {
            $m = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM " . \Balmo\table_mappings() . " WHERE id = %d", $id
            ));
            if (!$m) {
                wp_die(esc_html__('Profil nie istnieje.', 'balmo'));
            }
        }

        $name             = $m->name             ?? '';
        $format           = $m->format           ?? 'csv';
        $source_type      = $m->source_type      ?? 'all';
        $match_by         = $m->match_by         ?? 'sku';
        $price_formula    = $m->price_formula    ?? '';
        $image_url_prefix = $m->image_url_prefix ?? '';
        $column_map       = $m ? (json_decode($m->column_map,    true) ?: []) : [];
        $overrides        = $m ? (json_decode($m->overrides,     true) ?: []) : [];
        $update_fields    = $m ? (json_decode($m->update_fields, true) ?: ['_regular_price', '_stock', 'post_status']) : ['_regular_price', '_stock', 'post_status'];

        $title    = $id ? sprintf(__('Edytujesz: %s', 'balmo'), $name) : __('Nowy profil mapowania', 'balmo');
        $list_url = self::listUrl();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php echo esc_html($title); ?></h1>
            <a href="<?php echo esc_url($list_url); ?>" class="page-title-action">
                <?php esc_html_e('← Wróć do listy', 'balmo'); ?>
            </a>
            <hr class="wp-header-end">

            <input type="hidden" id="balmo-mapping-id" value="<?php echo esc_attr($id ?: ''); ?>">

            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="balmo-mapping-name"><?php esc_html_e('Nazwa profilu', 'balmo'); ?></label></th>
                    <td><input type="text" id="balmo-mapping-name" class="regular-text"
                               value="<?php echo esc_attr($name); ?>"
                               placeholder="<?php esc_attr_e('np. Colibra JSON', 'balmo'); ?>"></td>
                </tr>
                <tr>
                    <th><label for="balmo-mapping-format"><?php esc_html_e('Format pliku', 'balmo'); ?></label></th>
                    <td>
                        <select id="balmo-mapping-format">
                            <option value="csv" <?php selected($format, 'csv'); ?>><?php esc_html_e('CSV', 'balmo'); ?></option>
                            <option value="json" <?php selected($format, 'json'); ?>><?php esc_html_e('JSON', 'balmo'); ?></option>
                            <option value="xml" <?php selected($format, 'xml'); ?>><?php esc_html_e('XML', 'balmo'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('JSON i XML zostaną automatycznie skonwertowane do CSV przed importem.', 'balmo'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="balmo-mapping-source-type"><?php esc_html_e('Typ pliku', 'balmo'); ?></label></th>
                    <td>
                        <select id="balmo-mapping-source-type">
                            <option value="all" <?php selected($source_type, 'all'); ?>><?php esc_html_e('ALL — pełne dane', 'balmo'); ?></option>
                            <option value="ica" <?php selected($source_type, 'ica'); ?>><?php esc_html_e('ICA — cena/stan/aktywność', 'balmo'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="balmo-mapping-match-by"><?php esc_html_e('Dopasowanie produktów', 'balmo'); ?></label></th>
                    <td>
                        <select id="balmo-mapping-match-by">
                            <option value="sku" <?php selected($match_by, 'sku'); ?>><?php esc_html_e('Po SKU (product_code)', 'balmo'); ?></option>
                            <option value="id"  <?php selected($match_by, 'id');  ?>><?php esc_html_e('Po ID zewnętrznym (product_id)', 'balmo'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('Jak identyfikować istniejące produkty WooCommerce.', 'balmo'); ?></p>
                    </td>
                </tr>
            </table>

            <h3><?php esc_html_e('Mapowanie kolumn', 'balmo'); ?></h3>
            <p>
                <button type="button" id="balmo-btn-automap" class="button">
                    <?php esc_html_e('Auto-mapuj (drop.seltu.pl)', 'balmo'); ?>
                </button>
                <button type="button" id="balmo-btn-add-column" class="button">
                    <?php esc_html_e('+ Dodaj wiersz', 'balmo'); ?>
                </button>
            </p>
            <table class="widefat" id="balmo-column-map-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Kolumna CSV/JSON', 'balmo'); ?></th>
                        <th><?php esc_html_e('→ Pole WooCommerce', 'balmo'); ?></th>
                        <th><?php esc_html_e('Nadpisanie (opcjonalne)', 'balmo'); ?></th>
                        <th><?php esc_html_e('Usuń', 'balmo'); ?></th>
                    </tr>
                </thead>
                <tbody id="balmo-column-map-body"></tbody>
            </table>

            <h3><?php esc_html_e('Formuła cenowa', 'balmo'); ?></h3>
            <p class="description">
                <?php esc_html_e('Zmienne: {price}, {vat}. Funkcje: round(x,d), ceil(x), floor(x).', 'balmo'); ?>
                &nbsp; <code>{price} * 1.23</code> &nbsp;|&nbsp; <code>ceil({price} * 1.2)</code>
            </p>
            <input type="text" id="balmo-price-formula" class="large-text"
                   value="<?php echo esc_attr($price_formula); ?>"
                   placeholder="<?php esc_attr_e('np. ceil({price} * 1.23)', 'balmo'); ?>">
            <p>
                <button type="button" id="balmo-btn-preview-formula" class="button">
                    <?php esc_html_e('Podgląd (testowa cena: 64.58)', 'balmo'); ?>
                </button>
                <span id="balmo-formula-preview"></span>
            </p>

            <h3><?php esc_html_e('Prefix URL zdjęć', 'balmo'); ?></h3>
            <p class="description">
                <?php esc_html_e('Dodawany do relatywnych ścieżek zdjęć. Zostaw puste jeśli JSON zawiera pełne URLe.', 'balmo'); ?>
                &nbsp; <code>https://drop.seltu.pl</code>
            </p>
            <input type="text" id="balmo-image-url-prefix" name="balmo-image-url-prefix" class="large-text"
                   value="<?php echo esc_attr($image_url_prefix); ?>"
                   placeholder="https://drop.seltu.pl">

            <h3><?php esc_html_e('Pola do aktualizacji', 'balmo'); ?></h3>
            <p class="description"><?php esc_html_e('Które pola nadpisywać przy imporcie aktualizacyjnym (ICA / tryb "tylko aktualizuj").', 'balmo'); ?></p>
            <?php
            $all_update_fields = [
                '_regular_price' => __('Cena', 'balmo'),
                '_stock'         => __('Stan magazynowy', 'balmo'),
                'post_status'    => __('Aktywność (publish/draft)', 'balmo'),
                'post_title'     => __('Nazwa produktu', 'balmo'),
                'post_content'   => __('Opis', 'balmo'),
                '_weight'        => __('Waga', 'balmo'),
                '_attributes'    => __('Atrybuty produktu', 'balmo'),
            ];
            foreach ($all_update_fields as $val => $label):
                $checked = in_array($val, $update_fields, true) ? 'checked' : '';
            ?>
            <label><input type="checkbox" name="balmo_update_fields[]" value="<?php echo esc_attr($val); ?>" <?php echo $checked; ?>> <?php echo esc_html($label); ?></label><br>
            <?php endforeach; ?>

            <p style="margin-top:20px;">
                <button type="button" id="balmo-btn-save-mapping" class="button button-primary">
                    <?php esc_html_e('Zapisz profil', 'balmo'); ?>
                </button>
                <a href="<?php echo esc_url($list_url); ?>" class="button" style="margin-left:8px;">
                    <?php esc_html_e('Anuluj', 'balmo'); ?>
                </a>
                <span id="balmo-mapping-save-result" style="margin-left:10px;"></span>
            </p>

        </div><!-- .wrap -->

        <?php if ($id): ?>
        <script>
        window.balmoEditMapping = {
            column_map: <?php echo wp_json_encode($column_map); ?>,
            overrides:  <?php echo wp_json_encode($overrides); ?>
        };
        </script>
        <?php endif; ?>

        <!-- Szablon wiersza mapowania (ukryty) -->
        <script type="text/html" id="balmo-column-row-tpl">
            <tr class="balmo-map-row">
                <td><input type="text" class="balmo-csv-col regular-text" value="{{csv_col}}"></td>
                <td>
                    <select class="balmo-wc-field">
                        <?php foreach (self::wcFields() as $val => $label): ?>
                            <option value="<?php echo esc_attr($val); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td><input type="text" class="balmo-override" placeholder="<?php esc_attr_e('pozostaw puste = użyj wartości z CSV', 'balmo'); ?>"></td>
                <td><button type="button" class="button balmo-btn-remove-row">✕</button></td>
            </tr>
        </script>

        <script>
        // Dane dla JS: auto-mapy i WC fields
        window.balmoAutoMap     = <?php echo wp_json_encode(self::AUTO_MAP); ?>;
        window.balmoAutoMapJson = <?php echo wp_json_encode(self::autoMapJson()); ?>;
        window.balmoWcFields    = <?php echo wp_json_encode(array_keys(self::wcFields())); ?>;
        </script>
        <?php
    }

    public static function ajaxSaveMapping(): void
    {
        check_ajax_referer('balmo_ajax', 'nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('Brak uprawnień.');

        \Balmo\maybe_migrate_db();

        global $wpdb;

        $id               = (int) ($_POST['id'] ?? 0);
        $name             = sanitize_text_field($_POST['name']             ?? '');
        $format           = sanitize_text_field($_POST['format']           ?? 'csv');
        $source_type      = sanitize_text_field($_POST['source_type']      ?? 'all');
        $match_by         = sanitize_text_field($_POST['match_by']         ?? 'sku');
        $price_formula    = sanitize_text_field($_POST['price_formula']    ?? '');
        $image_url_prefix = self::sanitizeImageUrlPrefix($_POST['image_url_prefix'] ?? '');
        $column_map       = $_POST['column_map']   ?? [];
        $overrides        = $_POST['overrides']    ?? [];
        $update_fields    = $_POST['update_fields'] ?? [];

        if (empty($name)) {
            wp_send_json_error(__('Podaj nazwę profilu.', 'balmo'));
            return;
        }

        // Sanitize arrays
        $column_map    = array_map('sanitize_text_field', (array) $column_map);
        $overrides     = array_map('sanitize_text_field', (array) $overrides);
        $update_fields = array_map('sanitize_text_field', (array) $update_fields);

        $data = [
            'name'             => $name,
            'format'           => \Balmo\Service\FeedLoader::normalizeFormat($format),
            'source_type'      => in_array($source_type, ['all', 'ica'], true) ? $source_type : 'all',
            'match_by'         => in_array($match_by, ['sku', 'id'], true) ? $match_by : 'sku',
            'price_formula'    => $price_formula,
            'image_url_prefix' => $image_url_prefix,
            'column_map'       => wp_json_encode($column_map),
            'overrides'        => wp_json_encode($overrides),
            'update_fields'    => wp_json_encode($update_fields),
        ];

        $formats = array_fill(0, count($data), '%s');

        if ($id > 0) {
            $updated = $wpdb->update(\Balmo\table_mappings(), $data, ['id' => $id], $formats, ['%d']);
            if ($updated === false) {
                wp_send_json_error(__('Błąd bazy danych: ', 'balmo') . $wpdb->last_error);
                return;
            }
            wp_send_json_success([
                'id'      => $id,
                'message' => __('Profil zaktualizowany.', 'balmo'),
            ]);
        } else {
            $inserted = $wpdb->insert(\Balmo\table_mappings(), $data, $formats);
            $new_id = (int) $wpdb->insert_id;
            if (!$inserted) {
                wp_send_json_error(__('Błąd bazy danych: ', 'balmo') . $wpdb->last_error);
                return;
            }
            wp_send_json_success([
                'id'      => $new_id,
                'message' => __('Profil zapisany.', 'balmo'),
            ]);
        }
    }

    private static function sanitizeImageUrlPrefix($value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        if (!preg_match('#^https?://#i', $value)) {
            $value = 'https://' . ltrim($value, '/');
        }

        return untrailingslashit(esc_url_raw($value));
    }

    public static function ajaxDeleteMapping(): void
    {
        check_ajax_referer('balmo_ajax', 'nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('Brak uprawnień.');

        $id = (int) ($_POST['id'] ?? 0);
        if (!$id) wp_send_json_error(__('Brak ID.', 'balmo'));

        global $wpdb;
        $wpdb->delete(\Balmo\table_mappings(), ['id' => $id], ['%d']);
        wp_send_json_success();
    }

    public static function ajaxLoadMapping(): void
    {
        check_ajax_referer('balmo_ajax', 'nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('Brak uprawnień.');

        $id = (int) ($_POST['id'] ?? 0);
        if (!$id) wp_send_json_error(__('Brak ID.', 'balmo'));

        global $wpdb;
        $m = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . \Balmo\table_mappings() . " WHERE id = %d", $id
        ));

        if (!$m) wp_send_json_error(__('Profil nie istnieje.', 'balmo'));

        wp_send_json_success([
            'id'               => (int) $m->id,
            'name'             => $m->name,
            'format'           => $m->format ?? 'csv',
            'source_type'      => $m->source_type,
            'match_by'         => $m->match_by,
            'price_formula'    => $m->price_formula,
            'image_url_prefix' => $m->image_url_prefix ?? '',
            'column_map'       => json_decode($m->column_map, true) ?: [],
            'overrides'        => json_decode($m->overrides,  true) ?: [],
            'update_fields'    => json_decode($m->update_fields, true) ?: [],
        ]);
    }

    public static function ajaxPreviewFormula(): void
    {
        check_ajax_referer('balmo_ajax', 'nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('Brak uprawnień.');

        $formula    = sanitize_text_field($_POST['formula'] ?? '');
        $test_price = floatval($_POST['test_price'] ?? 64.58);
        $test_vat   = floatval($_POST['test_vat']   ?? 23);

        $result = \Balmo\Service\PriceFormula::evaluate($formula, $test_price, $test_vat);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
            return;
        }

        wp_send_json_success(['result' => $result]);
    }
}
