// NOT-A-SECRET-JS. This file is the ETHR canary's G0-B.5 bait for a STATIC
// EXTENSION, and it exists because shadow.txt alone gives a false negative.
//
// Measured 2026-09-18 by reproducing Plesk's topology locally — nginx proxying
// to Apache, with nginx's "serve static files directly" location block enabled:
//
//   static block includes .txt   shadow.txt -> the FILE won   (correct answer)
//   static block EXCLUDES .txt   shadow.txt -> the REWRITE won (WRONG answer)
//                                shadow.js  -> the FILE won   (correct answer)
//
// Plesk's generated static block always covers js/css/images; whether it covers
// .txt varies by version and template. So a canary that tests only .txt can
// report "the rewrite won" on a host that is in fact shadowing every asset the
// deployment actually ships — which is .js, .css and .woff2, never .txt.
//
// That mattered: the damage from static shadowing is that .htaccess never runs
// for those files, so the seven security headers and Cache-Control: immutable
// silently do not apply to them. G0-B.2's real failure mode hides behind
// G0-B.5's answer.
//
// Read the two together. They can legitimately disagree.
