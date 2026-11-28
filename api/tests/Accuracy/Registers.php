<?php

declare(strict_types=1);

namespace Tests\Accuracy;

use RuntimeException;

/**
 * Reads the rule registers and the case files, for RegisterConsistencyTest.
 * Static only: Pest loads every test file into one process.
 */
final class Registers
{
    /** prefix => [register path from the repository root, case directory from tests/Accuracy] */
    public const ENGINES = [
        'AR' => ['docs/ATTENDANCE-RULES.md', 'cases'],
        'PYR' => ['docs/payroll/PAYROLL-RULES.md', 'Payroll/cases'],
        'LVR' => ['docs/LEAVE-RULES.md', 'Leave/cases'],
        'CAL' => ['docs/production testing/14-LEAVE-CALENDAR-AND-RESTATED-FIGURES.md', null],
    ];

    public const ROW = '/^\| ((?:AR|PYR|LVR|CAL)-[A-Z0-9]+-\d+) \|(.*)\| (Decided|Implemented|Undecided \(legal\)|Undecided) \|$/';

    public static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array{rules: array<string, array{state: string, text: string}>, unparsed: list<string>} */
    public static function register(string $prefix): array
    {
        $path = self::root().'/'.self::ENGINES[$prefix][0];
        if (! is_file($path)) {
            throw new RuntimeException("missing register {$path}");
        }

        $rules = [];
        $unparsed = [];
        foreach (preg_split('/\R/', (string) file_get_contents($path)) as $n => $line) {
            $line = rtrim($line);
            if (preg_match('/^\| '.$prefix.'-[A-Z0-9]+-\d+ \|/', $line) !== 1) {
                continue;
            }
            if (preg_match(self::ROW, $line, $m) !== 1) {
                $unparsed[] = 'line '.($n + 1).': '.substr($line, 0, 60);

                continue;
            }
            $rules[$m[1]] = ['state' => $m[3], 'text' => trim($m[2])];
        }

        return ['rules' => $rules, 'unparsed' => $unparsed];
    }

    /**
     * The rule ids each case cites, keyed by a name for the case, or null when
     * the engine has no case directory yet.
     *
     * @return array<string, array{rules: list<string>, waits_on: bool}>|null
     */
    public static function citations(string $prefix): ?array
    {
        if ($prefix === 'CAL') {
            $cited = [];
            foreach (require __DIR__.'/Calendar/pins.php' as $id => $files) {
                foreach ($files as $file) {
                    $cited[$file.' '.$id] = ['rules' => [$id], 'waits_on' => false, 'file' => $file];
                }
            }

            return $cited;
        }

        $dir = __DIR__.'/'.self::ENGINES[$prefix][1];
        if (! is_dir($dir)) {
            return null;
        }

        $cited = [];
        foreach (glob($dir.'/*.{php,json}', GLOB_BRACE) ?: [] as $file) {
            $data = str_ends_with($file, '.json')
                ? json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR)
                : require $file;
            if (is_array($data)) {
                self::collect($data, basename($file), $cited);
            }
        }

        return $cited;
    }

    /** @param array<string, array{rules: list<string>, waits_on: bool}> $cited */
    private static function collect(array $node, string $where, array &$cited): void
    {
        $ids = array_merge((array) ($node['rule'] ?? []), (array) ($node['rules'] ?? []));
        if ($ids !== []) {
            $name = $where.' '.(is_string($node['id'] ?? null) ? $node['id'] : '#'.count($cited));
            $cited[$name] = ['rules' => array_values(array_map('strval', $ids)), 'waits_on' => ! empty($node['waits_on'])];
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                self::collect($child, $where, $cited);
            }
        }
    }

    /** @return array<string, string> id => reason */
    public static function exemptions(): array
    {
        return require __DIR__.'/register-exemptions.php';
    }
}
