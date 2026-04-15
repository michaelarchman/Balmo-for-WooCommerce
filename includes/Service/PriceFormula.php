<?php

namespace Balmo\Service;

if (!defined('ABSPATH')) exit;

/**
 * Bezpieczny ewaluator formuł cenowych.
 * NIE używa eval(). Parsuje wyrażenie do tokenów i oblicza je ręcznie.
 *
 * Obsługiwane elementy:
 *   - Zmienne:  {price}, {vat}
 *   - Operatory: + - * / ( )
 *   - Funkcje:   round(x), round(x,d), ceil(x), floor(x), abs(x), min(x,y), max(x,y)
 *   - Liczby:    całkowite i zmiennoprzecinkowe
 */
class PriceFormula
{
    // Dostępne funkcje i ich PHP-odpowiedniki
    private const FUNCTIONS = ['round', 'ceil', 'floor', 'abs', 'min', 'max'];

    public static function evaluate(string $formula, float $price, float $vat = 23.0): float|\WP_Error
    {
        if (empty(trim($formula))) {
            return $price;
        }

        // Podstaw zmienne
        $expr = str_replace(['{price}', '{vat}'], [$price, $vat], $formula);

        // Zamień przecinki w zapisie liczb na kropki (ale nie przecinki w argumentach funkcji)
        // Bezpieczne: tokenizuj
        try {
            $tokens = self::tokenize($expr);
            $result = self::parseExpression($tokens);
        } catch (\Throwable $e) {
            return new \WP_Error('formula_error', sprintf(
                __('Błąd w formule: %s', 'balmo'),
                $e->getMessage()
            ));
        }

        if (!is_finite($result)) {
            return new \WP_Error('formula_error', __('Formuła zwróciła nieskończoność lub NaN.', 'balmo'));
        }

        return round($result, 10); // zachowaj precyzję, WC sam zaokrągli
    }

    // Tokenizacja wyrażenia
    private static function tokenize(string $expr): array
    {
        $tokens = [];
        $i      = 0;
        $len    = strlen($expr);

        while ($i < $len) {
            $ch = $expr[$i];

            // Białe znaki — pomiń
            if (ctype_space($ch)) {
                $i++;
                continue;
            }

            // Liczba (z opcjonalnym minusem jako unary)
            if (ctype_digit($ch) || $ch === '.') {
                $num = '';
                while ($i < $len && (ctype_digit($expr[$i]) || $expr[$i] === '.')) {
                    $num .= $expr[$i++];
                }
                $tokens[] = ['type' => 'number', 'value' => (float) $num];
                continue;
            }

            // Identyfikator (funkcja lub zmienna)
            if (ctype_alpha($ch) || $ch === '_') {
                $name = '';
                while ($i < $len && (ctype_alnum($expr[$i]) || $expr[$i] === '_')) {
                    $name .= $expr[$i++];
                }
                if (in_array($name, self::FUNCTIONS, true)) {
                    $tokens[] = ['type' => 'function', 'value' => $name];
                } else {
                    throw new \InvalidArgumentException("Nieznany identyfikator: {$name}");
                }
                continue;
            }

            // Operatory i nawiasy
            if (in_array($ch, ['+', '-', '*', '/', '(', ')', ','], true)) {
                $tokens[] = ['type' => 'op', 'value' => $ch];
                $i++;
                continue;
            }

            throw new \InvalidArgumentException("Nieznany znak: {$ch}");
        }

        return $tokens;
    }

    // Parser wyrażenia — Shunting-Yard + obliczenia
    private static function parseExpression(array $tokens): float
    {
        $output   = []; // stos wartości
        $ops      = []; // stos operatorów
        $precedence = ['+' => 1, '-' => 1, '*' => 2, '/' => 2];

        $apply = function (string $op) use (&$output): void {
            if (count($output) < 2) {
                throw new \InvalidArgumentException("Za mało argumentów dla operatora {$op}");
            }
            $b = array_pop($output);
            $a = array_pop($output);
            $output[] = match ($op) {
                '+'     => $a + $b,
                '-'     => $a - $b,
                '*'     => $a * $b,
                '/'     => $b == 0 ? throw new \InvalidArgumentException('Dzielenie przez zero') : $a / $b,
                default => throw new \InvalidArgumentException("Nieznany operator: {$op}"),
            };
        };

        $i   = 0;
        $cnt = count($tokens);

        // Obsługa unary minus: poprzedza liczbę po operatorze lub na początku
        $prev_type = null;

        while ($i < $cnt) {
            $tok = $tokens[$i];

            if ($tok['type'] === 'number') {
                $output[] = $tok['value'];
                $prev_type = 'number';
                $i++;

            } elseif ($tok['type'] === 'function') {
                $ops[]     = $tok;
                $prev_type = 'function';
                $i++;

            } elseif ($tok['type'] === 'op') {
                $ch = $tok['value'];

                // Unary minus
                if ($ch === '-' && ($prev_type === null || $prev_type === 'op' && in_array($tokens[$i-1]['value'], ['(', ','], true) || $prev_type === 'op')) {
                    // Kolejny token powinien być liczbą
                    if (isset($tokens[$i+1]) && $tokens[$i+1]['type'] === 'number') {
                        $output[] = -$tokens[$i+1]['value'];
                        $i += 2;
                        $prev_type = 'number';
                        continue;
                    }
                }

                if ($ch === '(') {
                    $ops[]     = $tok;
                    $prev_type = 'op';
                    $i++;

                } elseif ($ch === ')') {
                    while (!empty($ops) && end($ops)['value'] !== '(') {
                        $apply(array_pop($ops)['value']);
                    }
                    if (empty($ops)) {
                        throw new \InvalidArgumentException('Niezrównoważone nawiasy.');
                    }
                    array_pop($ops); // usuń '('
                    // Jeśli na szczycie stosu jest funkcja — wywołaj ją
                    if (!empty($ops) && end($ops)['type'] === 'function') {
                        $fn   = array_pop($ops)['value'];
                        $args = self::popFunctionArgs($output, $fn);
                        $output[] = self::callFunction($fn, $args);
                    }
                    $prev_type = 'number';
                    $i++;

                } elseif ($ch === ',') {
                    while (!empty($ops) && end($ops)['value'] !== '(') {
                        $apply(array_pop($ops)['value']);
                    }
                    $prev_type = 'op';
                    $i++;

                } else {
                    // Binarny operator
                    $prec = $precedence[$ch] ?? 0;
                    while (!empty($ops)
                        && end($ops)['type'] === 'op'
                        && isset($precedence[end($ops)['value']])
                        && $precedence[end($ops)['value']] >= $prec
                    ) {
                        $apply(array_pop($ops)['value']);
                    }
                    $ops[]     = $tok;
                    $prev_type = 'op';
                    $i++;
                }
            } else {
                $i++;
            }
        }

        while (!empty($ops)) {
            $op = array_pop($ops);
            if ($op['value'] === '(' || $op['value'] === ')') {
                throw new \InvalidArgumentException('Niezrównoważone nawiasy.');
            }
            $apply($op['value']);
        }

        if (count($output) !== 1) {
            throw new \InvalidArgumentException('Nieprawidłowe wyrażenie.');
        }

        return (float) $output[0];
    }

    private static function popFunctionArgs(array &$stack, string $fn): array
    {
        // Funkcje 1-argumentowe: ceil, floor, round (bez d), abs
        // Funkcje 2-argumentowe: round(x,d), min(x,y), max(x,y)
        $two_arg = ['min', 'max'];
        $opt_two = ['round']; // round może mieć 1 lub 2 argumenty

        if (in_array($fn, $two_arg, true)) {
            if (count($stack) < 2) throw new \InvalidArgumentException("Funkcja {$fn} wymaga 2 argumentów.");
            $b = array_pop($stack);
            $a = array_pop($stack);
            return [$a, $b];
        }

        if (in_array($fn, $opt_two, true) && count($stack) >= 2) {
            // Sprawdź czy drugi argument jest całkowity (miejsca dziesiętne)
            $b = end($stack);
            if (is_float($b) && $b == (int) $b && $b >= 0 && $b <= 10) {
                array_pop($stack);
                $a = array_pop($stack);
                return [$a, (int) $b];
            }
        }

        if (empty($stack)) throw new \InvalidArgumentException("Funkcja {$fn} wymaga argumentów.");
        return [array_pop($stack)];
    }

    private static function callFunction(string $fn, array $args): float
    {
        return match ($fn) {
            'round' => round($args[0], $args[1] ?? 2),
            'ceil'  => ceil($args[0]),
            'floor' => floor($args[0]),
            'abs'   => abs($args[0]),
            'min'   => min($args[0], $args[1]),
            'max'   => max($args[0], $args[1]),
            default => throw new \InvalidArgumentException("Nieznana funkcja: {$fn}"),
        };
    }
}
