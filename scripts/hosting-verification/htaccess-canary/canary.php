<?php

/**
 * ETHR web-server canary.
 *
 * Safe to be web-reachable — that is the point. It reports booleans about the
 * web server and nothing about the environment: no phpinfo, no paths, no
 * grants, no disable_functions. The sensitive probe
 * (scripts/hosting-verification/ethr-hosting-check.php) stays in ~/ and is
 * never served; this answers the questions that one structurally cannot.
 *
 * DEPLOY
 *   1. Upload this whole directory to httpdocs/ethr-canary/ (.htaccess,
 *      canary.php, secret.txt.probe, shadow.txt, shadow.js — all five).
 *   2. Visit  https://<host>/ethr-canary/canary.php
 *   3. Follow the two manual checks it prints.
 *   4. Save the output, then DELETE THE DIRECTORY.
 *
 * It writes nothing, reads no database, and sends no mail.
 */

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

$rewriteHit = isset($_GET['rewrite']);
$shadowHit = isset($_GET['shadow']);
// Every check below is printed as a copy-pasteable URL, so getting this prefix
// wrong turns all five into 404s against a path that does not exist — and the
// canary reads a 404 on /REWRITE_OK as "mod_rewrite is NOT active", which would
// be a false FAIL on G0-B.1.
//
// The subtlety is that an empty dirname does NOT mean CLI. Serving this from
// the document root itself gives SCRIPT_NAME=/canary.php, dirname='/', and
// rtrim leaves '' — which the previous test ('' -> fall back) mistook for CLI
// and answered with the hardcoded '/ethr-canary'. Measured 2026-09-18 against
// php -S: it printed http://host/ethr-canary/REWRITE_OK while actually serving
// at /canary.php. Under the documented deploy (httpdocs/ethr-canary/) the old
// code was right, so this only ever bit an operator who uploaded the four files
// one directory up.
//
// So: ask the SAPI, not the string. '' is a legitimate answer meaning root.
$rawScriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
if (PHP_SAPI === 'cli' || $rawScriptName === '' || $rawScriptName[0] !== '/') {
    // Genuine CLI — there is no URL to derive. Show the intended deploy path so
    // the printed commands are still copy-pasteable after filling in the host.
    $scriptDir = '/ethr-canary';
} else {
    $scriptDir = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', dirname($rawScriptName)), '/');
}
$selfUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    .'://'.($_SERVER['HTTP_HOST'] ?? '<host>')
    .$scriptDir;

$authForwarded = isset($_SERVER['HTTP_AUTHORIZATION']) || isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);

$modules = function_exists('apache_get_modules') ? apache_get_modules() : null;

$line = str_repeat('=', 72);
echo "$line\nETHR WEB-SERVER CANARY\nGenerated: ".date('c')."\n$line\n\n";

printf("server software      : %s\n", $_SERVER['SERVER_SOFTWARE'] ?? '(not reported)');
printf("php sapi             : %s\n", PHP_SAPI);
if ($modules === null) {
    // Normal under FPM/CGI and not itself a problem: PHP cannot see the Apache
    // module list from there. The live tests below are what count.
    $moduleNote = 'not visible from this SAPI - the live tests below are what count';
} else {
    $interesting = array_values(array_filter(
        $modules,
        static fn ($m) => in_array($m, ['mod_rewrite', 'mod_headers', 'mod_authz_core'], true)
    ));
    $moduleNote = $interesting === [] ? 'none of the three we need' : implode(', ', $interesting);
}

printf("modules visible      : %s\n\n", $moduleNote);

if ($shadowHit) {
    $which = $_GET['shadow'] === 'js' ? 'shadow.js' : 'shadow.txt';
    echo "!! You reached this script via /{$which}, so the REWRITE won for that\n";
    echo "!! extension: this host let .htaccess rewrite a path that exists as a\n";
    echo "!! real file on disk.\n";
    if ($which === 'shadow.txt') {
        echo "!! This is the WEAKER of the two baits. Test /shadow.js as well\n";
        echo "!! before recording G0-B.5 -- see the G0-B.5 block below for why.\n\n";
    } else {
        echo "!! shadow.js is the bait that matters: it carries a static\n";
        echo "!! extension, so this answer covers the files the deployment\n";
        echo "!! actually ships. Record G0-B.5 = REWRITE WINS.\n\n";
    }
}

echo "── Automatic ───────────────────────────────────────────────────────\n";

printf("[%s] G0-B.1  mod_rewrite honoured\n", $rewriteHit ? ' PASS ' : ' ???? ');
if (! $rewriteHit) {
    echo "         Not yet tested. Open this URL and re-read the result:\n";
    echo "           {$selfUrl}/REWRITE_OK\n";
    echo "         If that 404s, .htaccess rewriting is NOT active.\n";
}

printf("\n[%s] G0-B.4  Authorization header reaches PHP\n", $authForwarded ? ' PASS ' : ' ???? ');
echo "         Only meaningful when the request actually carries one. Test:\n";
echo "           curl -s -H 'Authorization: Bearer probe' {$selfUrl}/canary.php | grep G0-B.4\n";
echo "         Sanctum auth and CSRF break silently without this.\n";

echo "\n── Manual, and the one that matters ────────────────────────────────\n\n";
echo "[ ???? ] G0-B.2  mod_headers honoured\n";
echo "         curl -sI {$selfUrl}/canary.php | grep -i x-ethr-canary\n";
echo "         Expect: X-Ethr-Canary: headers-ok\n";
echo "         Nothing back means the CSP, HSTS and X-Frame-Options that\n";
echo "         next.config.ts currently sets would not be applied in\n";
echo "         production either.\n\n";

echo "[ ???? ] G0-B.3  deny rules enforced   <-- DEPLOYMENT BLOCKER IF THIS FAILS\n";
echo "         curl -s -o /dev/null -w '%{http_code}\\n' {$selfUrl}/secret.txt.probe\n";
echo "         Expect: 403\n";
echo "         A 200 means .htaccess deny rules are ignored on this host, so\n";
echo "         api/.env, .git/ and composer.json would be web-readable in\n";
echo "         production while the application still appeared to work.\n";
echo "         A 404 is NOT a pass — it means the file is missing, so upload\n";
echo "         secret.txt.probe and try again.\n\n";

echo "[ ???? ] G0-B.5  does a real file shadow the rewrite?  RUN BOTH\n";
echo "         curl -s {$selfUrl}/shadow.txt | head -1\n";
echo "         curl -s {$selfUrl}/shadow.js  | head -1\n\n";
echo "         Each exists on disk AND is rewritten to this script, so the\n";
echo "         answer names which layer resolved the request first:\n";
echo "           'NOT-A-SECRET...'  -> the FILE won; nginx served disk before\n";
echo "                                 the rewrite ran\n";
echo "           this canary's text -> the REWRITE won; Apache resolved it\n\n";
echo "         WHY TWO BAITS, AND WHY .js IS THE ONE THAT COUNTS.\n";
echo "         Measured 2026-09-18 against a local reproduction of Plesk's\n";
echo "         topology (nginx proxying to Apache):\n";
echo "           static block includes .txt  -> both report FILE won\n";
echo "           static block EXCLUDES .txt  -> shadow.txt says REWRITE won,\n";
echo "                                          shadow.js says FILE won\n";
echo "         Plesk's generated static block always covers js/css/images;\n";
echo "         whether it covers .txt varies by version. So .txt alone can\n";
echo "         report 'rewrite won' on a host that is shadowing every asset\n";
echo "         the deployment ships — which is .js, .css and .woff2, never\n";
echo "         .txt. THEY CAN LEGITIMATELY DISAGREE; record both.\n\n";
echo "         If shadow.js says the FILE won, .htaccess never runs for\n";
echo "         static assets, so the seven security headers and\n";
echo "         Cache-Control: immutable silently do not reach them. That is\n";
echo "         G0-B.2's real failure mode hiding behind G0-B.5's answer.\n";
echo "         DEPLOYMENT.md step 4a is correct either way — it copies only\n";
echo "         index.php, which holds under both.\n\n";

echo "$line\n";
echo "Record all five in docs/deployment/GATE-0-RESULT.md, then DELETE this\n";
echo "directory from the server.\n";
echo "$line\n";
