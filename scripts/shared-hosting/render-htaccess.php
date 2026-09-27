<?php

declare(strict_types=1);

/**
 * Render the deployable <DOCROOT>/.htaccess from the repository template.
 *
 * WHY THIS EXISTS. `docs/deployment/shared-hosting/.htaccess` ships with its
 * BRANCH B rules commented out, because the same file has to serve two
 * deployment branches and only one of them can be active. Until 2026-09-27 the
 * runbook said to uncomment them by hand, and BASELINE.md §21c records what that
 * cost: a script that matched on directive names uncommented the English
 * sentence "ErrorDocument, not a rewrite. Measured 2026-09-26: ...", Apache
 * returned 500 for the whole vhost, and `httpd -t` reported "Syntax OK" because
 * it does not read .htaccess at all. Three lines in that block begin with the
 * words "Ordering", "Order matters" and "ErrorDocument," — and `Order` and
 * `ErrorDocument` are both real Apache directives.
 *
 * So the template does not ask a reader (or a regex) to tell prose from
 * directives. It marks them:
 *
 *   `#@ RewriteRule ...`   a directive to enable — the sentinel
 *   `# Order matters. ...` prose — left exactly as it is
 *
 * This script strips `#@ ` (or `#@` followed by end-of-line) and nothing else.
 * It cannot promote a comment, because a comment does not carry the sentinel.
 *
 * WHAT IT DELIBERATELY DOES NOT DO. It does not reorder, reformat, reindent,
 * normalise or minify anything. Group ordering inside the template is
 * load-bearing — group 0's /admin deny has to precede the SPA fallback or it
 * never fires, measured 200-instead-of-403 on 2026-09-26 — so the safest
 * renderer is one that changes line prefixes and leaves every byte of every
 * directive, and their order, alone. `SharedHostingHtaccessTest` asserts that
 * property directly.
 *
 * VERIFICATION STATUS. The rendered rules are LOCAL VERIFIED against Apache
 * 2.4.58 (BASELINE.md §21d, 20/20; §21g, 18/18). They are NOT HOST VERIFIED:
 * whether Ethio Telecom's Apache honours `[F,L]` at all is M1 and still open,
 * and `AllowOverride Options` is a requirement this rule set introduced. This
 * script rendering successfully says nothing about either.
 *
 * THE BRANCH LETTERS ARE A TRAP, AND SOMEONE HAS ALREADY FALLEN INTO IT.
 *
 *     BRANCH A = the Plesk Node.js application (Passenger owns `/`)
 *     BRANCH B = the static export served by Apache   <-- the production path
 *
 * They read backwards from what most people guess: "A" sounds like the primary
 * option and it is the one that is excluded. A 2026-09-27 directive said
 * "Use Branch A: static-export frontend ... keep --branch=a as the production
 * path" — the description and the letter contradicted each other, and following
 * the letter would have selected the Node application, which is the opposite of
 * what was decided.
 *
 * So prefer `--target=`, which names the thing instead of indexing it:
 *
 *   php scripts/shared-hosting/render-htaccess.php --target=static-export
 *   php scripts/shared-hosting/render-htaccess.php --target=static-export -o <DOCROOT>/.htaccess
 *
 * `--branch=b` still works, because the runbook, this repository's `.htaccess`
 * and `docs/audit/BASELINE.md` §21 all say "BRANCH B" and renaming a measured
 * thing to tidy a label is how a measurement stops being one.
 *
 * `--target=node` / `--branch=a` are refused with an error that names the
 * confusion, rather than silently doing the other thing.
 *
 * Exit codes: 0 rendered · 2 usage error · 3 template defect.
 */
const SENTINEL_PATTERN = '/^(\s*)#@(?: |$)/';

/** The template, relative to the repository root. */
const TEMPLATE = 'docs/deployment/shared-hosting/.htaccess';

/**
 * Directives that must survive into every rendered BRANCH B artifact.
 *
 * This is a floor, not a description: the test file carries the full assertion
 * set. Keeping a short list here means a botched render fails at the point of
 * rendering rather than at the first request on the host, which is the whole
 * lesson of §21.
 *
 * Each entry is a substring, matched against the rendered output with the
 * comment lines removed.
 */
const REQUIRED_IN_BRANCH_B = [
    'RewriteEngine On',
    // .env / .git / composer.json / artisan / phpunit.xml deny — the M1 mechanism.
    '[F,L]',
    // The front controller for /api and /sanctum.
    'RewriteRule ^ index.php [L]',
    // Group 0: the /admin host boundary, which must precede the fallback.
    'RewriteRule ^admin(/|$) - [F,L]',
    // Group 2's two non-Rewrite directives, the ones a name-matching script skipped.
    'Options -Indexes',
    'DirectorySlash Off',
    // Group 3, which is why an unknown URL returns 404 rather than 200.
    'ErrorDocument 404 /404.html',
    // The CSP, without which nothing hydrates.
    "script-src 'self' 'unsafe-inline'",
];

function fail(string $message, int $code): never
{
    fwrite(STDERR, "render-htaccess: {$message}\n");
    exit($code);
}

function repoRoot(): string
{
    // This file lives at <root>/scripts/shared-hosting/, so the root is two up.
    return dirname(__DIR__, 2);
}

/**
 * Strip the sentinel from directive lines, leaving everything else untouched.
 *
 * Returns the rendered text and the number of lines enabled, so the caller can
 * refuse a render that enabled nothing — which is what a renamed sentinel or a
 * truncated template looks like, and it would otherwise produce a syntactically
 * valid .htaccess that routes nothing.
 *
 * @return array{0: string, 1: int}
 */
function render(string $template): array
{
    $lines = explode("\n", $template);
    $enabled = 0;

    foreach ($lines as $i => $line) {
        if (preg_match(SENTINEL_PATTERN, $line, $m) === 1) {
            $lines[$i] = $m[1].substr($line, strlen($m[0]));
            $enabled++;
        }
    }

    return [implode("\n", $lines), $enabled];
}

/** Rendered output with comments and blank lines removed — what Apache acts on. */
function directivesOnly(string $rendered): string
{
    $out = [];

    foreach (explode("\n", $rendered) as $line) {
        $trimmed = trim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        $out[] = $trimmed;
    }

    return implode("\n", $out);
}

// ── main ────────────────────────────────────────────────────────────────────

if (PHP_SAPI !== 'cli') {
    fail('this script is CLI-only', 2);
}

$branch = null;
$outPath = null;

$argvRest = array_slice($argv, 1);

for ($i = 0; $i < count($argvRest); $i++) {
    $arg = $argvRest[$i];

    // `--target=` is the spelling to use. `--branch=` is kept working because
    // the runbook, the .htaccess and BASELINE §21 all say "BRANCH B", but the
    // letters are a known trap — see the TARGETS docblock below.
    if (str_starts_with($arg, '--target=')) {
        $target = strtolower(substr($arg, strlen('--target=')));

        $branch = match ($target) {
            'static-export', 'static', 'export', 'apache' => 'b',
            'node', 'nodejs', 'plesk-node', 'passenger' => 'a',
            default => fail(
                "unknown --target={$target}. Use --target=static-export (the owner-decided "
                .'production path) or --target=node.',
                2
            ),
        };

        continue;
    }

    if (str_starts_with($arg, '--branch=')) {
        $branch = strtolower(substr($arg, strlen('--branch=')));

        continue;
    }

    if ($arg === '-o' || $arg === '--output') {
        $outPath = $argvRest[$i + 1] ?? null;
        $i++;

        continue;
    }

    if (str_starts_with($arg, '--output=')) {
        $outPath = substr($arg, strlen('--output='));

        continue;
    }

    if ($arg === '-h' || $arg === '--help') {
        // Print the usage block from this file's own docblock rather than a
        // second copy of it that can disagree.
        $self = (string) file_get_contents(__FILE__);
        preg_match('/ \* Usage:\n(.*?)\n \*\n/s', $self, $usage);
        fwrite(STDOUT, str_replace(' *   ', '  ', $usage[1] ?? '')."\n");
        exit(0);
    }

    fail("unknown argument: {$arg}", 2);
}

if ($branch === 'a') {
    fail(
        "BRANCH A is the PLESK NODE.JS APPLICATION, and it is not the production path.\n"
        ."\n"
        ."                 IF YOU WERE TOLD TO \"USE BRANCH A\" MEANING THE STATIC EXPORT, THE\n"
        ."                 LETTER IS WRONG AND YOU WANT:  --target=static-export\n"
        ."\n"
        ."                 In this repository the letters run the other way round:\n"
        ."                     BRANCH A = Plesk Node.js application  (Passenger owns `/`)\n"
        ."                     BRANCH B = static export served by Apache  <-- owner-decided\n"
        ."\n"
        ."                 The owner decided the STATIC EXPORT on 2026-09-27; see\n"
        ."                 docs/decisions/OWNER-DECISION-C5-FRONTEND-TARGET.md. The Plesk\n"
        ."                 Node.js application is an extension, which hard rule 2 of\n"
        ."                 SHARED-HOSTING-CONTRACT.md excludes.\n"
        .'                 Separately: under BRANCH A the "Everything else" block is DELETED '
        .'rather than enabled, so it is not a render at all.',
        2
    );
}

if ($branch !== 'b') {
    fail(
        '--target=static-export is required (equivalently --branch=b). BRANCH B is the static '
        ."export; BRANCH A is the Plesk Node application and is not the production path.",
        2
    );
}

$templatePath = repoRoot().'/'.TEMPLATE;

if (! is_file($templatePath)) {
    fail("template not found at {$templatePath}", 3);
}

[$rendered, $enabled] = render((string) file_get_contents($templatePath));

if ($enabled === 0) {
    fail(
        'the template contains no `#@ ` sentinel lines, so this render would enable no BRANCH B '
        .'rules at all. Refusing rather than writing a file that serves nothing.',
        3
    );
}

$directives = directivesOnly($rendered);
$missing = [];

foreach (REQUIRED_IN_BRANCH_B as $needle) {
    if (! str_contains($directives, $needle)) {
        $missing[] = $needle;
    }
}

if ($missing !== []) {
    fail(
        "the rendered output is missing required directives:\n                 - "
        .implode("\n                 - ", $missing),
        3
    );
}

// A rendered artifact must never still carry a sentinel: that would mean a line
// the renderer was supposed to enable survived as a comment.
if (preg_match(SENTINEL_PATTERN, $directives) === 1) {
    fail('a sentinel survived into the rendered directives', 3);
}

if ($outPath === null) {
    fwrite(STDOUT, $rendered);
    exit(0);
}

$dir = dirname($outPath);

if (! is_dir($dir) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
    fail("cannot create output directory {$dir}", 2);
}

if (file_put_contents($outPath, $rendered) === false) {
    fail("cannot write {$outPath}", 2);
}

fwrite(
    STDERR,
    sprintf(
        "render-htaccess: wrote %s — BRANCH B, %d directives enabled.\n"
        ."render-htaccess: LOCAL VERIFIED rules (BASELINE.md §21d/§21g). NOT host-verified:\n"
        ."render-htaccess:   M1 ([F,L] honoured?) and AllowOverride Options are still open.\n",
        $outPath,
        $enabled
    )
);

exit(0);
