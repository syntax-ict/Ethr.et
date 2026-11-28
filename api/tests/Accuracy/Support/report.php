<?php

declare(strict_types=1);

/*
 * What the generated and property runs write down: a directory under
 * storage/framework/testing (ignored by that folder's own .gitignore), a
 * Wilson interval for an agreement rate, and a case file a person can turn into
 * a golden case. Every helper is global in Pest, so each is prefixed `accuracy`.
 */

function accuracyReportDir(): string
{
    $dir = dirname(__DIR__, 3).'/storage/framework/testing/accuracy';
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    return $dir;
}

/** Deletes the files of one run kind (a glob under the report directory) so a rerun starts clean. */
function accuracyClearReport(string $pattern): void
{
    foreach (glob(accuracyReportDir().'/'.$pattern) ?: [] as $file) {
        @unlink($file);
    }
}

/**
 * The 95% Wilson score interval for k agreements in n comparisons.
 *
 * @return array{0: float, 1: float}
 */
function accuracyWilson(int $k, int $n): array
{
    if ($n === 0) {
        return [0.0, 1.0];
    }
    $z = 1.96;
    $p = $k / $n;
    $denominator = 1 + $z * $z / $n;
    $centre = ($p + $z * $z / (2 * $n)) / $denominator;
    $half = $z * sqrt($p * (1 - $p) / $n + $z * $z / (4 * $n * $n)) / $denominator;

    return [max(0.0, $centre - $half), min(1.0, $centre + $half)];
}

/**
 * A ready-to-paste golden case with its `expect` left for a person to type, and
 * a line saying what was seen. Nothing is copied into `expect`.
 *
 * @param  array<string, mixed>  $case
 * @param  list<string>  $lines
 */
function accuracyWriteCaseFile(string $name, array $case, array $lines): void
{
    $case['expect'] = 'TODO: type by hand';

    $body = "<?php\n\ndeclare(strict_types=1);\n\n";
    foreach ($lines as $line) {
        $body .= '// '.str_replace(["\r", "\n"], ' ', $line)."\n";
    }
    $body .= "\nreturn ".var_export($case, true).";\n";

    file_put_contents(accuracyReportDir().'/'.$name.'.php', $body);
}
