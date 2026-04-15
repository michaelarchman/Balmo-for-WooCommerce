<?php

namespace Balmo\Service;

if (!defined('ABSPATH')) exit;

class CsvValidator
{
    // Wymagane kolumny dla każdego typu pliku
    private const REQUIRED = [
        'all' => ['name', 'price'],
        'ica' => ['price', 'stock', 'active'],
    ];

    // Kolumny zalecane (ostrzeżenie jeśli brak)
    private const RECOMMENDED = [
        'all' => ['product_code', 'active', 'stock', 'vat', 'category'],
        'ica' => ['product_id', 'product_code'],
    ];

    // Oczekiwane typy danych dla znanych kolumn
    private const TYPE_CHECKS = [
        'price'      => 'float',
        'stock'      => 'int',
        'active'     => 'bool01',
        'weight'     => 'float',
        'product_id' => 'int',
        'vat'        => 'vat',
    ];

    public static function validate(string $file_path, string $delimiter, string $source_type): array
    {
        $errors   = [];
        $warnings = [];
        $info     = [];

        if (!file_exists($file_path)) {
            return ['errors' => [__('Plik nie istnieje.', 'balmo')], 'warnings' => [], 'info' => []];
        }

        $handle = fopen($file_path, 'r');
        if (!$handle) {
            return ['errors' => [__('Nie można otworzyć pliku.', 'balmo')], 'warnings' => [], 'info' => []];
        }

        // Pomiń BOM
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($handle);

        $headers = fgetcsv($handle, 0, $delimiter);
        if (!$headers) {
            fclose($handle);
            return ['errors' => [__('Brak nagłówków CSV.', 'balmo')], 'warnings' => [], 'info' => []];
        }
        $headers = array_map('trim', $headers);

        // Sprawdź wymagane kolumny
        $required = self::REQUIRED[$source_type] ?? self::REQUIRED['all'];
        foreach ($required as $col) {
            if (!in_array($col, $headers, true)) {
                $errors[] = sprintf(__('Brak wymaganej kolumny: %s', 'balmo'), $col);
            }
        }

        // Sprawdź zalecane kolumny
        $recommended = self::RECOMMENDED[$source_type] ?? [];
        foreach ($recommended as $col) {
            if (!in_array($col, $headers, true)) {
                $warnings[] = sprintf(__('Brak zalecanej kolumny: %s', 'balmo'), $col);
            }
        }

        // Wykryj duplikaty nagłówków
        $duplicates = array_keys(array_filter(array_count_values($headers), fn($c) => $c > 1));
        foreach ($duplicates as $dup) {
            $errors[] = sprintf(__('Zduplikowana kolumna: %s', 'balmo'), $dup);
        }

        // Sprawdź typy danych na próbce (max 100 wierszy)
        $row_num        = 0;
        $type_errors    = [];
        $col_index      = array_flip($headers);

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false && $row_num < 100) {
            $row_num++;
            $row = array_pad($row, count($headers), '');

            foreach (self::TYPE_CHECKS as $col => $type) {
                if (!isset($col_index[$col])) continue;
                $val = trim($row[$col_index[$col]] ?? '');
                if ($val === '') continue; // puste pola są OK

                if (!self::checkType($val, $type)) {
                    $type_errors[$col][] = $row_num;
                }
            }
        }

        fclose($handle);

        foreach ($type_errors as $col => $rows) {
            $sample = array_slice($rows, 0, 5);
            $warnings[] = sprintf(
                __('Kolumna "%s" zawiera nieprawidłowe wartości w wierszach: %s%s', 'balmo'),
                $col,
                implode(', ', $sample),
                count($rows) > 5 ? '...' : ''
            );
        }

        // Info
        $info[] = sprintf(__('%d kolumn wykrytych: %s', 'balmo'), count($headers), implode(', ', $headers));

        return [
            'errors'   => $errors,
            'warnings' => $warnings,
            'info'     => $info,
        ];
    }

    private static function checkType(string $value, string $type): bool
    {
        return match ($type) {
            'float'  => is_numeric(str_replace(',', '.', $value)),
            'int'    => ctype_digit($value),
            'bool01' => in_array($value, ['0', '1'], true),
            'vat'    => is_numeric(rtrim($value, '%')),
            default  => true,
        };
    }
}
