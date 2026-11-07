<?php

namespace Balmo\Service;

if (!defined('ABSPATH')) exit;

/**
 * Spłaszcza jeden produkt (tablica z JSON lub XML) do płaskiego wiersza CSV.
 * Wspólny dla JsonFlattener i XmlFlattener, żeby oba formaty dawały te same kolumny.
 * Zdjęcia: "images[]" (uproszczony JSON) lub "main_image" + "additional_images.image[]".
 * Atrybuty (atributes / attributes) są pomijane — nie obsługiwane w tej wersji.
 */
class FeedRowFlattener
{
    public const MAX_ADDITIONAL = 6;  // additional_image_1 … additional_image_6

    public static function flatten(array $product): array
    {
        $flat = [];

        foreach ($product as $key => $value) {
            // "images": ["url1", "url2"] — format uproszczony (np. drop.seltu.pl)
            if ($key === 'images' && is_array($value)) {
                if (isset($value[0])) {
                    $flat['main_image'] = (string) $value[0];
                }
                for ($i = 1; $i <= self::MAX_ADDITIONAL && isset($value[$i]); $i++) {
                    $flat['additional_image_' . $i] = (string) $value[$i];
                }
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
                    if ($i >= self::MAX_ADDITIONAL) break;
                    $flat['additional_image_' . ($i + 1)] = (string) $img;
                }
                continue;
            }

            // Atrybuty — pomijamy
            if (in_array($key, ['atributes', 'attributes'], true)) {
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
}
