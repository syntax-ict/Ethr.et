# One panel session — runbook

**Everything left in this workstream that needs the Plesk panel or the host, in one pass.**
Ordered to minimise navigation, not by gate number: the subscription page first because two
questions read off one screen, then domains, then File Manager, then the database, then the
decisions that need no panel at all.

**Target:** Ethio Telecom Linux shared hosting (Plesk) · account `ethret` ·
`213.55.96.154` · `ethr.et`

**Who:** the owner. None of this can be run from the repository — no SSH (B-1), and the
egress proxy in the agent environment returns 403 for `ethr.et`, so a result cannot even be
verified from there.

**Time:** about 45 minutes, most of it Part 3.

> ### Read before starting
>
> - **Two steps change the live site.** They are marked **⚠ CHANGES THE LIVE SITE**, and
>   each says what to back up first. Nothing else writes anything.
> - **Do not fill an answer from documentation, vendor marketing or inference.** Only from
>   what the panel or the response actually shows. That rule is
>   [`deployment/GATE-0-RESULT.md`](deployment/GATE-0-RESULT.md)'s and it is the reason
>   this file exists rather than a guess.
> - **"Not present" is an answer.** If a page or field is missing, record that — it is a
>   measurement, and several gates turn on absence.
> - **Copy values verbatim.** Where this asks for a field, paste the string as shown,
>   including units and any "unlimited". Where it asks for a screenshot, a crop of the
>   named panel is enough.

---

## Part 1 · Subscription page — **Q9** and half of **Q3**

**Location:** Plesk → **Websites & Domains** → select `ethr.et` → the subscription name in
the breadcrumb, **or** the **Account** / **My Subscriptions** entry in the left menu. The
page is titled with the plan name and carries a *Resources* or *Allocated resources* panel.

### Q9 — what tier is this account, actually?

**Read:** the plan name shown on the subscription, and the *Resources* table.

**Why it is open:** the tier is **ASSUMED** everywhere in
[`deployment/SHARED_HOSTING_PLAN.md`](deployment/SHARED_HOSTING_PLAN.md). "Bronze" was
owner-supplied on 2026-08-29 and repeated since; **nobody has read it from the panel.** That
is testimony, and this repository grades testimony PARTIAL.

**Expected:** a plan name — `Bronze`, `Gold`, or something else entirely.
**A failing result:** no plan name visible, or a name that matches nothing in the published
spec sheet. Both are recordable answers; the second is more interesting than a match.

**Copy back:** the plan name string, and the *Resources* table as a screenshot.

### Q3 — does a wildcard count as one subdomain, or does each tenant count individually?

**Read, on the same page:** the **Subdomains** row of the resources table — both the limit
and the *current usage* number.

**Why it matters:** this is the single most consequential unknown in the workstream. If each
tenant counts individually, the published limits cap the product at **5 customers on Bronze**
and **15 on Gold**, and no tier is viable — which is a product question, not a hosting one.

**Expected:** a limit (a number, or "Unlimited") and a usage count.
**The panel usually cannot settle this on its own** — the counting *rule* only becomes
visible once a wildcard exists. Record the two numbers; if no wildcard subdomain exists yet,
say so, because then the usage figure tells us nothing about the rule.

**Copy back:** `Subdomains: <used> of <limit>`, and whether a wildcard (`*.ethr.et`) is
present in the subdomain list.

**If the rule is still ambiguous after reading it**, it goes in the support ticket — see
Part 5.

---

## Part 2 · Domains — **B-3**, the unexplained vhost

**Location:** Plesk → **Websites & Domains**. Look at the full list of domains, subdomains
and aliases on the subscription.

**The blocker:** `httpdocs/ethr.et/` appeared on 2026-09-17 23:48 — a Plesk-provisioned
vhost skeleton whose document root is **inside** `httpdocs/`. Its purpose is unknown, it may
collide with the live `ethr.et` vhost that carries the certificate, and it inverts the
intended `~/ethr` layout.

**Read first, do not delete:** find the entry whose document root is `httpdocs/ethr.et` (or
`/ethr.et`). Note **what kind of entry it is** — domain, subdomain, alias, or "additional
domain" — and whether it has its own certificate.

**Expected:** exactly one entry pointing there, distinct from the live `ethr.et` whose
document root is `httpdocs`.
**A failing result — and the one to stop on:** the entry pointing at `httpdocs/ethr.et` *is*
the live `ethr.et`, or it holds the Let's Encrypt certificate. In that case **do not remove
anything** and report it; removing it would take the site down and break renewal.

**Copy back:** the entry's name, its type, its document root as displayed, and whether a
certificate is attached. A screenshot of the domain list is ideal.

> **⚠ CHANGES THE LIVE SITE — removal only, and only if the above is unambiguous.**
> **Deleting a vhost is not deleting a folder.** If and only if the entry is clearly a
> stray skeleton — no certificate, not the live site — remove it *through the panel's domain
> removal*, not by deleting the directory in File Manager. Deleting the directory leaves the
> vhost configured and pointing at nothing.
> **Back up first:** File Manager → select `httpdocs/ethr.et` → **Add to Archive**, and keep
> the archive outside `httpdocs`.
> **If anything is ambiguous, leave it and report.** It is costing nothing where it sits.

---

## Part 3 · File Manager + browser — the canary, **G0-B.1 – G0-B.5**

This is the long one, and it has its own step-by-step sheet. **Do not work from this file —
follow [`../scripts/hosting-verification/htaccess-canary/RUN-SHEET.md`](../scripts/hosting-verification/htaccess-canary/RUN-SHEET.md)**,
which is written for exactly this case: no shell, browser only, with DevTools steps for the
two fetches that need request or response headers.

What this section adds is only the framing and the sequencing.

> **⚠ CHANGES THE LIVE SITE — it publishes a directory.**
> The canary is designed to be web-reachable and to disclose nothing: five booleans, no
> environment detail. It is safe to publish **and it is deleted at the end** — step 9 of the
> run sheet. Nothing needs backing up, because nothing existing is modified; you are adding
> `httpdocs/ethr-canary/` and then removing it.

**Location:** Plesk → **Files** (File Manager) → `httpdocs` → create `ethr-canary`.

**Upload all five files** from `scripts/hosting-verification/htaccess-canary/` in the
repository:

```
.htaccess   canary.php   secret.txt.probe   shadow.txt   shadow.js
```

`README.md` stays in the repository — it explains the baits and there is no reason to
publish it.

> **`.htaccess` is the one File Manager will fight you on.** A leading-dot file is hidden by
> default; enable *Show hidden files* in the File Manager settings before uploading, and
> confirm afterwards that it is actually there. **If `.htaccess` is missing, every result in
> this part is a false FAIL** — the canary would be measuring a directory with no rules in it.

**Then: six fetches, five gates.** The run sheet numbers them; the summary of what each is
for, so you know when one has gone wrong:

| Fetch | Gate | What it answers |
|---|---|---|
| 1 of 6 | — | baseline; `G0-B.1` deliberately reads `[ ???? ]` here |
| 2 of 6 | **G0-B.1** | `mod_rewrite` — **only** `/ethr-canary/REWRITE_OK` answers this |
| 3 of 6 | **G0-B.3** | deny rules — **the deployment blocker** |
| 4 of 6 | **G0-B.5 (a)** | `shadow.txt` — the weaker bait |
| 5 of 6 | **G0-B.5 (b)** | `shadow.js` — **the authoritative bait** |
| 6 of 6 | **G0-B.2** | `mod_headers` — needs DevTools |
| bonus | **G0-B.4** | `Authorization` reaches PHP — DevTools console |

**Three traps the run sheet spells out, repeated here because each silently voids a gate:**

- **Opening `canary.php` by its own filename does not answer G0-B.1.** The script sets its
  rewrite flag from a query parameter that only the rewrite rule supplies. Fetch 2 is the
  only one that counts for that row.
- **Score G0-B.5 from `shadow.js`, not `shadow.txt`.** The two can legitimately disagree.
  `.js`, `.css` and `.woff2` are what the deployment actually ships; `.txt` never is.
- **Pin the fetches to the Plesk host.** DNS already points at `213.55.96.154`, but pinning
  removes the doubt: `curl --resolve www.ethr.et:443:213.55.96.154 …`, or the run sheet's
  browser equivalent.

**The single most important line:** `curl -i https://www.ethr.et/.env` — or the browser
equivalent — must return **403**. [`deployment/GATE-0-RESULT.md`](deployment/GATE-0-RESULT.md)
treats that as a necessary condition before anything is deployed.

**Expected:** a PASS/FAIL per row, five rows.
**A failing result is still a result** — and if the deny rules (G0-B.3) fail,
`deployment/shared-hosting/nginx-directives.conf` is the prepared answer, unverified against
any live host.

**Copy back:** the canary's printed output (screenshot or copy-paste), plus the six response
statuses, plus the two DevTools readings. Then **delete `httpdocs/ethr-canary/`** — run sheet
step 9.

---

## Part 4 · Databases — evidence for **Q8**

**Location:** Plesk → **Databases** → the ETHR database → **phpMyAdmin**.

**Q8 is a decision, not a read** (Part 5). But the decision rests on a premise nobody has
tested: that `TRIGGER` is denied. The panel can settle the premise in one query, and if the
premise is false the question disappears.

**Run, in phpMyAdmin's SQL tab:**

```sql
SHOW GRANTS FOR CURRENT_USER();
```

**Expected if the premise holds:** the grant list does **not** include `TRIGGER`.
**Expected if the premise is wrong:** `TRIGGER` appears, or the grant is `ALL PRIVILEGES`.
That would retire Q8 entirely and unblock steps 3 and 7 of §5.5 — the single highest-value
outcome available in this whole session.

**A failing result:** phpMyAdmin refuses the statement, or no database exists yet. Record
which; "no database yet" is a different answer from "denied".

**Copy back:** the full `Grants for …` line, verbatim. It contains no password.

---

## Part 5 · Decisions — no panel needed

These are yours to take, and the panel contributes nothing to two of the three. They are
listed here so the session ends with them rather than leaving them for another day.

### Q6 — which external caller drives the cron endpoints, and where does `CRON_TOKEN` live?

Three options, traded in full in `deployment/SHARED_HOSTING_PLAN.md` §5.3a:

| Option | For | Against |
|---|---|---|
| **GitHub Actions** | no new vendor | 5-minute documented minimum, best-effort delivery |
| **The existing VPS** | perfect cadence | makes this Option C, and `SHARED_HOSTING_MIGRATION_PLAN.md` §5 then says Option A is strictly better |
| **Third-party cron service** | good cadence | a stranger holds a key that can drain your queues and reads up to 2000 characters of Artisan output |

**Copy back:** which one, and where the token should live.

### Q8 — `TRIGGER` denied: accept the audit-log downgrade, or ETHR does not deploy here?

**Answer Part 4 first.** If `TRIGGER` turns out to be granted, skip this.

If it is genuinely denied: `migrate` aborts at `2026_07_22_000001` **by design**, and
[`AUDIT_LOG_INTEGRITY_DECISION.md`](AUDIT_LOG_INTEGRITY_DECISION.md) pre-registered this
exact case. The choice returns to you as accepted risk, and the mechanism is then an
`AUDIT_LOG_REQUIRE_DB_IMMUTABILITY` flag that is **deliberately not built**. **This blocks
the deploy path at step 3 of §5.5** — nothing past installation proceeds without it.

**Copy back:** accept, or do not deploy here.

### The No-Go re-take

`SHARED_HOSTING_MIGRATION_PLAN.md` §4's **No-Go → Option A** fired on **G0-D**, and stands
until someone re-takes it deliberately. Note what it does *not* turn on: `httpdocs/` being
clean now does not move it, and neither does B-3. **G0-D was answered FAIL on 2026-09-18** —
there is no Scheduled Tasks section — so the thing the No-Go fired on has not changed.

Re-taking it is therefore a judgement about whether Plan B's route (deployment actions for
one-off work, an external caller for recurring work) is good enough, not a reading.
**Recommendation: do not re-take it in this session.** Take it after Q6 and Q8 are answered,
because both change what Option A would actually cost.

**Copy back:** re-take now, or defer.

### Send the support request

[`deployment/ETHIO-TELECOM-SUPPORT-REQUEST.md`](deployment/ETHIO-TELECOM-SUPPORT-REQUEST.md)
has **still never been sent**. It costs nothing and strictly improves Plan B: a cron grant
would retire Q6 entirely, and a `TRIGGER` grant would retire Q8. Order, unchanged:
**cron → higher plans → `TRIGGER` → SSH.** Add Q3 to it if Part 1 left the counting rule
ambiguous.

**No reply is not a refusal.** Plan B's denial premise is *assumed*.

**Copy back:** sent, or not.

---

## Reply template

Paste this back filled in. One line each; `not present` and `could not tell` are valid
answers and are more useful than a guess.

```
Q9  tier:            <plan name as shown>
Q3  subdomains:      <used> of <limit>; wildcard present: yes/no
B-3 vhost:           <name> / <type> / docroot <as shown> / cert: yes/no / removed: yes/no
G0-B.1 rewrite:      PASS / FAIL          (fetch 2, /REWRITE_OK)
G0-B.2 headers:      PASS / FAIL          (fetch 6, DevTools)
G0-B.3 deny rules:   PASS / FAIL          (fetch 3)  ← blocker
G0-B.4 auth header:  PASS / FAIL          (bonus, DevTools console)
G0-B.5 static:       .js PASS/FAIL  .txt PASS/FAIL   (score from .js)
/.env returns:       <status code>        ← must be 403
canary deleted:      yes/no
Q8  grants line:     <full "Grants for ..." output>
Q6  cron caller:     GitHub Actions / VPS / third-party; token lives: <where>
Q8  decision:        accept downgrade / do not deploy here / moot (TRIGGER granted)
No-Go re-take:       re-take now / defer
Support ticket:      sent / not sent
```

Anything that went wrong, in one line each — an unexpected page, a missing field, a fetch
that would not complete. Those are measurements too.
