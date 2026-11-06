/**
 * What an exported page carries itself, for the copies the host serves
 * straight from disk.
 *
 * On the shared host, nginx answers `/` and any direct `.html` URL from the
 * document root before Apache runs, so the security headers in `.htaccess`
 * never reach those responses; clean URLs such as `/features` go through
 * Apache and carry them (measured 2026-11-05, A-Z review W6-01). The hosting
 * contract rules out a panel fix, so the page brings its own:
 *
 * - the Content-Security-Policy as a `<meta>` tag, identical to the header
 *   (a page that also gets the header enforces both, which is the same
 *   policy), less `frame-ancestors`, which a `<meta>` tag cannot set;
 * - a guard that hides the page when it is shown inside a frame, in place of
 *   `frame-ancestors` and `X-Frame-Options`. A frame that blocks scripts
 *   keeps the page from running at all, so nothing in it works.
 *
 * `static-page-guard.test.ts` holds the policy to the `.htaccess` one.
 */
export const STATIC_EXPORT_CSP =
  "default-src 'self'; script-src 'self' 'unsafe-inline' 'wasm-unsafe-eval' https://maps.googleapis.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; font-src 'self' data:; connect-src 'self' https://nominatim.openstreetmap.org https://maps.googleapis.com https://storage.googleapis.com; base-uri 'self'; form-action 'self'";

export const FRAME_GUARD_SCRIPT =
  'if (window.top !== window.self) { document.documentElement.style.display = "none"; }';
