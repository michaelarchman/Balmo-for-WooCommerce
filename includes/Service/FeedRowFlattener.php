<?php

namespace Balmo\Service;

if (!defined('ABSPATH')) exit;

/**
 * Spłaszcza jeden produkt (tablica z JSON lub XML) do płaskiego wiersza CSV.
 * Wspólny dla JsonFlattener i XmlFlattener, żeby oba formaty dawały te same kolumny.
 * Zdjęcia: "images[]" (uproszczony JSON) lub "main_image" + "additional_images.image[]".
 * Atrybuty (JSON "atributes", XML "attributes") trafiają do jednej kolumny "attributes" jako JSON [{name, value}, ...].
 */
class FeedRowFlattener
{
    /**
     * @param int $dropped Wyjście: liczba zdjęć dodatkowych ponad BALMO_MAX_GALLERY_IMAGES.
     */
    public static function flatten(array $product, int &$dropped = 0): array
    {
        $dropped = 0;
        $flat = [];

        foreach ($product as $key => $value) {
            // "images": ["url1", "url2"] — format uproszczony (np. drop.seltu.pl)
            if ($key === 'images' && is_array($value)) {
                if (isset($value[0])) {
                    $flat['main_image'] = (string) $value[0];
                }
                for ($i = 1; $i <= BALMO_MAX_GALLERY_IMAGES && isset($value[$i]); $i++) {
                    $flat['additional_image_' . $i] = (string) $value[$i];
                }
                $dropped += max(0, count($value) - 1 - BALMO_MAX_GALLERY_IMAGES);
                continue;
            }

            // "additional_images": {"image": [...]} lub {"image": "url"}
            if ($key === 'additional_images' && is_array($value)) {
                $imgs = [];
                if (isset($value['image'])) {
                    $imgs = is_array($value['image']) ? $value['image'] : [$value['image']];
                } elseif (isset($value[0])) {
                    $imgs = $value;
                }
                foreach ($imgs as $i => $img) {
                    if ($i >= BALMO_MAX_GALLERY_IMAGES) break;
                    $flat['additional_image_' . ($i + 1)] = (string) $img;
                }
                $dropped += max(0, count($imgs) - BALMO_MAX_GALLERY_IMAGES);
                continue;
            }

            // Product attributes: JSON "atributes" and XML "attributes" share one "attributes" column
            if (in_array($key, ['atributes', 'attributes'], true)) {
                if (is_array($value)) {
                    $flat['attributes'] = self::encodeAttributes($value, $flat['attributes'] ?? '');
                }
                continue;
            }

            // Booleany: true → "1", false → "0"
            if (is_bool($value)) {
                $flat[$key] = $value ? '1' : '0';
                continue;
            }

            // Skalary
            if (!is_array($value)) {
                $flat[$key] = (string) $value;
                continue;
            }

            // Inne zagnieżdżone struktury — pomiń
        }

        return $flat;
    }

    /**
     * Nagłówki zdjęć wyznaczane z próbki produktów mogą pominąć kolumny z dalszych produktów.
     * Gdy feed ma zdjęcia, uzupełnij wszystkie additional_image_1 … additional_image_N.
     */
    public static function completeImageHeaders(array $headers): array
    {
        $has_images = false;
        foreach ($headers as $header) {
            if ($header === 'main_image' || str_starts_with((string) $header, 'additional_image_')) {
                $has_images = true;
                break;
            }
        }
        if (!$has_images) {
            return $headers;
        }

        for ($n = 1; $n <= BALMO_MAX_GALLERY_IMAGES; $n++) {
            $name = 'additional_image_' . $n;
            if (!in_array($name, $headers, true)) {
                $headers[] = $name;
            }
        }
        return $headers;
    }

    // The CSV knows nothing about the import, so the warning waits in a transient until ProductImporter takes it.
    public static function recordOverflow(string $csv_path, int $products, string $first_id): void
    {
        if ($products < 1) {
            return;
        }
        set_transient(self::warningKey($csv_path), sprintf(
            __('Produkty z liczbą zdjęć dodatkowych ponad limit %1$d: %2$d (pierwszy: %3$s). Nadmiarowe zdjęcia pominięto.', 'balmo'),
            BALMO_MAX_GALLERY_IMAGES,
            $products,
            $first_id !== '' ? $first_id : '—'
        ), DAY_IN_SECONDS);
    }

    public static function takeWarning(string $csv_path): ?string
    {
        $key     = self::warningKey($csv_path);
        $warning = get_transient($key);
        if (!is_string($warning)) {
            return null;
        }
        delete_transient($key);
        return $warning;
    }

    private static function warningKey(string $csv_path): string
    {
        return 'balmo_feed_warning_' . md5($csv_path);
    }

    // Keep only pairs with non-empty name and value; values are trimmed, never split on commas
    private static function encodeAttributes(array $items, string $previous): string
    {
        $pairs = $previous !== '' ? (json_decode($previous, true) ?: []) : [];

        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['name'], $item['value'])) continue;
            if (!is_scalar($item['name']) || !is_scalar($item['value'])) continue;

            $name  = trim((string) $item['name']);
            $value = trim((string) $item['value']);
            if ($name === '' || $value === '') continue;

            $pairs[] = ['name' => $name, 'value' => $value];
        }

        return wp_json_encode($pairs, JSON_UNESCAPED_UNICODE);
    }
}
