<?php

namespace Balmo\Service;

if (!defined('ABSPATH')) exit;

/**
 * Jedyne miejsce wyboru parsera pliku źródłowego.
 * Format pochodzi z profilu mapowania; bez profilu zawsze CSV.
 * Wynik ma kontrakt CsvFetcher: file_path, delimiter, source_type, source_url, columns, total_rows, preview.
 */
class FeedLoader
{
    public const FORMATS = ['csv', 'json', 'xml'];

    /**
     * Pobierz plik z URL i przygotuj CSV do importu.
     *
     * @return array|\WP_Error
     */
    public static function fromUrl(string $url, string $source_type, string $format)
    {
        switch (self::normalizeFormat($format)) {
            case 'json':
                return JsonFlattener::fromUrl($url, $source_type);
            case 'xml':
                return XmlFlattener::fromUrl($url, $source_type);
        }

        return CsvFetcher::fromUrl($url, $source_type);
    }

    /**
     * Przyjmij przesłany plik i przygotuj CSV do importu.
     *
     * @return array|\WP_Error
     */
    public static function fromUpload(array $file, string $source_type, string $format)
    {
        switch (self::normalizeFormat($format)) {
            case 'json':
                return JsonFlattener::fromUpload($file, $source_type);
            case 'xml':
                return XmlFlattener::fromUpload($file, $source_type);
        }

        return CsvFetcher::fromUpload($file, $source_type);
    }

    // Format pochodzi wyłącznie z wybranego profilu mapowania
    public static function formatForMapping(int $mapping_id): string
    {
        if (!$mapping_id) return 'csv';

        global $wpdb;
        $fmt = $wpdb->get_var($wpdb->prepare(
            "SELECT format FROM " . \Balmo\table_mappings() . " WHERE id = %d", $mapping_id
        ));

        return self::normalizeFormat((string) $fmt);
    }

    public static function normalizeFormat(string $format): string
    {
        return in_array($format, self::FORMATS, true) ? $format : 'csv';
    }
}
