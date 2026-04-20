<?php

namespace Balmo\Service;

if (!defined('ABSPATH')) exit;

/**
 * Mapuje wiersz CSV na tablicę pól WooCommerce.
 * Stosuje nadpisania wartości i formuły cenowe.
 */
class ColumnMapper
{
    private array  $column_map;      // ['csv_col' => 'wc_field']
    private array  $overrides;       // ['csv_col' => 'static_value'] lub ['wc_field' => 'static_value']
    private string $price_formula;
    private string $match_by;
    private array  $update_fields;   // pola do aktualizacji (tryb update)
    private string $image_url_prefix; // prefix URL dla zdjęć (np. https://drop.seltu.pl/)

    private const IMAGE_FIELDS = ['_thumbnail', '_gallery_image_2', '_gallery_image_3', '_gallery_image_4', '_gallery_image_5', '_gallery_image_6', '_gallery_image_7'];

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
            // Użyj auto-mapy z MappingPage
            $this->column_map       = \Balmo\Admin\MappingPage::AUTO_MAP;
            $this->overrides        = [];
            $this->price_formula    = '';
            $this->match_by         = 'sku';
            $this->update_fields    = ['_regular_price', '_stock', 'post_status'];
            $this->image_url_prefix = '';
        }
    }

    /**
     * Mapuj wiersz i zwróć powód pominięcia jeśli wiersz jest nieprawidłowy.
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
     * Waliduje wartość dla konkretnego pola WC.
     * Zwraca false jeśli wartość jest nieprawidłowa — wiersz zostanie pominięty
     * chyba że profil zawiera override dla tej kolumny.
     */
    private function isValueValid(string $wc_field, string $value): bool
    {
        $v = trim($value);

        return match ($wc_field) {
            '_regular_price',
            '_sale_price'   => is_numeric(str_replace(',', '.', $v)) && (float) str_replace(',', '.', $v) >= 0,

            '_stock'        => ctype_digit($v) || (is_numeric($v) && (int) $v >= 0),

            '_weight'       => is_numeric(str_replace(',', '.', $v)) && (float) str_replace(',', '.', $v) >= 0,

            // active: akceptuj 0, 1, true, false, yes, no (case-insensitive)
            'post_status'   => in_array(strtolower($v), ['0', '1', 'true', 'false', 'yes', 'no'], true),

            // VAT: liczba z opcjonalnym % na końcu
            'tax_class'     => is_numeric(rtrim($v, '%')),

            // Pozostałe pola — każda wartość jest akceptowalna
            default         => true,
        };
    }

    // Transformacje wartości dla konkretnych pól WC
    private function transformValue(string $wc_field, string $value, array $row): string
    {
        return match ($wc_field) {

            // Cena — zastosuj formułę jeśli ustawiona
            '_regular_price', '_sale_price' => $this->applyPriceFormula($value, $row),

            // Aktywność 0/1 → publish/draft
            'post_status' => $this->mapActiveToStatus($value),

            // VAT — usuń znak % jeśli jest
            'tax_class' => $this->mapVatToTaxClass(rtrim($value, '%')),

            // Stan magazynowy — upewnij się że int
            '_stock' => (string) max(0, (int) $value),

            // Waga — zamień przecinek na kropkę
            '_weight' => str_replace(',', '.', $value),

            // Zdjęcia — dodaj prefix jeśli URL jest relatywny
            '_thumbnail',
            '_gallery_image_2',
            '_gallery_image_3',
            '_gallery_image_4',
            '_gallery_image_5',
            '_gallery_image_6',
            '_gallery_image_7' => $this->applyImagePrefix($value),

            default => $value,
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
            // Formuła błędna — wróć do oryginalnej ceny
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
        // Mapowanie prostych wartości liczbowych
        $vat_num = (float) $vat;
        if ($vat_num === 0.0) return 'zero-rate';
        if ($vat_num <= 8.0) return 'reduced-rate';
        return ''; // standard (23%)
    }
}
