# Canary run sheet — offline, browser only

**For someone with a browser and the Plesk File Manager. No shell, no `curl`, no SSH.**

[`README.md`](README.md) explains *why* each bait exists and what each gate costs. This
file is the other half: a checklist to work through without reading any of that. Where the
two disagree, the README is the reasoning and this is the procedure.

> **This file stays in the repository.** Like `README.md`, it is not one of the five files
> that get uploaded — it names the baits and explains what they are for, and there is no
> reason to publish that on the host.

Six fetches. Work top to bottom and record every answer, including the ones that pass.

---

## 1 · Upload

Create `httpdocs/ethr-canary/` and upload **exactly these five files** from
`scripts/hosting-verification/htaccess-canary/`:

```
.htaccess   canary.php   secret.txt.probe   shadow.txt   shadow.js
```

Two warnings, both of which produce a **false FAIL on every check below** rather than an
error:

- **`.htaccess` is a dotfile and Plesk File Manager hides it by default.** Turn on *Show
  hidden files* (the gear/settings menu) and confirm it is listed after uploading. If you
  upload a zip, check the extractor did not skip it — many do. **Without `.htaccess` there
  are no rules to test, and every result below reads as "the server ignores `.htaccess`"
  when in fact nothing was ever asked of it.**
- **Upload into `ethr-canary/`, not into `httpdocs/` itself.** `canary.php` derives the
  URLs it prints from `SCRIPT_NAME`. One directory up and its own printed links point at a
  path that does not exist — and a 404 on `/REWRITE_OK` reads as *"mod_rewrite is not
  active"*, which is a false FAIL on the gate that matters most.

---

## 2 · Fetch 1 of 6 — the baseline

```
https://www.ethr.et/ethr-canary/canary.php
```

Expect a plain-text report.

| What you see | Meaning |
|---|---|
| The report loads | Go on. Note `server software` and `php sapi` from the header block |
| **404** | **Stop.** This is a document-root or vhost fact, not an `.htaccess` fact — see [`README.md`](README.md) → *If canary.php returns 404*. None of the five gates mean anything yet |
| PHP source code instead of a report | **Stop.** PHP is not executing in this directory. Record it; it is a finding in its own right |

Two things in the report that are **normal and not problems**:

- `modules visible : not visible from this SAPI` — PHP cannot read Apache's module list
  under FPM/CGI. The live tests are what count.
- **`G0-B.1  [ ???? ]`** — this is correct and expected. See the next step.

> **Without `curl` you cannot pin the request to the Plesk host.** The documented `curl`
> form uses `--resolve www.ethr.et:443:213.55.96.154` so the request cannot land on a
> different server than the one being tested. A browser follows DNS. If `www.ethr.et`
> resolves anywhere other than the Plesk account, you are measuring the wrong server — and
> the most likely symptom is the 404 above. Treat a 404 as "which server answered?" before
> treating it as anything else.

---

## 3 · Fetch 2 of 6 — **G0-B.1, `mod_rewrite`**

```
https://www.ethr.et/ethr-canary/REWRITE_OK
```

### This fetch, and only this fetch, answers G0-B.1

Loading `canary.php` directly can never show B.1 as PASS, and reading it that way is the
single easiest mistake to make here. `canary.php:26` sets `$rewriteHit` from
`isset($_GET['rewrite'])`, and the only thing that supplies that parameter is the rule in
`.htaccess`:

```apache
RewriteRule ^REWRITE_OK$ canary.php?rewrite=1 [L,QSA]
```

So opening the script by its own filename bypasses the rewrite entirely. The `[ ???? ]`
you saw in step 2 is the script correctly reporting *"not yet tested"*, not a failure.

| Result | Verdict |
|---|---|
| The canary report loads, now showing **`[ PASS ] G0-B.1`** | **PASS** — `.htaccess` rewriting is active on this host |
| **404** | **FAIL** — `.htaccess` is not processed. Either the server is nginx-only for this path, or Apache runs `AllowOverride None`. **This is the result that unbounds Option A** — see §9 |
| **403** | **Ambiguous.** Most likely Imunify or a WAF rule, which the account is known to run. Record the status and the body verbatim; do **not** score it as either PASS or FAIL |
| PHP source, or a blank page | **Ambiguous.** Record it; something other than `.htaccess` is wrong |

---

## 4 · Fetch 3 of 6 — **G0-B.3, deny rules** ← the deployment blocker

```
https://www.ethr.et/ethr-canary/secret.txt.probe
```

| Result | Verdict |
|---|---|
| **403** | **PASS** |
| **200** — you can read `NOT-A-SECRET. This file is bait…` | **FAIL, and stop.** `.htaccess` deny rules are ignored on this host, so `api/.env`, `.git/` and `composer.json` would be web-readable in production **while the application still worked normally**. The exposure is `APP_KEY` and the database password |
| **404** | **Not a pass.** The file did not upload. Re-upload `secret.txt.probe` and fetch again |

A 200 here blocks deployment on every branch, not just Option A. It is the one result in
this sheet that should stop the session rather than be recorded and moved past.

---

## 5 · Fetch 4 of 6 — **G0-B.5 (a), `shadow.txt`** — the weaker bait

```
https://www.ethr.et/ethr-canary/shadow.txt
```

Read the **first line** of the response.

| First line | Meaning |
|---|---|
| `NOT-A-SECRET. This file is the ETHR canary's G0-B.5 bait.` | **The FILE won** — nginx served it from disk before Apache saw the request |
| `========…` then `ETHR WEB-SERVER CANARY` | **The REWRITE won** — Apache resolved it |

**Do not record G0-B.5 from this fetch alone.** Both answers are legitimate; what makes
this one unreliable is explained in the next step.

---

## 6 · Fetch 5 of 6 — **G0-B.5 (b), `shadow.js`** — the authoritative bait

```
https://www.ethr.et/ethr-canary/shadow.js
```

| First line | Meaning |
|---|---|
| `// NOT-A-SECRET-JS.` | **The FILE won** → record **G0-B.5 = FILE WINS** |
| `========…` then `ETHR WEB-SERVER CANARY` | **The REWRITE won** → record **G0-B.5 = REWRITE WINS** |

### The two can legitimately disagree, and `.js` is the one to record

This is not a contradiction to resolve — it is the measurement. Measured 2026-09-18
against a local reproduction of Plesk's topology (nginx proxying to Apache):

| nginx's static block | `shadow.txt` reports | `shadow.js` reports |
|---|---|---|
| includes `.txt` | FILE won | FILE won |
| **excludes `.txt`** | **REWRITE won** ← misleading | FILE won |

Plesk's generated *"serve static files directly by nginx"* block **always** covers
`js`/`css`/images; whether it covers `.txt` varies by version and template. So on a host
that is shadowing every asset the deployment ships, `shadow.txt` alone reports *"the
rewrite won"* — a false PASS.

**`.js` is authoritative because `.js`, `.css` and `.woff2` are what the deployment
actually ships. It never ships `.txt`.** Record both answers; score the gate from `.js`.

---

## 7 · Fetch 6 of 6 — **G0-B.2, `mod_headers`** (needs DevTools)

A browser does not show response headers in the address bar, so this one needs the
developer tools. No installation, no shell.

1. Open `https://www.ethr.et/ethr-canary/canary.php`
2. Press **F12** (or right-click → *Inspect*)
3. Go to the **Network** tab
4. **Reload the page** — the Network tab only records requests made while it is open
5. Click the `canary.php` row
6. Read the **Response Headers** panel

| Look for | Verdict |
|---|---|
| **`x-ethr-canary: headers-ok`** present | **PASS** |
| Absent | **FAIL** — the CSP, HSTS, X-Frame-Options and Permissions-Policy that `next.config.ts` sets today would not be applied in production either. Silent: the site works perfectly and nothing logs it |

While you are there, record whether `x-frame-options: DENY` and
`x-content-type-options: nosniff` are present too — `.htaccess` sets all three, so a
partial result is itself informative.

> **A PASS here covers PHP responses only.** `canary.php` is a PHP file, so it necessarily
> passes through Apache. Whether headers reach a `.css` or `.js` file is a *different*
> question, and it is the one §6 answers: if `shadow.js` says the FILE won, `.htaccess`
> never runs for static assets, so the headers do not reach them however green this check
> is. `GATE-0-RESULT.md` records this trap in full.

---

## 8 · Bonus — **G0-B.4, `Authorization` reaches PHP** (DevTools console)

A browser cannot send an `Authorization` header by typing a URL, and embedded credentials
in the address bar no longer work in current browsers. Use the console instead.

1. On the `canary.php` page, press **F12** → **Console**
2. Paste this and press Enter:

```js
fetch('/ethr-canary/canary.php', { headers: { Authorization: 'Bearer probe' } })
  .then(r => r.text())
  .then(t => console.log(t.match(/.*G0-B\.4.*/)[0]))
```

| Output | Verdict |
|---|---|
| `[ PASS ] G0-B.4 …` | **PASS** — the header is forwarded to PHP |
| `[ ???? ] G0-B.4 …` | **FAIL** — it is being stripped. Sanctum auth and CSRF break **silently**; it presents as an authentication bug and costs an afternoon to trace |

This is not one of the six numbered fetches — it re-requests a page already counted — but
it is the cheapest of the five gates to answer and should not be skipped.

---

## 9 · Record, then delete

1. Write all five results into [`../../../docs/deployment/GATE-0-RESULT.md`](../../../docs/deployment/GATE-0-RESULT.md)'s
   G0-B rows, **with today's date**, including the two G0-B.5 answers separately.
2. **Delete `httpdocs/ethr-canary/` entirely.** Nothing in it is secret — that is the whole
   design — but a directory of probes left on a production host is a loose end, and the
   `.htaccess` in it is not the one the deployment wants.

---

## What each failure rules out — and which results void Option A

| Failure | What it rules out | Effect on Option A (static export) |
|---|---|---|
| **B.1 FAIL** — `/REWRITE_OK` 404s | Everything downstream. `.htaccess` is inert, so the SPA rewrite `/employees/*` → `/employees/_.html` **cannot exist**. Entity routes then have no serving mechanism at all — not a bug to fix, no mechanism to fix | **VOID.** `SHARED_HOSTING_PLAN.md` §3A's ≈8-day estimate should be **discarded**, not treated as optimistic. Option A stops being bounded, and Option C becomes the live contingency |
| **B.3 FAIL** — 200 on the bait | Deploying to this account at all, on any branch | Blocks every option, not just A |
| **B.2 FAIL** — no `x-ethr-canary` | Restoring the CSP/HSTS/X-Frame-Options that `output: "export"` drops silently | **0.5 day void**, and the headers become **unrecoverable on this host** — a permanent security regression rather than a cost |
| **B.5 = FILE WINS on `.js`** | `.htaccess` running for static assets | The SPA rewrite may still work for extensionless paths, but the seven security headers and `Cache-Control: immutable` never reach `.js`/`.css`/`.woff2`. **This is B.2's real failure mode hiding behind B.5's answer.** `DEPLOYMENT.md` step 4a is correct either way — it copies only `index.php` |
| **B.4 FAIL** | Sanctum's cookie and CSRF transport working as written | Not A-specific; affects every option equally |
| **B.1 or B.3 ambiguous (403)** | Nothing yet | Record verbatim and resolve the WAF question before scoring the gate. An unresolved 403 is not a FAIL, and treating it as one would retire Option A on a firewall rule |

**The one sentence to act on:** if **B.1 fails**, the 3 days `SHARED_HOSTING_PLAN.md` §3A
marks *"gated on G0-B.1/B.2"* are not at risk — they are gone, and with them the only
mechanism entity routes have. That is what makes this sheet worth running **before** the
eight days are spent rather than during them.
