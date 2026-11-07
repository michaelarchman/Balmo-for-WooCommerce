<?php

namespace Balmo\Service;

if (!defined('ABSPATH')) exit;

/**
 * The only place that selects the source file parser.
 * The format comes from the mapping profile; without a profile it is always CSV.
 * The result follows the CsvFetcher contract: file_path, delimiter, source_type, source_url, columns, total_rows, preview.
 */
class FeedLoader
{
    public const FORMATS = ['csv', 'json', 'xml'];

    /**
     * Fetch a file from URL and prepare CSV for import.
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
     * Accept an uploaded file and prepare CSV for import.
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

    // Format comes only from the selected mapping profile
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
