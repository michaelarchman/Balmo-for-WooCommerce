<?php

namespace Balmo\Service;

if (!defined('ABSPATH')) exit;

/**
 * Przetwarza transzę wierszy CSV i tworzy/aktualizuje produkty WooCommerce.
 * Zaprojektowany do pracy zarówno z AJAX (transze po ~200 wierszy)
 * jak i z WP-Cron (wszystkie wiersze sekwencyjnie).
 */
class ProductImporter
{
    private int     $import_id;
    private object  $log;
    private ?object $mapping;
    private ColumnMapper $mapper;
    private string  $import_mode;

    public function __construct(int $import_id, object $log, ?object $mapping)
    {
        $this->import_id    = $import_id;
        $this->log          = $log;
        $this->mapping      = $mapping;
        $this->mapper       = new ColumnMapper($mapping);
        $this->import_mode  = $log->import_mode ?? 'full';
    }

    /**
     * Przetwórz jedną transzę wierszy CSV.
     *
     * @param string $file_path Ścieżka do pliku CSV
     * @param string $delimiter Separator CSV
     * @param int    $offset    Od którego wiersza danych (nie licząc nagłówka) zacząć
     * @param int    $batch     Ile wierszy przetworzyć
     * @return array ['processed', 'created', 'updated', 'skipped', 'errors']
     */
    public function processBatch(string $file_path, string $delimiter, int $offset, int $batch): array
    {
        $stats = ['processed' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'next_offset' => $offset];
        $started_at = microtime(true);
        $rows_read  = 0;

        $handle = fopen($file_path, 'r');
        if (!$handle) {
            $this->logError(0, [], __('Nie można otworzyć pliku CSV.', 'balmo'));
            $stats['errors']++;
            return $stats;
        }

        // Pomiń BOM
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($handle);

        // Wczytaj nagłówki
        $headers = fgetcsv($handle, 0, $delimiter);
        if (!$headers) {
            fclose($handle);
            return $stats;
        }
        $headers = array_map('trim', $headers);

        // Przesuń do offsetu (pomiń wiersze przed transzą)
        for ($i = 0; $i < $offset; $i++) {
            if (fgetcsv($handle, 0, $delimiter) === false) break;
        }

        // Przetwarzaj wiersze transzy
        $row_num = $offset + 1;
        $count   = 0;

        while ($count < $batch && ($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rows_read++;
            // Puste wiersze — pomiń
            if (count(array_filter($row, fn($v) => trim($v) !== '')) === 0) {
                $row_num++;
                $stats['next_offset'] = $offset + $rows_read;
                if ((microtime(true) - $started_at) >= BALMO_BATCH_TIME_LIMIT) {
                    break;
                }
                continue;
            }

            $data = $this->combineRow($headers, $row);
            \Balmo\mark_import_heartbeat($this->import_id, $offset + $rows_read - 1, [
                'row_number' => $row_num,
                'sku'        => (string) ($data['product_code'] ?? ''),
                'product_id' => (string) ($data['product_id'] ?? ''),
            ]);

            try {
                $result = $this->processRow($data, $row_num);
                $stats[$result]++;
            } catch (\Throwable $e) {
                $this->logError($row_num, $data, $e->getMessage());
                $stats['errors']++;
            }

            $stats['processed']++;
            $row_num++;
            $count++;
            $stats['next_offset'] = $offset + $rows_read;

            if ((microtime(true) - $started_at) >= BALMO_BATCH_TIME_LIMIT) {
                break;
            }
        }

        fclose($handle);
        return $stats;
    }

    // Przetwórz pojedynczy wiersz, zwróć 'created'|'updated'|'skipped'
    private function processRow(array $data, int $row_num): string
    {
        $map_result = $this->mapper->mapRowWithReason($data, $this->import_mode);

        // Wiersz ma kolumny z nieprawidłową wartością bez override → pomijamy
        if ($map_result['result'] === null) {
            $cols = implode(', ', $map_result['invalid_columns']);
            $this->logError($row_num, $data, sprintf(
                __('Wiersz pominięty — nieprawidłowe wartości w kolumnach bez nadpisania: %s', 'balmo'),
                $cols
            ));
            return 'skipped';
        }

        $mapped = $map_result['result'];

        // Znajdź istniejący produkt
        $match_by   = $this->mapper->getMatchBy();
        $sku        = $data['product_code'] ?? $mapped['_sku'] ?? '';
        $ext_id     = (int) ($data['product_id'] ?? 0);
        $product_id = $this->findProduct($match_by, $sku, $ext_id);

        if ($this->import_mode === 'update' && !$product_id) {
            return 'skipped'; // Tryb update — nie twórz nowych
        }

        if ($product_id) {
            $this->updateProduct($product_id, $mapped, $data);
            return 'updated';
        } else {
            $this->createProduct($mapped, $data);
            return 'created';
        }
    }

    // Znajdź istniejący produkt WC po SKU lub meta ID zewnętrznym
    private function findProduct(string $match_by, string $sku, int $ext_id): int
    {
        global $wpdb;

        if ($match_by === 'sku' && !empty($sku)) {
            $product_id = wc_get_product_id_by_sku($sku);
            if ($product_id) return $product_id;
        }

        if ($match_by === 'id' && $ext_id > 0) {
            $product_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_product_id_ext' AND meta_value = %s LIMIT 1",
                (string) $ext_id
            ));
            if ($product_id) return $product_id;
        }

        return 0;
    }

    // Utwórz nowy produkt
    private function createProduct(array $mapped, array $raw): void
    {
        $title      = $mapped['post_title'] ?? '';
        $content    = $mapped['post_content'] ?? '';
        $excerpt    = $mapped['post_excerpt'] ?? '';
        $status     = $mapped['post_status'] ?? get_option('balmo_default_status', 'publish');

        if (empty($title)) {
            throw new \InvalidArgumentException(__('Brak nazwy produktu (post_title).', 'balmo'));
        }

        $post_id = wp_insert_post([
            'post_title'   => sanitize_text_field($title),
            'post_content' => wp_slash($content),
            'post_excerpt' => wp_slash($excerpt),
            'post_status'  => in_array($status, ['publish', 'draft', 'pending'], true) ? $status : 'publish',
            'post_type'    => 'product',
        ], true);

        if (is_wp_error($post_id)) {
            throw new \InvalidArgumentException($post_id->get_error_message());
        }

        // Domyślny typ produktu: simple
        wp_set_object_terms($post_id, 'simple', 'product_type');

        $this->saveProductMeta($post_id, $mapped, $raw, true);
    }

    // Zaktualizuj istniejący produkt
    private function updateProduct(int $product_id, array $mapped, array $raw): void
    {
        $update = [];

        if (isset($mapped['post_title'])) {
            $update['post_title'] = sanitize_text_field($mapped['post_title']);
        }
        if (isset($mapped['post_content'])) {
            $update['post_content'] = wp_slash($mapped['post_content']);
        }
        if (isset($mapped['post_excerpt'])) {
            $update['post_excerpt'] = wp_slash($mapped['post_excerpt']);
        }
        if (isset($mapped['post_status'])) {
            $status = $mapped['post_status'];
            $update['post_status'] = in_array($status, ['publish', 'draft', 'pending'], true) ? $status : 'publish';
        }

        if (!empty($update)) {
            $update['ID'] = $product_id;
            wp_update_post($update);
        }

        $this->saveProductMeta($product_id, $mapped, $raw, false);
    }

    // Zapisz meta pola produktu WooCommerce
    private function saveProductMeta(int $post_id, array $mapped, array $raw, bool $is_new): void
    {
        // SKU
        if (isset($mapped['_sku'])) {
            $sku = sanitize_text_field($mapped['_sku']);
            if ($sku !== '') {
                update_post_meta($post_id, '_sku', $sku);
            }
        }

        // Cena
        if (isset($mapped['_regular_price'])) {
            $price = wc_format_decimal($mapped['_regular_price']);
            update_post_meta($post_id, '_regular_price', $price);
            update_post_meta($post_id, '_price', $price);
        }

        if (isset($mapped['_sale_price']) && $mapped['_sale_price'] !== '') {
            $sale = wc_format_decimal($mapped['_sale_price']);
            update_post_meta($post_id, '_sale_price', $sale);
        }

        // Stan magazynowy
        if (isset($mapped['_stock'])) {
            $stock = (int) $mapped['_stock'];
            update_post_meta($post_id, '_manage_stock', 'yes');
            update_post_meta($post_id, '_stock', $stock);
            $stock_status = $stock > 0 ? 'instock' : 'outofstock';
            update_post_meta($post_id, '_stock_status', $stock_status);
            wc_update_product_stock_status($post_id, $stock_status);
        } elseif ($is_new) {
            // Nowy produkt bez zamapowanego/prawidłowego stanu — brak zarządzania, domyślnie "w magazynie"
            update_post_meta($post_id, '_manage_stock', 'no');
            update_post_meta($post_id, '_stock_status', 'instock');
        }

        // Waga
        if (isset($mapped['_weight'])) {
            update_post_meta($post_id, '_weight', wc_format_decimal($mapped['_weight']));
        }

        // ID zewnętrzne
        if (isset($mapped['_product_id_ext'])) {
            update_post_meta($post_id, '_product_id_ext', sanitize_text_field($mapped['_product_id_ext']));
        }

        // EAN
        if (isset($mapped['_ean'])) {
            update_post_meta($post_id, '_ean', sanitize_text_field($mapped['_ean']));
        }

        // Producent
        if (isset($mapped['_producer'])) {
            update_post_meta($post_id, '_producer', sanitize_text_field($mapped['_producer']));
        }

        // Jednostka
        if (isset($mapped['_unit'])) {
            update_post_meta($post_id, '_unit', sanitize_text_field($mapped['_unit']));
        }

        // Klasa podatkowa
        if (isset($mapped['tax_class'])) {
            update_post_meta($post_id, '_tax_class', sanitize_text_field($mapped['tax_class']));
        }

        // Kategorie — drzewo oddzielone " > "
        if (isset($mapped['_categories'])) {
            $this->assignCategories($post_id, $mapped['_categories']);
        }

        // Tagi
        if (isset($mapped['_tags'])) {
            wp_set_object_terms($post_id, array_map('trim', explode(',', $mapped['_tags'])), 'product_tag');
        }

        // Zdjęcia
        $this->saveImages($post_id, $mapped);

        // Wyczyść cache WooCommerce
        wc_delete_product_transients($post_id);

        // Przelicz ceny (ważne dla WC)
        if ($is_new || isset($mapped['_regular_price'])) {
            $product = wc_get_product($post_id);
            if ($product) {
                $product->save();
            }
        }
    }

    // Utwórz/znajdź kategorie z hierarchii "Kobiety > Bransoletki na nogę"
    private function assignCategories(int $post_id, string $category_str): void
    {
        if (empty(trim($category_str))) return;

        $parts      = array_map('trim', explode('>', $category_str));
        $parent_id  = 0;
        $term_ids   = [];

        foreach ($parts as $cat_name) {
            if (empty($cat_name)) continue;

            // Szukaj istniejącej kategorii pod danym rodzicem
            $term = get_term_by('name', $cat_name, 'product_cat');
            if (!$term) {
                // Utwórz nową kategorię
                $result = wp_insert_term($cat_name, 'product_cat', ['parent' => $parent_id]);
                if (is_wp_error($result)) continue;
                $term_id = $result['term_id'];
            } else {
                $term_id = $term->term_id;
            }

            $term_ids[] = $term_id;
            $parent_id  = $term_id;
        }

        if (!empty($term_ids)) {
            wp_set_object_terms($post_id, $term_ids, 'product_cat');
        }
    }

    // Zapisz zdjęcia jako zewnętrzne URL (bez pobierania)
    private function saveImages(int $post_id, array $mapped): void
    {
        if (get_option('balmo_image_mode', 'none') === 'external') {
            $this->saveExternalImages($post_id, $mapped);
        }
    }

    // Tryb external — wirtualne załączniki WP z zewnętrznym URL
    private function saveExternalImages(int $post_id, array $mapped): void
    {
        $main_url = isset($mapped['_thumbnail']) ? esc_url_raw((string) $mapped['_thumbnail']) : '';

        if ($main_url !== '') {
            $attach_id = $this->getOrCreateExternalAttachment($main_url, $post_id);
            if ($attach_id) {
                set_post_thumbnail($post_id, $attach_id);
            }
        }

        $has_gallery_columns = false;
        $gallery_ids = [];
        for ($i = 2; $i <= 7; $i++) {
            $field = "_gallery_image_{$i}";
            if (!array_key_exists($field, $mapped)) continue;
            $has_gallery_columns = true;
            $url = esc_url_raw((string) $mapped[$field]);
            if ($url === '') continue;
            $id = $this->getOrCreateExternalAttachment($url, $post_id);
            if ($id) $gallery_ids[] = $id;
        }

        if ($has_gallery_columns) {
            update_post_meta($post_id, '_product_image_gallery', implode(',', $gallery_ids));
        }
    }

    // Utwórz wirtualny załącznik WP dla zewnętrznego URL (bez pobierania pliku)
    private function getOrCreateExternalAttachment(string $url, int $post_id): int
    {
        global $wpdb;

        $existing = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_balmo_external_url' AND meta_value = %s LIMIT 1",
            $url
        ));
        if ($existing) return $existing;

        $path     = wp_parse_url($url, PHP_URL_PATH) ?: '';
        $filename = sanitize_file_name(wp_basename($path));
        if ($filename === '' || $filename === '.') {
            $filename = 'balmo-' . md5($url) . '.jpg';
        }

        $attach_id = wp_insert_post([
            'post_title'     => $filename,
            'post_status'    => 'inherit',
            'post_type'      => 'attachment',
            'post_mime_type' => $this->guessMimeType($filename),
            'post_parent'    => $post_id,
            'guid'           => $url,
        ]);

        if (is_wp_error($attach_id) || !$attach_id) return 0;

        $id = (int) $attach_id;
        update_post_meta($id, '_balmo_external_url', $url);
        update_post_meta($id, '_wp_attached_file', $url);
        update_post_meta($id, '_wp_attachment_metadata', [
            'width'  => 0,
            'height' => 0,
            'file'   => $url,
            'sizes'  => [],
        ]);

        return $id;
    }

    private function guessMimeType(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $map = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'webp' => 'image/webp',
            'gif'  => 'image/gif',
            'avif' => 'image/avif',
        ];
        return $map[$ext] ?? 'image/jpeg';
    }

    // Tryb sideload — pobierz obraz i zapisz w bibliotece mediów
    private function logError(int $row_num, array $row_data, string $message): void
    {
        global $wpdb;
        $wpdb->insert(
            \Balmo\table_import_errors(),
            [
                'import_id'     => $this->import_id,
                'row_number'    => $row_num,
                'row_data'      => wp_json_encode(array_slice($row_data, 0, 10)), // max 10 pól
                'error_message' => mb_substr($message, 0, 500),
            ],
            ['%d', '%d', '%s', '%s']
        );
    }

    private function combineRow(array $headers, array $row): array
    {
        $data = [];
        $max  = count($headers);

        for ($i = 0; $i < $max; $i++) {
            $header = trim((string) ($headers[$i] ?? ''));
            if ($header === '') {
                continue;
            }

            $data[$header] = isset($row[$i]) ? (string) $row[$i] : '';
        }

        return $data;
    }
}
