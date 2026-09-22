# Web-server canary

Answers the one class of question the main probe structurally cannot.

`../ethr-hosting-check.php` lives in `~/` and is never served by Apache — deliberately, because it prints `disable_functions`, database grants and the filesystem layout. But *"is `.htaccess` honoured?"* can only be answered by something the web server actually serves.

This directory is the opposite trade: **it is web-reachable and discloses nothing.** Five booleans, no environment detail, no database, no writes, no mail.

## Files

| File | Purpose |
|---|---|
| `.htaccess` | The rules under test — rewrite, headers, deny, `Authorization` forwarding |
| `canary.php` | Reports what arrived, prints the **five** `curl` checks to run |
| `secret.txt.probe` | Bait. Must return **403**. Contains nothing confidential |
| `shadow.txt` | Bait for G0-B.5. Exists on disk *and* is rewritten, so the response says which layer won. Contains nothing confidential |
| `shadow.js` | **The same bait with a static extension, and the one that counts.** Added 2026-09-18 after measurement: Plesk's "serve static files directly by nginx" block always covers js/css/images but only *sometimes* covers `.txt`, so `shadow.txt` alone reports "the rewrite won" on a host that is shadowing every asset the deployment ships. **Run both; they can legitimately disagree.** Contains nothing confidential |

*Counts corrected 2026-09-19.* This file said "four" files and, ten lines apart, both
"three" and "four" curl checks. It is five and five: `shadow.js` was added on 2026-09-18
as the second G0-B.5 bait and the counts here were never brought along. Six fetches in
total — loading `canary.php` is itself the G0-B.1 reading.

## Use

```
1. Upload all five to   httpdocs/ethr-canary/   (.htaccess is hidden — check it went)
2. Open                 https://www.ethr.et/ethr-canary/canary.php
3. Run the five curl commands it prints, each with --resolve (below)
4. Record results in    docs/deployment/GATE-0-RESULT.md  (G0-B rows)
5. DELETE THE DIRECTORY
```

## Pin every request to the Plesk host

**Do not trust DNS or assume which vhost answers.** `docs/B1-B5_GATE_REPORT.md` records
the account host as `213.55.96.154`; that IP is the repository's evidence for where this
account lives. Add `--resolve` to every command:

```bash
curl --resolve www.ethr.et:443:213.55.96.154 -sI https://www.ethr.et/ethr-canary/canary.php
```

`canary.php` builds its printed commands from the hostname in the request it receives, so
it cannot add the flag for you — add it by hand to each one.

This is not paranoia on this host specifically: `B1-B5_GATE_REPORT.md` records
`zzq7x.ethr.et` reaching the *server default* page rather than the `ethr.et` vhost, which
is direct evidence that this box serves more than one vhost and that which one answers is
the open question. A request that lands on the wrong vhost returns a plausible answer, and
a plausible wrong answer is worse than an error.

## If canary.php returns 404 — stop

A 404 after a successful upload means the request is **not served from the directory you
uploaded into**. That is a document-root/vhost fact, not a `.htaccess` fact.

**None of the five checks is meaningful until it is fixed**, and each would misreport:
a 404 on `secret.txt.probe` reads as "not a pass", a 404 on `REWRITE_OK` reads as
"`mod_rewrite` off", and both conclusions would be wrong. Do not record a G0-B failure.

1. Record it against the `document root editable` row in `GATE-0-RESULT.md`, not a G0-B row.
2. **Websites & Domains → Hosting Settings** — read *Document root* verbatim; it may not
   be `httpdocs`.
3. Establish which vhost answered: compare `www.ethr.et` against a name known to hit the
   server default (`zzq7x.ethr.et`). Same page → the `ethr.et` vhost is not serving you.
4. Re-upload all **five** files (`.htaccess`, `canary.php`, `secret.txt.probe`, `shadow.txt`, `shadow.js`) into the confirmed document root.
5. Re-open `canary.php`. Only once it loads do G0-B.1 – G0-B.5 mean anything.

Step 5 matters even though nothing here is secret: a stray `.htaccess` in a live document root is a configuration surprise waiting to happen.

## What each check means

| Check | Why ETHR cares |
|---|---|
| **G0-B.1** `mod_rewrite` | The deployment routes everything through a repointed `index.php`. Without it, nothing resolves. |
| **G0-B.2** `mod_headers` | `src/next.config.ts:33-77` defines CSP, HSTS, X-Frame-Options and Permissions-Policy. Serving the frontend statically drops them with the Node server; they must come from the web server instead. |
| **G0-B.3** deny rules | Second line of defence for `.env`, `.git`, `composer.json` and `storage/`. See the ranking below — this is not the top one. |
| **G0-B.4** `Authorization` | Sanctum auth and the CSRF flow need `Authorization` and `X-XSRF-Token` forwarded to PHP. CGI/FastCGI strips them unless `.htaccess` restores them. |
| **G0-B.5** static shadowing | `DEPLOYMENT.md` step 4a rests on a real file in the document root winning over the rewrite — that is why only `index.php` is copied there and `robots.txt` is not. The Apache half is readable in that file's `!-f` guard; whether Plesk's nginx serves from disk before Apache sees the request is a property of *this host*. The runbook asserted it before anyone measured it. |

## If `.htaccess` is ignored, which check actually hurts

If Apache is not reading `.htaccess` — nginx-only, or `AllowOverride None` — every rule is ignored **silently**. No error, no log entry. But the four rules do not fail equally, and it is worth being precise about which one is the emergency:

| | Failure | How loud |
|---|---|---|
| **G0-B.2 headers** | CSP, HSTS, X-Frame-Options and Permissions-Policy stop being sent. The site works perfectly. Nothing in any log. | **Silent. This is the worst one.** |
| **G0-B.1 rewrite** | `/api/*` stops resolving. | Loud — total outage, noticed in seconds. |
| **G0-B.4 `Authorization`** | Every authenticated request 401s. | Semi-loud — looks like an auth bug, costs an afternoon. |
| **G0-B.3 deny rules** | `.env`/`.git`/`composer.json` become servable **if they are under the document root**. | Silent, but see below. |

This table corrects an earlier version of this file, which called G0-B.3 "the one that matters" and described `api/.env` becoming web-readable with `APP_KEY` and the database password in it. That overstates it. In the layout `docs/deployment/shared-hosting/.htaccess` describes, the Laravel application lives in `~/ethr`, **entirely outside `~/httpdocs`** — so those files are not under the document root and are not reachable whether the deny rules apply or not. That `.htaccess` says so itself, in the comment above the rule. The layout is the control; the deny rules are defence in depth against a file being uploaded to the wrong place, an archive unpacked in the document root, or the document root being repointed later.

Run G0-B.3 anyway, and require 403 — defence in depth is worth having, and a host that ignores deny rules ignores the header rules too. Just do not let a green G0-B.3 read as "headers are fine": they are separate mechanisms and the canary tests them separately for that reason.

**A 404 is not a pass.** It means the file is not there yet — say, before the first deploy — and the same request will return 200 the moment it is.

**G0-B.5 has no failing answer.** Both outcomes are compatible with step 4a, which says
to copy only `index.php` into the document root — correct whether the file layer or the
rewrite layer resolves first. It is recorded because "which layer answered" is the first
question anyone asks when the document root behaves unexpectedly, and the cheapest moment
to answer it is while somebody already has the account open.

## If any of these fail

The fix is Plesk → **Apache & nginx Settings → Additional nginx directives**. That translation is already written, so nobody has to work out nginx `location` semantics under deadline: [`docs/deployment/shared-hosting/nginx-directives.conf`](../../../docs/deployment/shared-hosting/nginx-directives.conf).

> ⚠ **On this account that field appears not to exist**, so read this remedy as
> *prepared* rather than *available*. The owner read Apache & nginx Settings on
> 2026-09-17 and reported **no directives textarea at all** — neither
> "Additional directives for HTTP/HTTPS" nor "Additional nginx directives" — on
> an otherwise complete page. In Plesk both are gated by a service-plan
> permission. Recorded as **B-2** in [`MIGRATION_STATE.md`](../../../docs/MIGRATION_STATE.md)
> and as the evidence behind **G0-A**, currently `NOT VERIFIED` with *"strong
> evidence of FAIL, unconfirmed"*.
>
> It is stated here because this is the page someone reads **at the moment the
> canary has just failed**, which is the worst moment to discover the remedy has
> nowhere to go. If the field is genuinely absent the route is the higher-plans
> ask of [`ETHIO-TELECOM-SUPPORT-REQUEST.md`](../../../docs/deployment/ETHIO-TELECOM-SUPPORT-REQUEST.md),
> not a paste. **Confirm the absence first** — one look at the bottom of that
> page also closes G0-A, which is item 3 of the plan's own priority list.

It covers the silent half — headers, deny rules, timeouts, upload size — and deliberately refuses to guess at routing, because the correct form of that depends on whether nginx proxies to Apache or serves the root itself, which is precisely what this canary is here to find out. Read its header before pasting it; it is unverified against any live host.
