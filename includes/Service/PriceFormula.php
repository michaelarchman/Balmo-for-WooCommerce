<?php

namespace Balmo\Service;

if (!defined('ABSPATH')) exit;

/**
 * Safe evaluator for price formulas.
 * Does NOT use eval(). Parses the expression into tokens and evaluates them manually.
 *
 * Supported elements:
 *   - Variables:  {price}, {vat}
 *   - Operators: + - * / ( )
 *   - Functions:   round(x), round(x,d), ceil(x), floor(x), abs(x), min(x,y), max(x,y)
 *   - Numbers:    integers and floats
 */
class PriceFormula
{
    // Available functions and their PHP equivalents
    private const FUNCTIONS = ['round', 'ceil', 'floor', 'abs', 'min', 'max'];

    public static function evaluate(string $formula, float $price, float $vat = 23.0): float|\WP_Error
    {
        if (empty(trim($formula))) {
            return $price;
        }

        // Podstaw zmienne
        $expr = str_replace(['{price}', '{vat}'], [$price, $vat], $formula);

        // Replace decimal commas with dots (but not argument commas in functions)
        // Safe: tokenize
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

        return round($result, 10); // keep precision, WC rounds on its own
    }

    // Tokenize the expression
    private static function tokenize(string $expr): array
    {
        $tokens = [];
        $i      = 0;
        $len    = strlen($expr);

        while ($i < $len) {
            $ch = $expr[$i];

            // Whitespace: skip
            if (ctype_space($ch)) {
                $i++;
                continue;
            }

            // Number (with optional minus as unary)
            if (ctype_digit($ch) || $ch === '.') {
                $num = '';
                while ($i < $len && (ctype_digit($expr[$i]) || $expr[$i] === '.')) {
                    $num .= $expr[$i++];
                }
                $tokens[] = ['type' => 'number', 'value' => (float) $num];
                continue;
            }

            // Identifier (function or variable)
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

            // Operators and parentheses
            if (in_array($ch, ['+', '-', '*', '/', '(', ')', ','], true)) {
                $tokens[] = ['type' => 'op', 'value' => $ch];
                $i++;
                continue;
            }

            throw new \InvalidArgumentException("Nieznany znak: {$ch}");
        }

        return $tokens;
    }

    // Expression parser: Shunting-Yard + evaluation
    private static function parseExpression(array $tokens): float
    {
        $output   = []; // value stack
        $ops      = []; // operator stack
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

        // Unary minus: precedes a number after an operator or at the start
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
                    // Next token should be a number
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
                    array_pop($ops); // remove '('
                    // If a function is on top of the stack, call it
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
        // 1-argument functions: ceil, floor, round (without d), abs
        // 2-argument functions: round(x,d), min(x,y), max(x,y)
        $two_arg = ['min', 'max'];
        $opt_two = ['round']; // round may take 1 or 2 arguments

        if (in_array($fn, $two_arg, true)) {
            if (count($stack) < 2) throw new \InvalidArgumentException("Funkcja {$fn} wymaga 2 argumentów.");
            $b = array_pop($stack);
            $a = array_pop($stack);
            return [$a, $b];
        }

        if (in_array($fn, $opt_two, true) && count($stack) >= 2) {
            // Check whether the second argument is an integer (decimal places)
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
