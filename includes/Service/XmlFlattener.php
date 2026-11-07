<?php

namespace Balmo\Service;

if (!defined('ABSPATH')) exit;

/**
 * Pobiera plik XML Colibra i konwertuje go do CSV gotowego do importu.
 * Czyta strumieniowo (XMLReader), po jednym elemencie <Product>, z dowolnego poziomu dokumentu.
 * Plik jest niezaufany: bez sieci (LIBXML_NONET), bez rozwijania encji, DOCTYPE jest odrzucany.
 * Spłaszczanie pojedynczego produktu robi FeedRowFlattener (wspólny z JsonFlattener).
 */
class XmlFlattener
{
    private const MAX_FILE_SIZE = 104857600; // 100 MB
    private const PREVIEW_ROWS  = 10;
    private const HEADER_SAMPLE = 50;

    /**
     * Pobierz XML z URL i zapisz jako CSV.
     *
     * @return array|\WP_Error
     */
    public static function fromUrl(string $url, string $source_type)
    {
        $xml_path = self::tempPath('xml');

        $response = wp_remote_get($url, [
            'timeout'   => 120,
            'sslverify' => true,
            'stream'    => true,
            'filename'  => $xml_path,
        ]);

        if (is_wp_error($response)) {
            @unlink($xml_path);
            return new \WP_Error('fetch_failed', sprintf(
                __('Błąd pobierania pliku XML: %s', 'balmo'),
                $response->get_error_message()
            ));
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            @unlink($xml_path);
            return new \WP_Error('http_error', sprintf(
                __('Serwer zwrócił kod HTTP %d.', 'balmo'),
                $code
            ));
        }

        if (filesize($xml_path) > self::MAX_FILE_SIZE) {
            @unlink($xml_path);
            return new \WP_Error('file_too_large', __('Plik XML jest za duży (max 100 MB).', 'balmo'));
        }

        $csv_path = self::tempPath('csv');
        $result   = self::convertToCsv($xml_path, $csv_path, $source_type, $url);
        @unlink($xml_path);

        return $result;
    }

    /**
     * Prześlij XML i zapisz jako CSV.
     *
     * @return array|\WP_Error
     */
    public static function fromUpload(array $file, string $source_type)
    {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return new \WP_Error('upload_error', __('Błąd przesyłania pliku.', 'balmo'));
        }

        if ($file['size'] > self::MAX_FILE_SIZE) {
            return new \WP_Error('file_too_large', __('Plik XML jest za duży (max 100 MB).', 'balmo'));
        }

        $xml_path = self::tempPath('xml');
        if (!move_uploaded_file($file['tmp_name'], $xml_path)) {
            return new \WP_Error('move_failed', __('Nie można zapisać pliku.', 'balmo'));
        }

        $csv_path = self::tempPath('csv');
        $result   = self::convertToCsv($xml_path, $csv_path, $source_type, '');
        @unlink($xml_path);

        return $result;
    }

    /**
     * Konwertuj XML → CSV, zwróć dane kompatybilne z CsvFetcher.
     *
     * @return array|\WP_Error
     */
    public static function convertToCsv(string $xml_path, string $csv_path, string $source_type, string $source_url)
    {
        // Przebieg 1: nagłówki z próbki pierwszych produktów
        $headers = [];
        $seen    = 0;
        $error   = self::eachProduct($xml_path, function (array $product) use (&$headers, &$seen) {
            foreach (array_keys(FeedRowFlattener::flatten($product)) as $key) {
                if (!in_array($key, $headers, true)) {
                    $headers[] = $key;
                }
            }
            return ++$seen < self::HEADER_SAMPLE;
        }, true);

        if ($error !== null) {
            return $error;
        }
        $headers = FeedRowFlattener::completeImageHeaders($headers);

        $handle = fopen($csv_path, 'w');
        if (!$handle) {
            return new \WP_Error('csv_write', __('Nie można zapisać pliku tymczasowego.', 'balmo'));
        }

        fwrite($handle, "\xEF\xBB\xBF"); // BOM UTF-8
        fputcsv($handle, $headers);

        // Przebieg 2: zapis wierszy
        $preview        = [];
        $total_rows     = 0;
        $overflow_count = 0;
        $overflow_first = null;
        $error          = self::eachProduct($xml_path, function (array $product) use ($handle, $headers, &$preview, &$total_rows, &$overflow_count, &$overflow_first) {
            $dropped = 0;
            $flat    = FeedRowFlattener::flatten($product, $dropped);
            if ($dropped > 0) {
                $overflow_count++;
                $overflow_first ??= (string) ($product['product_code'] ?? $product['product_id'] ?? '');
            }
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
            return true;
        }, false);

        fclose($handle);

        if ($error !== null) {
            @unlink($csv_path);
            return $error;
        }
        FeedRowFlattener::recordOverflow($csv_path, $overflow_count, (string) $overflow_first);

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

    /**
     * Przejdź po elementach <Product> i wywołaj $callback(array $product).
     * Callback zwraca false, żeby przerwać (próbka nagłówków). $sample = true pomija kontrolę błędu parsowania po przerwaniu.
     *
     * @return \WP_Error|null
     */
    private static function eachProduct(string $xml_path, callable $callback, bool $sample)
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $reader = new \XMLReader();
        if (!$reader->open($xml_path, null, LIBXML_NONET | LIBXML_COMPACT)) {
            libxml_use_internal_errors($previous);
            return new \WP_Error('xml_read', __('Nie można odczytać pliku XML.', 'balmo'));
        }

        $found   = 0;
        $stopped = false;
        $error   = null;

        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::DOC_TYPE) {
                $error = new \WP_Error('xml_doctype', __('Plik XML zawiera DOCTYPE, który jest niedozwolony.', 'balmo'));
                break;
            }

            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'Product') {
                continue;
            }

            $node = simplexml_load_string($reader->readOuterXml(), 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
            if ($node === false) {
                $error = new \WP_Error('xml_parse', __('Błąd parsowania pliku XML.', 'balmo'));
                break;
            }

            $found++;
            if ($callback(self::nodeToArray($node)) === false) {
                $stopped = true;
                break;
            }
            $reader->next(); // pomiń poddrzewo bieżącego <Product>
        }

        $reader->close();

        if ($error === null && !($stopped && $sample)) {
            foreach (libxml_get_errors() as $e) {
                if ($e->level >= LIBXML_ERR_ERROR) {
                    $error = new \WP_Error('xml_parse', sprintf(
                        __('Błąd parsowania pliku XML: %s', 'balmo'),
                        trim($e->message)
                    ));
                    break;
                }
            }
        }

        if ($error === null && $found === 0) {
            $error = new \WP_Error('xml_empty', __('Plik XML nie zawiera produktów (<Product>).', 'balmo'));
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $error;
    }

    // Element <Product> → tablica w kształcie zgodnym z FeedRowFlattener
    private static function nodeToArray(\SimpleXMLElement $node): array
    {
        $product = [];

        foreach ($node->children() as $name => $child) {
            $name = (string) $name;

            if ($name === 'additional_images') {
                $images = [];
                foreach ($child->image as $img) {
                    $url = trim((string) $img);
                    if ($url !== '') {
                        $images[] = $url;
                    }
                }
                $product['additional_images'] = ['image' => $images];
                continue;
            }

            if ($name === 'attributes') {
                $attrs = [];
                foreach ($child->attribute as $attr) {
                    $attrs[] = ['name' => (string) $attr->name, 'value' => (string) $attr->value];
                }
                $product['attributes'] = $attrs;
                continue;
            }

            if ($child->count() > 0) continue; // inne zagnieżdżone struktury — pomiń, jak w JSON

            $value = (string) $child;

            // ICA XML: produkt nieaktywny ma pusty <active></active>
            if ($name === 'active' && trim($value) === '') {
                $value = '0';
            }

            $product[$name] = $value;
        }

        return $product;
    }

    private static function tempPath(string $ext): string
    {
        $upload_dir = wp_upload_dir();
        $dir        = $upload_dir['basedir'] . '/balmo';
        wp_mkdir_p($dir);
        return $dir . '/import_' . uniqid('', true) . '.' . $ext;
    }
}
