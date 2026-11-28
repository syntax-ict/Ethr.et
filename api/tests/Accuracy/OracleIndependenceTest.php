<?php

declare(strict_types=1);

/*
 * The oracle is worth something only while it shares nothing with the engine:
 * no application code, no Laravel, no Carbon, no database, no reading of the
 * clock, the environment or the file system, and no way to build a name at run
 * time. Function names here are prefixed `oracle` (see OracleCasesTest).
 */

// Pest's arch `toOnlyUse` sees the classes a file names, not what they call
// in turn, so the token scan below covers the rest.
arch('the oracle uses only itself and a few named PHP classes')
    ->expect('Tests\Accuracy\Oracle')
    ->toOnlyUse(['DateTimeImmutable', 'DateTimeZone', 'DateInterval', 'Closure', 'InvalidArgumentException', 'Tests\Accuracy\Oracle']);

arch('the oracle uses no application, framework, date library or database code')
    ->expect('Tests\Accuracy\Oracle')
    ->not->toUse(['App', 'Illuminate', 'Carbon', 'Database', 'Laravel', 'Symfony', 'Pest', 'PHPUnit', 'Tests\Accuracy\OracleComparer']);

const ORACLE_CLOCK_AND_WORLD_FUNCTIONS = [
    // the clock
    'now', 'today', 'time', 'date', 'gmdate', 'date_create', 'date_create_immutable', 'date_create_from_format',
    'date_create_immutable_from_format', 'getdate', 'localtime', 'idate', 'gettimeofday', 'microtime', 'hrtime',
    'mktime', 'gmmktime', 'strtotime', 'date_default_timezone_get', 'strftime', 'gmstrftime',
    // framework helpers
    'collect', 'config', 'app', 'env', 'resolve', 'base_path', 'storage_path',
    // the file system, the environment, other processes
    'file_get_contents', 'file_put_contents', 'file', 'fopen', 'readfile', 'fgets', 'fread', 'glob', 'scandir',
    'opendir', 'is_file', 'file_exists', 'getenv', 'putenv', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open',
    // names built at run time
    'call_user_func', 'call_user_func_array', 'constant', 'eval', 'forward_static_call', 'forward_static_call_array',
    'is_callable', 'func_get_args',
    // randomness
    'rand', 'mt_rand', 'random_int', 'uniqid', 'shuffle',
];

const ORACLE_FORBIDDEN_VARIABLES = ['$_SERVER', '$_ENV', '$GLOBALS', '$_GET', '$_POST', '$_COOKIE', '$_FILES', '$_REQUEST', '$_SESSION'];

const ORACLE_DATE_CLASSES = ['DateTime', 'DateTimeImmutable', 'DateTimeZone', 'DatePeriod', 'DateInterval'];

/**
 * Everything in the oracle's source that reaches outside its input, found with
 * the tokeniser, so comments, strings, methods and names such as
 * getTimestamp() are not hit.
 *
 * @return list<string> `path:line what`
 */
function oracleForbiddenUses(string $directory): array
{
    $found = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = ltrim(substr($file->getPathname(), strlen($directory)), '\\/');
        $isCalendar = basename($file->getPathname()) === 'Calendar.php';
        $tokens = [];
        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $tokens[] = $token;
        }

        $name = static fn (mixed $t): ?string => is_array($t) && in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) ? ltrim($t[1], '\\') : null;
        $line = static fn (mixed $t): int => is_array($t) ? $t[2] : 0;

        foreach ($tokens as $i => $token) {
            $previous = $tokens[$i - 1] ?? null;
            $next = $tokens[$i + 1] ?? null;
            $after = is_array($previous) ? $previous[0] : $previous;
            $member = in_array($after, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST], true);
            $where = $relative.':'.$line($token);

            // include, require, eval
            if (is_array($token) && in_array($token[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE, T_EVAL], true)) {
                $found[] = "{$where} {$token[1]}";
            }

            // $_SERVER and the like
            if (is_array($token) && $token[0] === T_VARIABLE && in_array($token[1], ORACLE_FORBIDDEN_VARIABLES, true)) {
                $found[] = "{$where} {$token[1]}";
            }

            // a call to a function that reads the clock, the world, or builds a name
            $called = $name($token);
            if ($called !== null && $next === '(' && ! $member && in_array(strtolower($called), ORACLE_CLOCK_AND_WORLD_FUNCTIONS, true)) {
                $found[] = "{$where} {$called}()";
            }

            // `new $class`, `new (expr)`: a name built at run time
            if (is_array($token) && $token[0] === T_NEW) {
                if ((is_array($next) && $next[0] === T_VARIABLE) || $next === '(') {
                    $found[] = "{$where} new with a name built at run time";
                }
                $class = $name($next);
                if ($class !== null && in_array($class, ORACLE_DATE_CLASSES, true) && ! $isCalendar) {
                    $found[] = "{$where} new {$class} outside Calendar.php";
                }
                if ($isCalendar && ($class === 'DateTimeImmutable' || $class === 'DateTime') && ! oracleCalendarArgumentIsGuarded($tokens, $i + 3)) {
                    $found[] = "{$where} new {$class} from an argument that is not validated or an @timestamp";
                }
            }

            // DateTimeImmutable::createFromFormat() and friends
            if ($called !== null && in_array($called, ORACLE_DATE_CLASSES, true) && is_array($next) && $next[0] === T_DOUBLE_COLON) {
                $method = is_array($tokens[$i + 2] ?? null) ? $tokens[$i + 2][1] : '';
                if (! ($isCalendar && $called === 'DateTimeZone' && $method === 'listIdentifiers')) {
                    $found[] = "{$where} {$called}::{$method}";
                }
            }
        }
    }

    return $found;
}

/**
 * The first argument of `new DateTimeImmutable(` in Calendar.php must be a
 * validated date (`self::date(`) joined to a time, or `'@'` and a timestamp.
 * `$open` is the index of the first token after the opening parenthesis.
 *
 * @param  list<mixed>  $tokens
 */
function oracleCalendarArgumentIsGuarded(array $tokens, int $open): bool
{
    $text = '';
    $depth = 0;
    for ($i = $open, $n = count($tokens); $i < $n; $i++) {
        $t = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
        if ($t === '(') {
            $depth++;
        } elseif ($t === ')') {
            $depth--;
            if ($depth < 0) {
                break;
            }
        } elseif ($t === ',' && $depth === 0) {
            break;
        }
        $text .= $t;
    }

    return str_starts_with($text, "'@'.") || str_contains($text, 'self::date(');
}

it('reads no clock, environment, file or framework helper, and builds no name at run time', function (): void {
    expect(oracleForbiddenUses(__DIR__.'/Oracle'))->toBe([]);
});

it('can find every kind of thing it forbids', function (): void {
    $snippets = [
        'a global clock call' => '$a = now();',
        'a fully qualified clock call' => '$a = \now();',
        'a fully qualified date call' => '$a = \date("Y");',
        'date with an explicit timestamp' => '$a = date("Y", $_SERVER["REQUEST_TIME"]);',
        'gmdate' => '$a = gmdate("Y", 5);',
        'date_create' => '$a = date_create();',
        'getdate' => '$a = getdate();',
        'microtime' => '$a = microtime(true);',
        'hrtime' => '$a = hrtime(true);',
        'mktime' => '$a = mktime(0, 0, 0, 1, 1, 2026);',
        'strtotime' => '$a = strtotime("2026-01-01");',
        'a date object outside Calendar.php' => '$a = new DateTimeImmutable("now");',
        'a fully qualified date object' => '$a = new \DateTimeImmutable("2026-01-01");',
        'a static factory' => '$a = DateTimeImmutable::createFromFormat("Y", "2026");',
        'a name built at run time' => '$a = new $class();',
        'call_user_func' => '$a = call_user_func("x");',
        'constant()' => '$a = constant("X");',
        'require' => 'require "x.php";',
        'file_get_contents' => '$a = file_get_contents("x");',
        'getenv' => '$a = getenv("X");',
        '$_SERVER' => '$a = $_SERVER["X"];',
        '$_ENV' => '$a = $_ENV["X"];',
        'app()' => '$a = app();',
    ];

    $clean = '// now() date() in a comment, "time()" in a string
        $a = $x->time() + self::date("x") + $d->getTimestamp(); $b = "now()"; $c = strlen("x");';

    foreach ($snippets as $what => $code) {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oracle-scan-'.getmypid().'-'.md5($what);
        mkdir($dir.DIRECTORY_SEPARATOR.'sub', 0777, true);
        $file = $dir.DIRECTORY_SEPARATOR.'sub'.DIRECTORY_SEPARATOR.'Bad.php'; // also proves the scan is recursive
        file_put_contents($file, "<?php\n{$code}\n");

        try {
            expect(oracleForbiddenUses($dir))->not->toBe([], "the scan did not flag: {$what}");
        } finally {
            unlink($file);
            rmdir($dir.DIRECTORY_SEPARATOR.'sub');
            rmdir($dir);
        }
    }

    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oracle-scan-'.getmypid().'-clean';
    mkdir($dir);
    file_put_contents($dir.DIRECTORY_SEPARATOR.'Clean.php', "<?php\n{$clean}\n");
    try {
        expect(oracleForbiddenUses($dir))->toBe([]);
    } finally {
        unlink($dir.DIRECTORY_SEPARATOR.'Clean.php');
        rmdir($dir);
    }
});
