<?php

namespace Balmo\Admin;

if (!defined('ABSPATH')) exit;

class MappingPage
{
    // Pola WooCommerce dostępne do mapowania
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
        // Zdjęcia
        '_thumbnail'        => 'Główne zdjęcie (URL)',
        '_gallery_image_2'  => 'Zdjęcie dodatkowe 2',
        '_gallery_image_3'  => 'Zdjęcie dodatkowe 3',
        '_gallery_image_4'  => 'Zdjęcie dodatkowe 4',
        '_gallery_image_5'  => 'Zdjęcie dodatkowe 5',
        '_gallery_image_6'  => 'Zdjęcie dodatkowe 6',
        '_gallery_image_7'  => 'Zdjęcie dodatkowe 7',
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

    // Automatyczne mapowanie dla znanych kolumn drop.seltu.pl
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
        // ICA
        'currency'          => '_skip',
    ];

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

        global $wpdb;
        $mappings = $wpdb->get_results(
            "SELECT * FROM " . \Balmo\table_mappings() . " ORDER BY name ASC"
        ) ?: [];

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Profile mapowania', 'balmo'); ?></h1>
            <p class="description">
                <?php esc_html_e('Profil mapowania określa, które kolumny CSV trafiają do których pól WooCommerce. Możesz też nadpisać wartość całej kolumny (np. wpisując "23" w nadpisaniu dla "vat" wszystkie wartości zostaną zastąpione przez 23).', 'balmo'); ?>
            </p>

            <!-- Lista zapisanych profili -->
            <h2><?php esc_html_e('Zapisane profile', 'balmo'); ?></h2>
            <?php if (empty($mappings)): ?>
                <p><?php esc_html_e('Brak zapisanych profili. Stwórz nowy poniżej lub podczas importu.', 'balmo'); ?></p>
            <?php else: ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Nazwa', 'balmo'); ?></th>
                            <th><?php esc_html_e('Typ', 'balmo'); ?></th>
                            <th><?php esc_html_e('Dopasowanie', 'balmo'); ?></th>
                            <th><?php esc_html_e('Formuła cenowa', 'balmo'); ?></th>
                            <th><?php esc_html_e('Zaktualizowano', 'balmo'); ?></th>
                            <th><?php esc_html_e('Akcje', 'balmo'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mappings as $m): ?>
                            <tr>
                                <td><strong><?php echo esc_html($m->name); ?></strong></td>
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
                                    <button type="button" class="button balmo-btn-edit-mapping"
                                            data-id="<?php echo esc_attr($m->id); ?>">
                                        <?php esc_html_e('Edytuj', 'balmo'); ?>
                                    </button>
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

            <hr>

            <!-- Formularz nowego/edytowanego profilu -->
            <h2 id="balmo-mapping-form-title"><?php esc_html_e('Nowy profil mapowania', 'balmo'); ?></h2>
            <input type="hidden" id="balmo-mapping-id" value="">

            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="balmo-mapping-name"><?php esc_html_e('Nazwa profilu', 'balmo'); ?></label></th>
                    <td><input type="text" id="balmo-mapping-name" class="regular-text" placeholder="np. Colibra PL"></td>
                </tr>
                <tr>
                    <th><label for="balmo-mapping-source-type"><?php esc_html_e('Typ pliku', 'balmo'); ?></label></th>
                    <td>
                        <select id="balmo-mapping-source-type">
                            <option value="all"><?php esc_html_e('ALL — pełne dane', 'balmo'); ?></option>
                            <option value="ica"><?php esc_html_e('ICA — cena/stan/aktywność', 'balmo'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="balmo-mapping-match-by"><?php esc_html_e('Dopasowanie produktów', 'balmo'); ?></label></th>
                    <td>
                        <select id="balmo-mapping-match-by">
                            <option value="sku"><?php esc_html_e('Po SKU (product_code)', 'balmo'); ?></option>
                            <option value="id"><?php esc_html_e('Po ID zewnętrznym (product_id)', 'balmo'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('Jak identyfikować istniejące produkty WooCommerce.', 'balmo'); ?></p>
                    </td>
                </tr>
            </table>

            <!-- Tabela mapowania kolumn (wypełniana przez JS po wczytaniu nagłówków) -->
            <h3><?php esc_html_e('Mapowanie kolumn', 'balmo'); ?></h3>
            <p class="description">
                <?php esc_html_e('Wczytaj najpierw plik CSV na stronie Import, aby zobaczyć kolumny. Lub wpisz nazwy kolumn ręcznie.', 'balmo'); ?>
            </p>
            <p>
                <button type="button" id="balmo-btn-automap" class="button">
                    <?php esc_html_e('Auto-mapuj kolumny drop.seltu.pl', 'balmo'); ?>
                </button>
                <button type="button" id="balmo-btn-add-column" class="button">
                    <?php esc_html_e('+ Dodaj wiersz', 'balmo'); ?>
                </button>
            </p>
            <table class="widefat" id="balmo-column-map-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Kolumna CSV', 'balmo'); ?></th>
                        <th><?php esc_html_e('→ Pole WooCommerce', 'balmo'); ?></th>
                        <th><?php esc_html_e('Nadpisanie (opcjonalne)', 'balmo'); ?></th>
                        <th><?php esc_html_e('Usuń', 'balmo'); ?></th>
                    </tr>
                </thead>
                <tbody id="balmo-column-map-body">
                    <!-- wiersze generowane przez JS -->
                </tbody>
            </table>

            <!-- Formuła cenowa -->
            <h3><?php esc_html_e('Formuła cenowa', 'balmo'); ?></h3>
            <p class="description">
                <?php esc_html_e('Wyrażenie matematyczne przeliczające cenę z CSV. Dostępne zmienne: {price}, {vat}. Funkcje: round(x,d), ceil(x), floor(x). Przykłady:', 'balmo'); ?>
                <br>
                <code>{price} * 1.23</code> &nbsp;|&nbsp;
                <code>ceil({price} * 1.2)</code> &nbsp;|&nbsp;
                <code>floor({price} * 1.2 / 0.1) * 0.1 + 0.99</code>
            </p>
            <input type="text" id="balmo-price-formula" class="large-text"
                   placeholder="<?php esc_attr_e('np. ceil({price} * 1.23)', 'balmo'); ?>">
            <p>
                <button type="button" id="balmo-btn-preview-formula" class="button">
                    <?php esc_html_e('Podgląd formuły (testowa cena: 64.58)', 'balmo'); ?>
                </button>
                <span id="balmo-formula-preview"></span>
            </p>

            <!-- Pola do aktualizacji (dla trybu ICA/update) -->
            <h3><?php esc_html_e('Pola do aktualizacji', 'balmo'); ?></h3>
            <p class="description">
                <?php esc_html_e('Które pola nadpisywać przy imporcie aktualizacyjnym (ICA lub pełny w trybie "tylko aktualizuj").', 'balmo'); ?>
            </p>
            <label><input type="checkbox" name="balmo_update_fields[]" value="_regular_price" checked> <?php esc_html_e('Cena', 'balmo'); ?></label><br>
            <label><input type="checkbox" name="balmo_update_fields[]" value="_stock" checked> <?php esc_html_e('Stan magazynowy', 'balmo'); ?></label><br>
            <label><input type="checkbox" name="balmo_update_fields[]" value="post_status" checked> <?php esc_html_e('Aktywność (publish/draft)', 'balmo'); ?></label><br>
            <label><input type="checkbox" name="balmo_update_fields[]" value="post_title"> <?php esc_html_e('Nazwa produktu', 'balmo'); ?></label><br>
            <label><input type="checkbox" name="balmo_update_fields[]" value="post_content"> <?php esc_html_e('Opis', 'balmo'); ?></label><br>
            <label><input type="checkbox" name="balmo_update_fields[]" value="_weight"> <?php esc_html_e('Waga', 'balmo'); ?></label><br>

            <p style="margin-top:20px;">
                <button type="button" id="balmo-btn-save-mapping" class="button button-primary">
                    <?php esc_html_e('Zapisz profil', 'balmo'); ?>
                </button>
                <span id="balmo-mapping-save-result" style="margin-left:10px;"></span>
            </p>

        </div><!-- .wrap -->

        <!-- Szablon wiersza mapowania (ukryty) -->
        <script type="text/html" id="balmo-column-row-tpl">
            <tr class="balmo-map-row">
                <td><input type="text" class="balmo-csv-col regular-text" value="{{csv_col}}"></td>
                <td>
                    <select class="balmo-wc-field">
                        <?php foreach (self::WC_FIELDS as $val => $label): ?>
                            <option value="<?php echo esc_attr($val); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td><input type="text" class="balmo-override" placeholder="<?php esc_attr_e('pozostaw puste = użyj wartości z CSV', 'balmo'); ?>"></td>
                <td><button type="button" class="button balmo-btn-remove-row">✕</button></td>
            </tr>
        </script>

        <script>
        // Dane dla JS: auto-mapa i WC fields
        window.balmoAutoMap   = <?php echo json_encode(self::AUTO_MAP); ?>;
        window.balmoWcFields  = <?php echo json_encode(array_keys(self::WC_FIELDS)); ?>;
        </script>
        <?php
    }

    public static function ajaxSaveMapping(): void
    {
        check_ajax_referer('balmo_ajax', 'nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('Brak uprawnień.');

        global $wpdb;

        $id           = (int) ($_POST['id'] ?? 0);
        $name         = sanitize_text_field($_POST['name']         ?? '');
        $source_type  = sanitize_text_field($_POST['source_type']  ?? 'all');
        $match_by     = sanitize_text_field($_POST['match_by']     ?? 'sku');
        $price_formula = sanitize_text_field($_POST['price_formula'] ?? '');
        $column_map   = $_POST['column_map']   ?? [];
        $overrides    = $_POST['overrides']    ?? [];
        $update_fields = $_POST['update_fields'] ?? [];

        if (empty($name)) {
            wp_send_json_error(__('Podaj nazwę profilu.', 'balmo'));
            return;
        }

        // Sanitize arrays
        $column_map    = array_map('sanitize_text_field', (array) $column_map);
        $overrides     = array_map('sanitize_text_field', (array) $overrides);
        $update_fields = array_map('sanitize_text_field', (array) $update_fields);

        $data = [
            'name'          => $name,
            'source_type'   => in_array($source_type, ['all', 'ica'], true) ? $source_type : 'all',
            'match_by'      => in_array($match_by, ['sku', 'id'], true) ? $match_by : 'sku',
            'price_formula' => $price_formula,
            'column_map'    => wp_json_encode($column_map),
            'overrides'     => wp_json_encode($overrides),
            'update_fields' => wp_json_encode($update_fields),
        ];

        if ($id > 0) {
            $wpdb->update(\Balmo\table_mappings(), $data, ['id' => $id], array_fill(0, count($data), '%s'), ['%d']);
            wp_send_json_success(['id' => $id, 'message' => __('Profil zaktualizowany.', 'balmo')]);
        } else {
            $wpdb->insert(\Balmo\table_mappings(), $data, array_fill(0, count($data), '%s'));
            wp_send_json_success(['id' => (int) $wpdb->insert_id, 'message' => __('Profil zapisany.', 'balmo')]);
        }
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
            'id'            => (int) $m->id,
            'name'          => $m->name,
            'source_type'   => $m->source_type,
            'match_by'      => $m->match_by,
            'price_formula' => $m->price_formula,
            'column_map'    => json_decode($m->column_map, true) ?: [],
            'overrides'     => json_decode($m->overrides,  true) ?: [],
            'update_fields' => json_decode($m->update_fields, true) ?: [],
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
