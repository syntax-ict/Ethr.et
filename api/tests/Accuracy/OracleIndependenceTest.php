<?php

declare(strict_types=1);

/*
 * The oracle is worth something only while it shares nothing with the engine:
 * no application code, no Laravel, no Carbon, no database, and no reading of
 * the clock. Function names here are prefixed `oracle` (see OracleCasesTest).
 */

arch('the oracle uses no application, framework, date library or database code')
    ->expect('Tests\Accuracy\Oracle')
    ->not->toUse(['App', 'Illuminate', 'Carbon', 'Database']);

/**
 * Calls in the oracle's code, found with the tokeniser so comments, strings,
 * methods and names such as getTimestamp() or strtotime() are not hit.
 *
 * @return list<string> one line per forbidden call: file, line, call
 */
function oracleForbiddenCalls(string $directory): array
{
    $forbidden = ['now', 'today', 'collect', 'config', 'app', 'time', 'date'];
    $found = [];

    foreach (glob($directory.'/*.php') ?: [] as $file) {
        $tokens = token_get_all((string) file_get_contents($file));
        $significant = [];
        foreach ($tokens as $i => $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $significant[] = [$i, $token];
        }

        foreach ($significant as $position => [$i, $token]) {
            if (! is_array($token) || $token[0] !== T_STRING || ! in_array($token[1], $forbidden, true)) {
                continue;
            }
            $next = $significant[$position + 1][1] ?? null;
            $previous = $significant[$position - 1][1] ?? null;
            if ($next !== '(') {
                continue;
            }
            if (is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
                continue;
            }

            if ($token[1] === 'date' && oracleTopLevelArguments($significant, $position + 1) >= 2) {
                continue; // date() with an explicit timestamp reads no clock
            }

            $found[] = sprintf('%s:%d %s()', basename($file), $token[2], $token[1]);
        }
    }

    return $found;
}

/** Number of top-level arguments of the call whose '(' is at `$open`. */
function oracleTopLevelArguments(array $significant, int $open): int
{
    $depth = 0;
    $arguments = 0;
    for ($i = $open, $n = count($significant); $i < $n; $i++) {
        $token = $significant[$i][1];
        $text = is_array($token) ? $token[1] : $token;
        if (in_array($text, ['(', '[', '{'], true)) {
            $depth++;
            if ($depth === 1 && $significant[$i + 1][1] !== ')') {
                $arguments = 1;
            }
        } elseif (in_array($text, [')', ']', '}'], true)) {
            $depth--;
            if ($depth === 0) {
                break;
            }
        } elseif ($text === ',' && $depth === 1) {
            $arguments++;
        }
    }

    return $arguments;
}

it('reads no clock and uses no framework helper', function (): void {
    expect(oracleForbiddenCalls(__DIR__.'/Oracle'))->toBe([]);
});

it('builds no moment from the current time or a relative string', function (): void {
    foreach (glob(__DIR__.'/Oracle/*.php') ?: [] as $file) {
        $source = (string) file_get_contents($file);
        expect($source)->not->toMatch('/new\s+(?:\\\\)?DateTime(?:Immutable)?\s*\(\s*\)/', basename($file));
        expect($source)->not->toMatch('/\b(?:strtotime|mktime|microtime|hrtime)\s*\(/', basename($file));
    }
});

it('can find a forbidden call', function (): void {
    // The scan is only worth anything if it can see what it forbids.
    $dir = sys_get_temp_dir().'/oracle-scan-'.getmypid();
    mkdir($dir);
    file_put_contents($dir.'/Bad.php', "<?php\n// now( in a comment is fine\n\$a = now();\n\$b = date('Y');\n\$c = date('Y', 5);\n\$d = \$x->time();\n");

    try {
        expect(oracleForbiddenCalls($dir))->toBe(['Bad.php:3 now()', 'Bad.php:4 date()']);
    } finally {
        unlink($dir.'/Bad.php');
        rmdir($dir);
    }
});
