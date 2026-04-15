<?php

namespace Balmo\Service;

if (!defined('ABSPATH')) exit;

class CsvFetcher
{
    private const MAX_FILE_SIZE = 52428800; // 50 MB
    private const PREVIEW_ROWS  = 10;

    // Pobierz CSV z URL
    public static function fromUrl(string $url, string $source_type): array|\WP_Error
    {
        if (empty($url)) {
            return new \WP_Error('empty_url', __('Podaj URL pliku CSV.', 'balmo'));
        }

        $response = wp_remote_get($url, [
            'timeout'   => 120,
            'sslverify' => true,
            'stream'    => true,
            'filename'  => self::tempFilePath(),
        ]);

        if (is_wp_error($response)) {
            return new \WP_Error('fetch_failed', sprintf(
                __('Błąd pobierania pliku: %s', 'balmo'),
                $response->get_error_message()
            ));
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new \WP_Error('http_error', sprintf(
                __('Serwer zwrócił kod HTTP %d.', 'balmo'),
                $code
            ));
        }

        $file_path = $response['filename'];
        return self::processFile($file_path, $source_type, $url);
    }

    // Pobierz CSV z uploadu
    public static function fromUpload(array $file, string $source_type): array|\WP_Error
    {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return new \WP_Error('upload_error', __('Błąd przesyłania pliku.', 'balmo'));
        }

        if ($file['size'] > self::MAX_FILE_SIZE) {
            return new \WP_Error('file_too_large', sprintf(
                __('Plik jest za duży (max %d MB).', 'balmo'),
                self::MAX_FILE_SIZE / 1024 / 1024
            ));
        }

        $dest = self::tempFilePath();
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            return new \WP_Error('move_failed', __('Nie można zapisać pliku.', 'balmo'));
        }

        return self::processFile($dest, $source_type, '');
    }

    // Przetworz plik — wykryj separator, zlicz wiersze, zrób podgląd
    private static function processFile(string $file_path, string $source_type, string $source_url): array|\WP_Error
    {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return new \WP_Error('file_not_found', __('Nie można odczytać pliku.', 'balmo'));
        }

        $handle = fopen($file_path, 'r');
        if (!$handle) {
            return new \WP_Error('file_open_error', __('Nie można otworzyć pliku.', 'balmo'));
        }

        // Usuń BOM (UTF-8)
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        // Wykryj separator z pierwszego wiersza
        $first_line = fgets($handle);
        if ($first_line === false) {
            fclose($handle);
            return new \WP_Error('empty_file', __('Plik jest pusty.', 'balmo'));
        }

        $delimiter = self::detectDelimiter($first_line, $source_type);
        rewind($handle);
        if ($bom === "\xEF\xBB\xBF") fread($handle, 3); // skip BOM again

        // Wczytaj nagłówki
        $headers = fgetcsv($handle, 0, $delimiter);
        if (!$headers) {
            fclose($handle);
            return new \WP_Error('no_headers', __('Nie można odczytać nagłówków CSV.', 'balmo'));
        }
        $headers = array_map('trim', $headers);

        // Podgląd pierwszych N wierszy + zlicz wszystkie
        $preview     = [];
        $total_rows  = 0;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $total_rows++;
            if (count($preview) < self::PREVIEW_ROWS) {
                $preview[] = self::combineRow($headers, $row);
            }
        }

        fclose($handle);

        return [
            'file_path'   => $file_path,
            'delimiter'   => $delimiter,
            'source_type' => $source_type,
            'source_url'  => $source_url,
            'columns'     => $headers,
            'total_rows'  => $total_rows,
            'preview'     => $preview,
        ];
    }

    private static function detectDelimiter(string $line, string $source_type): string
    {
        // ICA zawsze używa średnika
        if ($source_type === 'ica') return ';';

        // Wykryj automatycznie
        $semicolons = substr_count($line, ';');
        $commas     = substr_count($line, ',');

        return $semicolons > $commas ? ';' : ',';
    }

    private static function tempFilePath(): string
    {
        $upload_dir = wp_upload_dir();
        $dir        = $upload_dir['basedir'] . '/balmo';
        wp_mkdir_p($dir);
        return $dir . '/import_' . uniqid('', true) . '.csv';
    }

    private static function combineRow(array $headers, array $row): array
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
