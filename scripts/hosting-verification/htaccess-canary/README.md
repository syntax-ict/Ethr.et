# Web-server canary

Answers the one class of question the main probe structurally cannot.

`../ethr-hosting-check.php` lives in `~/` and is never served by Apache — deliberately, because it prints `disable_functions`, database grants and the filesystem layout. But *"is `.htaccess` honoured?"* can only be answered by something the web server actually serves.

This directory is the opposite trade: **it is web-reachable and discloses nothing.** Five booleans, no environment detail, no database, no writes, no mail.

## Files

| File | Purpose |
|---|---|
| `RUN-SHEET.md` | **Offline checklist** — browser and Plesk File Manager only, no shell. Stays in the repository like this file; not uploaded |
| `.htaccess` | The rules under test — rewrite, headers, deny, `Authorization` forwarding |
| `canary.php` | Reports what arrived, prints the **eight** `curl` fetches to run |
| `secret.env.probe` | **Bait for the deployment's own deny mechanism** (`RewriteRule ... [F,L]`), added 2026-09-25. Must return **403**. This is the one whose result predicts production. Contains nothing confidential |
| `secret.txt.probe` | Bait for `<FilesMatch>` + `Require all denied` — an authorization grant the deployment does **not** use. Must return **403**. **Run both; they can legitimately disagree**, because `AllowOverride` can grant `FileInfo` while withholding `Limit`. Contains nothing confidential |
| `shadow.txt` | Bait for G0-B.5. Exists on disk *and* is rewritten, so the response says which layer won. Contains nothing confidential |
| `shadow.js` | **The same bait with a static extension, and the one that counts.** Added 2026-09-18 after measurement: Plesk's "serve static files directly by nginx" block always covers js/css/images but only *sometimes* covers `.txt`, so `shadow.txt` alone reports "the rewrite won" on a host that is shadowing every asset the deployment ships. **Run both; they can legitimately disagree.** Contains nothing confidential |

*Counts corrected again 2026-09-25 — six files, eight fetches.* `secret.env.probe` was
added because the canary had been testing `<FilesMatch>`/`Require all denied` while the
deployment denies with `RewriteRule ... [F,L]`, and G0-B.2 gained a second fetch because
this directory was setting two of the deployment's seven headers while claiming a scan of
it "reflects what the deployment would set". Both are measured, not inferred.

*Counts corrected 2026-09-19.* This file said "four" files and, ten lines apart, both
"three" and "four" curl checks. It was five and five: `shadow.js` was added on 2026-09-18
as the second G0-B.5 bait and the counts here were never brought along. Six fetches in
total.

*Corrected 2026-09-22.* The line above used to end "— loading `canary.php` is itself the
G0-B.1 reading." It is not. `canary.php:26` sets `$rewriteHit` from `isset($_GET['rewrite'])`,
and only the `RewriteRule ^REWRITE_OK$ canary.php?rewrite=1` supplies that. Opened
directly, the script reports G0-B.1 as `????` and tells you to fetch `/REWRITE_OK`. The
count of six is right; which fetch answers B.1 was not.

## Run sheet

**Why this is worth the fifteen minutes.** `SHARED_HOSTING_PLAN.md` §3A costs frontend
Option A (static export) at **≈8 days, realistically 6–11** — and **3 of those days are
gated on G0-B.1/B.2, which this canary answers.** `.htaccess` is not a convenience for
that option, it is the mechanism: it carries the SPA rewrite that entity routes
(`/employees/{id}` and three others) have **no alternative mechanism for**, plus the
CSP/HSTS headers a static export silently drops and the `/admin` host boundary that
middleware can no longer enforce. If `.htaccess` is ignored on this host, Option A is not
bounded and that estimate is void rather than optimistic.

So this is the one check that can show Option A unbounded **before** the eight days are
spent rather than during them — and it needs no shell, no cron and no support ticket.

### 1 · Upload

All **five** files into **`httpdocs/ethr-canary/`**.

`.htaccess` is hidden. In Plesk File Manager turn on **Show hidden files**; in an FTP
client enable hidden files. It will otherwise silently not upload, and **every check below
then reports a false negative**. Confirm it arrived before going on.

### 2 · Open it

```
https://www.ethr.et/ethr-canary/canary.php
```

**A 404 here means stop** — see *If canary.php returns 404* below. It is a document-root
or vhost fact, not an `.htaccess` fact, and none of the five checks mean anything yet.

**Opening it does not read G0-B.1, and it will show `[ ???? ]` for that row.** That is
correct, not a failure: only `/REWRITE_OK` answers B.1 — see step 3. This step is the
baseline reading (server software, SAPI) and a check that the directory is reachable at
all.

### 3 · Run the eight fetches

`canary.php` prints these too, but without `--resolve` — it builds them from the hostname
in the request it receives, so it cannot add the flag for you. Use these instead:

```bash
R="--resolve www.ethr.et:443:213.55.96.154"
U="https://www.ethr.et/ethr-canary"

# G0-B.1  mod_rewrite
curl $R -s "$U/REWRITE_OK" | grep -E "G0-B.1|PASS"

# G0-B.2  mod_headers — RUN BOTH. The marker alone proves only that the two
#         SHORTEST headers survived; CSP is the one an intermediary mangles.
curl $R -sI "$U/canary.php" | grep -i x-ethr-canary
curl $R -sI "$U/canary.php" | grep -ci content-security-policy   # expect 1

# G0-B.3  deny rules        <-- the deployment blocker
#         RUN BOTH. secret.env.probe uses RewriteRule [F,L], which is what the
#         deployment's .htaccess actually uses; the .txt bait uses
#         <FilesMatch>/Require, which it does not. They can disagree.
curl $R -s -o /dev/null -w '%{http_code}\n' "$U/secret.env.probe"
curl $R -s -o /dev/null -w '%{http_code}\n' "$U/secret.txt.probe"

# G0-B.4  Authorization forwarded
curl $R -s -H 'Authorization: Bearer probe' "$U/canary.php" | grep -A1 "G0-B.4"

# G0-B.5  static shadowing — RUN BOTH, they can legitimately disagree
curl $R -s "$U/shadow.txt" | head -1
curl $R -s "$U/shadow.js"  | head -1
```

Six fetches in total: loading `canary.php` in step 2 is the baseline reading, and
`/REWRITE_OK` is what actually exercises G0-B.1.

### 4 · Read the answers

| Check | Pass | What a failure means |
|---|---|---|
| **B.1** rewrite | `[ PASS ] G0-B.1` — **only when fetched as `/REWRITE_OK`**, never by opening `canary.php` | a **404** means rewriting is off. **This is the one that unbounds Option A** — no rewrite, no way to serve `/employees/{id}` at all |
| **B.2** headers | `X-Ethr-Canary: headers-ok` | nothing back → CSP, HSTS, X-Frame-Options and Permissions-Policy would not be applied in production. **Silent**: the site works perfectly and nothing logs it |
| **B.3** deny | `403` | `200` → `.env`, `.git/` and `composer.json` web-readable while the app still looks fine. **`404` is NOT a pass** — the file did not upload; fix and re-run |
| **B.4** auth | the `PASS` line | Sanctum auth and CSRF break silently — it looks like an auth bug and costs an afternoon |
| **B.5** shadow | *(no failing answer)* — **run both baits and record both** | `NOT-A-SECRET…` → the **file** won (nginx served disk first). This canary's own text → the **rewrite** won. **They can legitimately disagree; score the gate from `shadow.js`** |

**On B.5, `shadow.js` is the authoritative one, and the two disagreeing is a legitimate
result rather than a contradiction to resolve.** Plesk's static block always covers
js/css/images but only sometimes covers `.txt`, so `shadow.txt` alone can report "rewrite
won" on a host that is shadowing every asset the deployment actually ships. **`.js` is
authoritative because `.js`, `.css` and `.woff2` are what the deployment ships; it never
ships `.txt`.** Record both answers, score the gate from `shadow.js`.

Why each of these matters to ETHR is in *What each check means*; which failure hurts most
is in *If `.htaccess` is ignored*.

### 5 · Record, then delete

Write the five results into `docs/deployment/GATE-0-RESULT.md`'s G0-B rows with today's
date — then **delete `httpdocs/ethr-canary/` entirely**. Nothing in it is secret, but a
stray `.htaccess` in a live document root is a configuration surprise waiting to happen.

### While you are in the panel

Read the bottom of **Apache & nginx Settings**. Confirming whether a directives textarea
exists closes **G0-A** for free — and it is what decides whether the remedy below is
available at all (see *If any of these fail*).

## Why every request is pinned with `--resolve`

The run sheet's `$R` is `--resolve www.ethr.et:443:213.55.96.154`, and it is not
decoration.

**Do not trust DNS or assume which vhost answers.** `docs/B1-B5_GATE_REPORT.md` records
the account host as `213.55.96.154`; that IP is the repository's evidence for where this
account lives.

This is not paranoia on this host specifically: `B1-B5_GATE_REPORT.md` records
`zzq7x.ethr.et` reaching the *server default* page rather than the `ethr.et` vhost, which
is direct evidence that this box serves more than one vhost and that which one answers is
the open question. A request that lands on the wrong vhost returns a plausible answer, and
a plausible wrong answer is worse than an error.

## If canary.php returns 404 — stop

A 404 after a successful upload means the request is **not served from the directory you
uploaded into**. That is a document-root/vhost fact, not a `.htaccess` fact.

**None of the checks is meaningful until it is fixed**, and each would misreport:
a 404 on either `secret.*.probe` reads as "not a pass", a 404 on `REWRITE_OK` reads as
"`mod_rewrite` off", and both conclusions would be wrong. Do not record a G0-B failure.

1. Record it against the `document root editable` row in `GATE-0-RESULT.md`, not a G0-B row.
2. **Websites & Domains → Hosting Settings** — read *Document root* verbatim; it may not
   be `httpdocs`.
3. Establish which vhost answered: compare `www.ethr.et` against a name known to hit the
   server default (`zzq7x.ethr.et`). Same page → the `ethr.et` vhost is not serving you.
4. Re-upload all **six** files (`.htaccess`, `canary.php`, `secret.env.probe`, `secret.txt.probe`, `shadow.txt`, `shadow.js`) into the confirmed document root.
5. Re-open `canary.php`. Only once it loads do G0-B.1 – G0-B.5 mean anything — and then
   fetch `/REWRITE_OK` for B.1, which opening `canary.php` never answers.

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
