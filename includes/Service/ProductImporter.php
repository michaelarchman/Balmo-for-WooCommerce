<?php

namespace Balmo\Service;

if (!defined('ABSPATH')) exit;

/**
 * Processes a batch of CSV rows and creates/updates WooCommerce products.
 * Designed for both AJAX (batches of ~200 rows)
 * and WP-Cron (all rows sequentially).
 */
class ProductImporter
{
    private int     $import_id;
    private object  $log;
    private ?object $mapping;
    private ColumnMapper $mapper;
    private string  $import_mode;
    private int     $current_row  = 0;
    private array   $current_data = [];

    // Per-instance caches, so a batch does not repeat lookups for the same attribute or term
    private ?array  $attribute_index = null; // lowercase label => attribute slug (without pa_)
    private array   $term_cache      = [];   // taxonomy|lowercase value => term_id
    private ?array  $skip_values     = null; // lowercase values dropped at import

    public function __construct(int $import_id, object $log, ?object $mapping)
    {
        $this->import_id    = $import_id;
        $this->log          = $log;
        $this->mapping      = $mapping;
        $this->mapper       = new ColumnMapper($mapping);
        $this->import_mode  = $log->import_mode ?? 'full';
    }

    /**
     * Process one batch of CSV rows.
     *
     * @param string $file_path Path to the CSV file
     * @param string $delimiter Separator CSV
     * @param int    $offset    First data row to process (header not counted)
     * @param int    $batch     Number of rows to process
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

        $warning = FeedRowFlattener::takeWarning($file_path);
        if ($warning !== null) {
            $this->logError(0, [], $warning);
        }

        // Skip BOM
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($handle);

        $headers = fgetcsv($handle, 0, $delimiter);
        if (!$headers) {
            fclose($handle);
            return $stats;
        }
        $headers = array_map('trim', $headers);

        // Seek to offset (skip rows before the batch)
        for ($i = 0; $i < $offset; $i++) {
            if (fgetcsv($handle, 0, $delimiter) === false) break;
        }

        // Process batch rows
        $row_num = $offset + 1;
        $count   = 0;

        while ($count < $batch && ($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rows_read++;
            // Empty rows: skip
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

    // Process one row, return 'created'|'updated'|'skipped'
    private function processRow(array $data, int $row_num): string
    {
        $this->current_row  = $row_num;
        $this->current_data = $data;

        $map_result = $this->mapper->mapRowWithReason($data, $this->import_mode);

        // Row has columns with an invalid value and no override: skip
        if ($map_result['result'] === null) {
            $cols = implode(', ', $map_result['invalid_columns']);
            $this->logError($row_num, $data, sprintf(
                __('Wiersz pominięty — nieprawidłowe wartości w kolumnach bez nadpisania: %s', 'balmo'),
                $cols
            ));
            return 'skipped';
        }

        $mapped = $map_result['result'];

        // Find existing product
        $match_by   = $this->mapper->getMatchBy();
        $sku        = $data['product_code'] ?? $mapped['_sku'] ?? '';
        $ext_id     = (int) ($data['product_id'] ?? 0);
        $product_id = $this->findProduct($match_by, $sku, $ext_id);

        if ($this->import_mode === 'update' && !$product_id) {
            return 'skipped'; // Update mode: do not create new products
        }

        if ($product_id) {
            $this->updateProduct($product_id, $mapped, $data);
            return 'updated';
        } else {
            $this->createProduct($mapped, $data);
            return 'created';
        }
    }

    // Find existing WC product by SKU or external ID meta
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

    // Create new product
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

        // Default product type: simple
        wp_set_object_terms($post_id, 'simple', 'product_type');

        $this->saveProductMeta($post_id, $mapped, $raw, true);
    }

    // Update existing product
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

    // Save WooCommerce product meta fields
    private function saveProductMeta(int $post_id, array $mapped, array $raw, bool $is_new): void
    {
        // SKU
        if (isset($mapped['_sku'])) {
            $sku = sanitize_text_field($mapped['_sku']);
            if ($sku !== '') {
                update_post_meta($post_id, '_sku', $sku);
            }
        }

        // Price
        if (isset($mapped['_regular_price'])) {
            $price = wc_format_decimal($mapped['_regular_price']);
            update_post_meta($post_id, '_regular_price', $price);
            update_post_meta($post_id, '_price', $price);
        }

        if (isset($mapped['_sale_price']) && $mapped['_sale_price'] !== '') {
            $sale = wc_format_decimal($mapped['_sale_price']);
            update_post_meta($post_id, '_sale_price', $sale);
        }

        // Stock
        if (isset($mapped['_stock'])) {
            $stock = (int) $mapped['_stock'];
            update_post_meta($post_id, '_manage_stock', 'yes');
            update_post_meta($post_id, '_stock', $stock);
            $stock_status = $stock > 0 ? 'instock' : 'outofstock';
            update_post_meta($post_id, '_stock_status', $stock_status);
            wc_update_product_stock_status($post_id, $stock_status);
        } elseif ($is_new) {
            // New product without a valid mapped stock: no stock management, default "in stock"
            update_post_meta($post_id, '_manage_stock', 'no');
            update_post_meta($post_id, '_stock_status', 'instock');
        }

        // Weight
        if (isset($mapped['_weight'])) {
            update_post_meta($post_id, '_weight', wc_format_decimal($mapped['_weight']));
        }

        // External ID
        if (isset($mapped['_product_id_ext'])) {
            update_post_meta($post_id, '_product_id_ext', sanitize_text_field($mapped['_product_id_ext']));
        }

        // EAN: custom meta always, native GTIN is set with the product save below
        $gtin = '';
        if (isset($mapped['_ean'])) {
            $ean = sanitize_text_field($mapped['_ean']);
            update_post_meta($post_id, '_ean', $ean);
            if (preg_match('/^(\d{8}|\d{12,14})$/', $ean)) {
                $gtin = $ean;
            }
        }

        // Producer
        if (isset($mapped['_producer'])) {
            update_post_meta($post_id, '_producer', sanitize_text_field($mapped['_producer']));
        }

        // Unit
        if (isset($mapped['_unit'])) {
            update_post_meta($post_id, '_unit', sanitize_text_field($mapped['_unit']));
        }

        // Tax class
        if (isset($mapped['tax_class'])) {
            update_post_meta($post_id, '_tax_class', sanitize_text_field($mapped['tax_class']));
        }

        // Kategorie — drzewo oddzielone " > "
        if (isset($mapped['_categories'])) {
            $this->assignCategories($post_id, $mapped['_categories']);
        }

        // Tags
        if (isset($mapped['_tags'])) {
            wp_set_object_terms($post_id, array_map('trim', explode(',', $mapped['_tags'])), 'product_tag');
        }

        // Images
        $this->saveImages($post_id, $mapped);

        // Clear WooCommerce cache
        wc_delete_product_transients($post_id);

        // Recalculate prices (required by WC)
        if ($is_new || isset($mapped['_regular_price']) || $gtin !== '') {
            $product = wc_get_product($post_id);
            if ($product) {
                if ($gtin !== '') {
                    $this->applyGtin($product, $gtin);
                }
                $product->save();
            }
        }

        // Attributes: after saving the price, so they work on a fresh product object
        if (isset($mapped['_attributes'])) {
            $this->assignAttributes($post_id, $mapped['_attributes']);
        }
    }

    // Native GTIN (WooCommerce 9.2+). A duplicate is logged and skipped; the row is still imported.
    private function applyGtin(\WC_Product $product, string $gtin): void
    {
        if (!method_exists($product, 'set_global_unique_id')) return;
        if ($product->get_global_unique_id() === $gtin) return;

        $owner = function_exists('wc_get_product_id_by_global_unique_id')
            ? (int) wc_get_product_id_by_global_unique_id($gtin)
            : 0;
        if ($owner > 0 && $owner !== $product->get_id()) {
            $this->logError($this->current_row, $this->current_data, sprintf(
                __('EAN %1$s jest już przypisany do produktu %2$d — GTIN pominięty.', 'balmo'),
                $gtin,
                $owner
            ));
            return;
        }

        try {
            $product->set_global_unique_id($gtin);
        } catch (\WC_Data_Exception $e) {
            $this->logError($this->current_row, $this->current_data, sprintf(
                __('EAN %1$s: GTIN pominięty — %2$s', 'balmo'),
                $gtin,
                $e->getMessage()
            ));
        }
    }

    /**
     * Set global (pa_*) attributes from a JSON list of {name, value} pairs.
     * Only attributes previously set by Balmo (meta _balmo_attribute_taxonomies) can be removed;
     * attributes added by hand stay. A failing attribute is logged and the rest is still saved.
     */
    private function assignAttributes(int $post_id, string $json): void
    {
        $items = json_decode($json, true);
        if (!is_array($items)) return;

        // taxonomy => term ids, in feed order
        $feed = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;

            $name  = trim((string) ($item['name'] ?? ''));
            $value = trim((string) ($item['value'] ?? ''));
            if ($name === '' || $value === '' || $this->isSkippedAttributeValue($value)) continue;

            $taxonomy = $this->resolveAttributeTaxonomy($name);
            if ($taxonomy === '') continue;

            $term_id = $this->resolveAttributeTerm($taxonomy, $value);
            if ($term_id === 0) continue;

            if (!isset($feed[$taxonomy])) {
                $feed[$taxonomy] = [];
            }
            if (!in_array($term_id, $feed[$taxonomy], true)) {
                $feed[$taxonomy][] = $term_id;
            }
        }

        $previous = get_post_meta($post_id, '_balmo_attribute_taxonomies', true);
        $previous = is_array($previous) ? $previous : [];

        if (empty($feed) && empty($previous)) return;

        $product = wc_get_product($post_id);
        if (!$product) return;

        $attributes = $product->get_attributes();

        foreach ($previous as $taxonomy) {
            if (isset($feed[$taxonomy])) continue;
            unset($attributes[$taxonomy]);
            wp_set_object_terms($post_id, [], $taxonomy);
        }

        $managed = [];
        foreach ($feed as $taxonomy => $term_ids) {
            $set = wp_set_object_terms($post_id, $term_ids, $taxonomy);
            if (is_wp_error($set)) {
                $this->logError($this->current_row, $this->current_data, sprintf(
                    __('Nie można przypisać atrybutu %1$s: %2$s', 'balmo'),
                    $taxonomy,
                    $set->get_error_message()
                ));
                continue;
            }

            $attribute = new \WC_Product_Attribute();
            $attribute->set_id((int) wc_attribute_taxonomy_id_by_name($taxonomy));
            $attribute->set_name($taxonomy);
            $attribute->set_options($term_ids);
            $attribute->set_position(isset($attributes[$taxonomy]) ? $attributes[$taxonomy]->get_position() : count($attributes));
            $attribute->set_visible(true);
            $attribute->set_variation(false);

            $attributes[$taxonomy] = $attribute;
            $managed[]             = $taxonomy;
        }

        $product->set_attributes($attributes);
        $product->save();

        if (empty($managed)) {
            delete_post_meta($post_id, '_balmo_attribute_taxonomies');
        } else {
            update_post_meta($post_id, '_balmo_attribute_taxonomies', $managed);
        }
    }

    private function isSkippedAttributeValue(string $value): bool
    {
        if ($this->skip_values === null) {
            $raw = (string) get_option('balmo_attribute_skip_values', \Balmo\Admin\Settings::DEFAULT_ATTRIBUTE_SKIP_VALUES);
            $this->skip_values = [];
            foreach (preg_split('/\R/', $raw) as $line) {
                $line = mb_strtolower(trim($line));
                if ($line !== '') {
                    $this->skip_values[$line] = true;
                }
            }
        }

        return isset($this->skip_values[mb_strtolower($value)]);
    }

    // Find or create the global attribute for a feed name; returns taxonomy (pa_*) or '' on failure
    private function resolveAttributeTaxonomy(string $name): string
    {
        if ($this->attribute_index === null) {
            $this->attribute_index = [];
            foreach (wc_get_attribute_taxonomies() as $tax) {
                $this->attribute_index[mb_strtolower($tax->attribute_label)] = $tax->attribute_name;
            }
        }

        $key = mb_strtolower($name);

        if (!isset($this->attribute_index[$key])) {
            $slug = $this->uniqueAttributeSlug($name);
            $id   = wc_create_attribute([
                'name'         => $name,
                'slug'         => $slug,
                'type'         => 'select',
                'order_by'     => 'menu_order',
                'has_archives' => false,
            ]);

            if (is_wp_error($id)) {
                $this->logError($this->current_row, $this->current_data, sprintf(
                    __('Nie można utworzyć atrybutu %1$s: %2$s', 'balmo'),
                    $name,
                    $id->get_error_message()
                ));
                return '';
            }

            $this->attribute_index[$key] = $slug;
        }

        $taxonomy = wc_attribute_taxonomy_name($this->attribute_index[$key]);

        // A taxonomy created in this request is not registered yet
        if (!taxonomy_exists($taxonomy)) {
            register_taxonomy($taxonomy, ['product'], [
                'hierarchical' => false,
                'show_ui'      => false,
                'query_var'    => true,
                'rewrite'      => false,
            ]);
        }

        return $taxonomy;
    }

    // Slug limited to 28 characters (WooCommerce limit); a collision after truncation gets a numeric suffix
    private function uniqueAttributeSlug(string $name): string
    {
        $base = rtrim(substr(sanitize_title($name), 0, 28), '-');
        if ($base === '') {
            $base = 'atrybut';
        }

        $slug = $base;
        for ($i = 2; in_array($slug, $this->attribute_index, true); $i++) {
            $suffix = '-' . $i;
            $slug   = rtrim(substr($base, 0, 28 - strlen($suffix)), '-') . $suffix;
        }

        return $slug;
    }

    // Find or create the term for a value; the value is never split on commas
    private function resolveAttributeTerm(string $taxonomy, string $value): int
    {
        $key = $taxonomy . '|' . mb_strtolower($value);
        if (isset($this->term_cache[$key])) {
            return $this->term_cache[$key];
        }

        $term = get_term_by('name', $value, $taxonomy);
        if ($term) {
            return $this->term_cache[$key] = (int) $term->term_id;
        }

        $result = wp_insert_term($value, $taxonomy);
        if (is_wp_error($result)) {
            $existing = (int) $result->get_error_data('term_exists');
            if ($existing > 0) {
                return $this->term_cache[$key] = $existing;
            }

            $this->logError($this->current_row, $this->current_data, sprintf(
                __('Nie można utworzyć wartości atrybutu "%1$s": %2$s', 'balmo'),
                $value,
                $result->get_error_message()
            ));
            return 0;
        }

        return $this->term_cache[$key] = (int) $result['term_id'];
    }

    // Create or find categories from a hierarchy such as "Women > Anklets"
    private function assignCategories(int $post_id, string $category_str): void
    {
        if (empty(trim($category_str))) return;

        $parts      = array_map('trim', explode('>', $category_str));
        $parent_id  = 0;
        $term_ids   = [];

        foreach ($parts as $cat_name) {
            if (empty($cat_name)) continue;

            // Look up an existing category under the given parent
            $term = get_term_by('name', $cat_name, 'product_cat');
            if (!$term) {
                // Create new category
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

    // Save images as external URLs (no download)
    private function saveImages(int $post_id, array $mapped): void
    {
        if (get_option('balmo_image_mode', 'none') === 'external') {
            $this->saveExternalImages($post_id, $mapped);
        }
    }

    // External mode: virtual WP attachments with an external URL
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
        $gallery_fields = [];
        foreach (array_keys($mapped) as $field) {
            if (preg_match('/^_gallery_image_(\d+)$/', (string) $field, $m)) {
                $gallery_fields[(int) $m[1]] = (string) $field;
            }
        }
        ksort($gallery_fields);

        foreach ($gallery_fields as $field) {
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

    // Create a virtual WP attachment for an external URL (no file download)
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

    // Sideload mode: download the image and save it to the media library
    private function logError(int $row_num, array $row_data, string $message): void
    {
        global $wpdb;
        $wpdb->insert(
            \Balmo\table_import_errors(),
            [
                'import_id'     => $this->import_id,
                'row_number'    => $row_num,
                'row_data'      => wp_json_encode(array_slice($row_data, 0, 10)), // max 10 fields
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
