<?php

namespace Balmo\Service;

if (!defined('ABSPATH')) exit;

/**
 * Pobiera plik JSON Colibra i konwertuje go do CSV gotowego do importu.
 * Obsługuje struktury: [{...}] lub {"products":[{...}]}
 * Spłaszczanie pojedynczego produktu robi FeedRowFlattener (wspólny z XmlFlattener).
 */
class JsonFlattener
{
    private const MAX_FILE_SIZE  = 104857600; // 100 MB
    private const PREVIEW_ROWS   = 10;

    // Pobierz JSON z URL i zapisz jako CSV
    public static function fromUrl(string $url, string $source_type): array|\WP_Error
    {
        $json_path = self::tempPath('json');

        $response = wp_remote_get($url, [
            'timeout'   => 120,
            'sslverify' => true,
            'stream'    => true,
            'filename'  => $json_path,
        ]);

        if (is_wp_error($response)) {
            @unlink($json_path);
            return new \WP_Error('fetch_failed', sprintf(
                __('Błąd pobierania pliku JSON: %s', 'balmo'),
                $response->get_error_message()
            ));
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            @unlink($json_path);
            return new \WP_Error('http_error', sprintf(
                __('Serwer zwrócił kod HTTP %d.', 'balmo'),
                $code
            ));
        }

        if (filesize($json_path) > self::MAX_FILE_SIZE) {
            @unlink($json_path);
            return new \WP_Error('file_too_large', __('Plik JSON jest za duży (max 100 MB).', 'balmo'));
        }

        $csv_path = self::tempPath('csv');
        $result   = self::convertToCsv($json_path, $csv_path, $source_type, $url);
        @unlink($json_path);

        return $result;
    }

    // Prześlij JSON i zapisz jako CSV
    public static function fromUpload(array $file, string $source_type): array|\WP_Error
    {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return new \WP_Error('upload_error', __('Błąd przesyłania pliku.', 'balmo'));
        }

        if ($file['size'] > self::MAX_FILE_SIZE) {
            return new \WP_Error('file_too_large', __('Plik JSON jest za duży (max 100 MB).', 'balmo'));
        }

        $json_path = self::tempPath('json');
        if (!move_uploaded_file($file['tmp_name'], $json_path)) {
            return new \WP_Error('move_failed', __('Nie można zapisać pliku.', 'balmo'));
        }

        $csv_path = self::tempPath('csv');
        $result   = self::convertToCsv($json_path, $csv_path, $source_type, '');
        @unlink($json_path);

        return $result;
    }

    // Konwertuj JSON → CSV, zwróć dane kompatybilne z CsvFetcher
    public static function convertToCsv(string $json_path, string $csv_path, string $source_type, string $source_url): array|\WP_Error
    {
        $raw = @file_get_contents($json_path);
        if ($raw === false) {
            return new \WP_Error('json_read', __('Nie można odczytać pliku JSON.', 'balmo'));
        }

        $data = json_decode($raw, true);
        unset($raw);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new \WP_Error('json_parse', sprintf(
                __('Błąd parsowania JSON: %s', 'balmo'),
                json_last_error_msg()
            ));
        }

        $products = self::extractProducts($data);
        unset($data);

        if (empty($products)) {
            return new \WP_Error('json_empty', __('Plik JSON nie zawiera produktów.', 'balmo'));
        }

        // Zbierz nagłówki (na próbce pierwszych 50 produktów)
        $headers = self::collectHeaders(array_slice($products, 0, 50));

        $handle = fopen($csv_path, 'w');
        if (!$handle) {
            return new \WP_Error('csv_write', __('Nie można zapisać pliku tymczasowego.', 'balmo'));
        }

        fwrite($handle, "\xEF\xBB\xBF"); // BOM UTF-8
        fputcsv($handle, $headers);

        $preview    = [];
        $total_rows = 0;

        foreach ($products as $product) {
            $flat = FeedRowFlattener::flatten($product);
            $row  = [];
            foreach ($headers as $h) {
                $row[] = $flat[$h] ?? '';
            }
            fputcsv($handle, $row);
            $total_rows++;

            if ($total_rows <= self::PREVIEW_ROWS) {
                $assoc = [];
                foreach ($headers as $h) {
                    $assoc[$h] = $flat[$h] ?? '';
                }
                $preview[] = $assoc;
            }
        }

        fclose($handle);

        return [
            'file_path'   => $csv_path,
            'delimiter'   => ',',
            'source_type' => $source_type,
            'source_url'  => $source_url,
            'columns'     => $headers,
            'total_rows'  => $total_rows,
            'preview'     => $preview,
        ];
    }

    // Wyciągnij tablicę produktów z różnych struktur JSON Colibra
    private static function extractProducts(array $data): array
    {
        // Bezpośrednia tablica [{...}, ...]
        if (isset($data[0]) && is_array($data[0])) {
            return $data;
        }

        // Obiekt z kluczem products/product/items/data
        foreach (['products', 'product', 'items', 'data'] as $key) {
            if (!isset($data[$key]) || !is_array($data[$key])) continue;
            $val = $data[$key];
            if (!isset($val[0])) return [$val]; // pojedynczy produkt
            return $val;
        }

        // Pojedynczy produkt jako root
        if (isset($data['product_id']) || isset($data['product_code'])) {
            return [$data];
        }

        // Obiekt z ID jako kluczami: {"425": {...}, "426": {...}} — format Colibra JSON
        $first = reset($data);
        if (is_array($first) && (isset($first['product_id']) || isset($first['product_code']) || isset($first['name']))) {
            return array_values($data);
        }

        return [];
    }

    // Zbierz unikalne klucze z próbki produktów
    private static function collectHeaders(array $products): array
    {
        $headers = [];
        foreach ($products as $product) {
            foreach (array_keys(FeedRowFlattener::flatten($product)) as $key) {
                if (!in_array($key, $headers, true)) {
                    $headers[] = $key;
                }
            }
        }
        return $headers;
    }

    private static function tempPath(string $ext): string
    {
        $upload_dir = wp_upload_dir();
        $dir        = $upload_dir['basedir'] . '/balmo';
        wp_mkdir_p($dir);
        return $dir . '/import_' . uniqid('', true) . '.' . $ext;
    }
}
