<?php

declare(strict_types=1);

namespace Tests\Accuracy;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The payroll oracle keeps to whole cents: every money and count figure is a
 * PHP int, and all division and rounding live in one audited file (Exact.php).
 * This is the scan that holds it to that, with the tokeniser, so comments,
 * strings (other than the two answer keys), methods and names such as
 * $x->round() are not hit.
 *
 * Outside the helper: no float literal (`1.5`, `1e3`), no `/ /= % %= ** **=`,
 * no `(float)` or `(double)` cast, no call to a rounding, dividing or
 * arbitrary-precision function, and neither of the string literals 'expect'
 * and 'working' (the oracle must never read the hand values).
 *
 * In the helper, `intdiv` and `%` are what remain allowed: no `/`, no float
 * literal, no float cast, and none of the same functions except `intdiv`.
 *
 * A class with static methods, because Pest loads every test file into one
 * process and a global function declared twice is fatal.
 */
final class IntegerOnlyScan
{
    /** Called outside the helper: any of these. */
    private const FORBIDDEN_CALLS = [
        'round', 'floor', 'ceil', 'intdiv', 'fdiv', 'fmod', 'pow', 'sqrt', 'exp', 'log', 'floatval', 'doubleval', 'settype', 'number_format',
    ];

    /** Called in the helper: everything above except intdiv. */
    private const FORBIDDEN_IN_HELPER = [
        'round', 'floor', 'ceil', 'fdiv', 'fmod', 'pow', 'sqrt', 'exp', 'log', 'floatval', 'doubleval', 'settype', 'number_format',
    ];

    private const ANSWER_KEYS = ['expect', 'working'];

    /**
     * @return list<string> `path:line what`
     */
    public static function violations(string $directory, string $helper = 'Exact.php'): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $found = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($directory))), '/');
            $isHelper = $relative === $helper;
            $tokens = [];
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $tokens[] = $token;
            }

            foreach ($tokens as $i => $token) {
                $where = $relative.':'.(is_array($token) ? $token[2] : self::lineBefore($tokens, $i));
                $previous = $tokens[$i - 1] ?? null;
                $next = $tokens[$i + 1] ?? null;

                if ($token === '/' || $token === '%') {
                    if ($token === '%' && $isHelper) {
                        continue;
                    }
                    $found[] = "{$where} the operator {$token}";

                    continue;
                }

                if (! is_array($token)) {
                    continue;
                }

                [$id, $text] = $token;

                if ($id === T_DNUMBER) {
                    $found[] = "{$where} the float literal {$text}";
                } elseif (in_array($id, [T_DIV_EQUAL], true)) {
                    $found[] = "{$where} the operator {$text}";
                } elseif (in_array($id, [T_MOD_EQUAL], true) && ! $isHelper) {
                    $found[] = "{$where} the operator {$text}";
                } elseif (in_array($id, [T_POW, T_POW_EQUAL], true)) {
                    $found[] = "{$where} the operator {$text}";
                } elseif ($id === T_DOUBLE_CAST) {
                    $found[] = "{$where} the cast {$text}";
                } elseif ($id === T_CONSTANT_ENCAPSED_STRING && in_array(trim($text, '\'"'), self::ANSWER_KEYS, true)) {
                    $found[] = "{$where} the answer key {$text}";
                } elseif (in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && $next === '(') {
                    $after = is_array($previous) ? $previous[0] : $previous;
                    if (in_array($after, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST, T_NEW], true)) {
                        continue;
                    }
                    $name = strtolower(ltrim($text, '\\'));
                    $banned = in_array($name, $isHelper ? self::FORBIDDEN_IN_HELPER : self::FORBIDDEN_CALLS, true)
                        || str_starts_with($name, 'bc')
                        || str_starts_with($name, 'gmp_');
                    if ($banned) {
                        $found[] = "{$where} a call to {$name}()";
                    }
                }
            }
        }

        return $found;
    }

    /**
     * The line of a single-character token: the nearest line number before it.
     *
     * @param  list<mixed>  $tokens
     */
    private static function lineBefore(array $tokens, int $index): int
    {
        for ($i = $index; $i >= 0; $i--) {
            if (is_array($tokens[$i])) {
                return $tokens[$i][2];
            }
        }

        return 1;
    }
}
