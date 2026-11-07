<?php

namespace Balmo\Service;

if (!defined('ABSPATH')) exit;

/**
 * Maps a CSV row to an array of WooCommerce fields.
 * Applies value overrides and price formulas.
 */
class ColumnMapper
{
    private array  $column_map;      // ['csv_col' => 'wc_field']
    private array  $overrides;       // ['csv_col' => 'static_value'] or ['wc_field' => 'static_value']
    private string $price_formula;
    private string $match_by;
    private array  $update_fields;   // fields to update (update mode)
    private string $image_url_prefix; // image URL prefix (e.g. https://drop.seltu.pl/)

    public function __construct(?object $mapping)
    {
        if ($mapping) {
            $this->column_map      = json_decode($mapping->column_map,    true) ?: [];
            $this->overrides       = json_decode($mapping->overrides,     true) ?: [];
            $this->price_formula   = $mapping->price_formula ?? '';
            $this->match_by        = $mapping->match_by ?? 'sku';
            $this->update_fields   = json_decode($mapping->update_fields, true) ?: ['_regular_price', '_stock', 'post_status'];
            $this->image_url_prefix = rtrim($mapping->image_url_prefix ?? '', '/');
        } else {
            // Use the auto-map from MappingPage
            $this->column_map       = \Balmo\Admin\MappingPage::AUTO_MAP;
            $this->overrides        = [];
            $this->price_formula    = '';
            $this->match_by         = 'sku';
            $this->update_fields    = ['_regular_price', '_stock', 'post_status'];
            $this->image_url_prefix = '';
        }
    }

    /**
     * Map a row and return the skip reason if the row is invalid.
     *
     * @return array{result: array|null, invalid_columns: string[]}
     */
    public function mapRowWithReason(array $row, string $import_mode = 'full'): array
    {
        $mapped          = [];
        $invalid_columns = [];

        foreach ($this->column_map as $csv_col => $wc_field) {
            if ($wc_field === '_skip') continue;

            $has_override = isset($this->overrides[$csv_col]) && $this->overrides[$csv_col] !== '';

            if ($has_override) {
                $mapped[$wc_field] = $this->transformValue($wc_field, $this->overrides[$csv_col], $row);
                continue;
            }

            $value = $row[$csv_col] ?? null;
            if ($value === null || trim($value) === '') continue;

            if (!$this->isValueValid($wc_field, trim($value))) {
                $invalid_columns[] = $csv_col;
                continue;
            }

            $mapped[$wc_field] = $this->transformValue($wc_field, $value, $row);
        }

        if (!empty($invalid_columns)) {
            return ['result' => null, 'invalid_columns' => $invalid_columns];
        }

        // Fallback for Colibra JSON/CSV feeds:
        // if the mapping did not set post_title but the raw record has "name",
        // use that value so the whole product is not rejected for an empty title.
        if (empty($mapped['post_title']) && !empty($row['name'])) {
            $mapped['post_title'] = trim((string) $row['name']);
        }

        if ($import_mode === 'update') {
            $mapped = array_intersect_key($mapped, array_flip($this->update_fields));
        }

        return ['result' => $mapped, 'invalid_columns' => []];
    }

    public function getMatchBy(): string
    {
        return $this->match_by;
    }

    /**
     * Validates a value for a specific WC field.
     * Returns false if the value is invalid, the row is then skipped
     * unless the profile has an override for this column.
     */
    private function isValueValid(string $wc_field, string $value): bool
    {
        $v = trim($value);

        return match ($wc_field) {
            '_regular_price',
            '_sale_price'   => is_numeric(str_replace(',', '.', $v)) && (float) str_replace(',', '.', $v) >= 0,

            '_stock'        => ctype_digit($v) || (is_numeric($v) && (int) $v >= 0),

            '_weight'       => is_numeric(str_replace(',', '.', $v)) && (float) str_replace(',', '.', $v) >= 0,

            // active: accept 0, 1, true, false, yes, no (case-insensitive)
            'post_status'   => in_array(strtolower($v), ['0', '1', 'true', 'false', 'yes', 'no'], true),

            // VAT: number with optional % at the end
            'tax_class'     => is_numeric(rtrim($v, '%')),

            // JSON list of {name, value} pairs from FeedRowFlattener
            '_attributes'   => is_array(json_decode($v, true)),

            // Other fields: any value is accepted
            default         => true,
        };
    }

    // Value transformations for specific WC fields
    private function transformValue(string $wc_field, string $value, array $row): string
    {
        return match ($wc_field) {

            // Price: apply formula if set
            '_regular_price', '_sale_price' => $this->applyPriceFormula($value, $row),

            // Active 0/1 → publish/draft
            'post_status' => $this->mapActiveToStatus($value),

            // VAT: remove % sign if present
            'tax_class' => $this->mapVatToTaxClass(rtrim($value, '%')),

            // Stock: make sure it is an int
            '_stock' => (string) max(0, (int) $value),

            // Weight: replace comma with dot
            '_weight' => str_replace(',', '.', $value),

            // Images: add prefix if the URL is relative
            default => $this->isImageField($wc_field) ? $this->applyImagePrefix($value) : $value,
        };
    }

    private function applyPriceFormula(string $price_str, array $row): string
    {
        $price = (float) str_replace(',', '.', $price_str);

        if (empty($this->price_formula)) {
            return number_format($price, 4, '.', '');
        }

        $vat    = (float) rtrim($row['vat'] ?? '23', '%');
        $result = PriceFormula::evaluate($this->price_formula, $price, $vat);

        if (is_wp_error($result)) {
            // Invalid formula: fall back to the original price
            return number_format($price, 4, '.', '');
        }

        return number_format((float) $result, 4, '.', '');
    }

    private function mapActiveToStatus(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '0' || strtolower($trimmed) === 'false' || strtolower($trimmed) === 'no') {
            return 'draft';
        }
        return get_option('balmo_default_status', 'publish');
    }

    private function isImageField(string $wc_field): bool
    {
        return $wc_field === '_thumbnail' || preg_match('/^_gallery_image_\d+$/', $wc_field) === 1;
    }

    private function applyImagePrefix(string $value): string
    {
        $value = trim($value);
        if ($value === '' || $this->image_url_prefix === '') return $value;
        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://') || str_starts_with($value, '//')) {
            return $value;
        }
        return $this->image_url_prefix . '/' . ltrim($value, '/');
    }

    private function mapVatToTaxClass(string $vat): string
    {
        // WooCommerce tax classes: '' = standard, 'reduced-rate', 'zero-rate'
        // Mapping of simple numeric values
        $vat_num = (float) $vat;
        if ($vat_num === 0.0) return 'zero-rate';
        if ($vat_num <= 8.0) return 'reduced-rate';
        return ''; // standard (23%)
    }
}
