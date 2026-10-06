# Running ETHR locally in its production shape

**Three commands. Runs clean — 36/36 checks, zero errors in the Apache or Laravel logs.**

```bash
scripts/local-production/up.sh          # build, assemble, migrate, start
scripts/local-production/verify.sh      # 36 checks across every path shape
scripts/local-production/down.sh        # stop
```

Then open **http://localhost:8081/**.

> **This is not the Ethio Telecom host and closes no gate.** It is your Apache, your
> MariaDB and your grants, under `AllowOverride All` — the permissive case, which is
> exactly why `BASELINE.md` §21e says a local harness cannot answer **G0-B.6**.
> `CUTOVER-CHECKLIST.md` is unchanged by anything here and
> `./scripts/gates.sh evidence` still lists all eleven host gates.
>
> What it *is*: the Bronze deployment procedure executed end to end, which had never
> been done anywhere before 2026-09-27. It finds defects in **our** procedure and
> **our** code — and it has found **five**, itemised in `BASELINE.md` §23b. Four were
> in the harness rather than the product, which is the cost of this kind of tool and
> is worth paying: one of the five was a hardcoded `admin.ethr.et` that would have
> made the platform console unreachable in production, silently.

---

## 1. Local vs production — what differs, and why

The rule is simple: **local gets working values, production gets placeholders.** Every
account-specific value is a parameter, never a literal, because a literal is what
breaks silently on a different domain.

| | Local (this setup) | Production (Bronze/Plesk) |
|---|---|---|
| **Web server** | XAMPP Apache 2.4.58, port **8081**, its own config | Plesk Apache behind nginx, port 443 |
| **PHP** | `mod_cgi` + `Action` → `php-cgi` (`cgi-fcgi`) | **`fpm-fcgi`** — measured on the host |
| **Document root** | `.local-production/app/api/public/` | `ethr/api/public` — set on purpose since 2026-10-06 (Laravel Toolkit). **Exactly that value: `ethr` or `ethr/api` would serve `.env`** |
| **App root** | `.local-production/app/` — the release, deployed over it | `<APP_ROOT>` — `~/ethr/`, Plesk Git's deploy path; the document root is its `api/public` |
| **Database** | MariaDB 10.4.32, `ethr_local_prod` / `ethr_localprod` | `<DB_NAME>` / `<DB_USER>`, version **unverified (G0-I)** |
| **Admin host** | `admin.localhost:8081` | `admin.<APP_DOMAIN>` |
| **`APP_URL`** | `http://localhost:8081` | `https://<APP_DOMAIN>` |
| **`APP_KEY`** | generated fresh per setup | generated on the host by `key:generate` — **never the one in git history** |
| **Scheduler** | run by hand (below) | GitHub Actions, once two secrets exist |
| **TLS** | none | Plesk-issued; wildcard binding is **M3**, open |

**Identical in both:** the release tree itself (`scripts/shared-hosting/build-release.sh`,
built from committed files, the same script CI publishes with). That includes the
rendered `.htaccess`, `.user.ini`, Laravel's own `index.php` and the static export, plus `QUEUE_CONNECTION`/`CACHE_STORE`/
`SESSION_DRIVER=database`, `BROADCAST_CONNECTION=null`, `FILESYSTEM_DISK=local`, and
the audit-log triggers. Those are the things worth rehearsing; the rest is plumbing.

### The one value with no default

`--admin-host` is **required** by the renderer. It was the literal `admin.ethr.et`
until 2026-09-28, and that was a latent production defect: rendered for any other
domain, the deny predicate reads *"refuse `/admin` unless the host is
admin.ethr.et"* — so it refuses on the **real** admin host too, and the platform
console is unreachable. Silently, because the rest of the site is fine.

```bash
# production
php scripts/shared-hosting/render-htaccess.php \
    --target=static-export --admin-host=admin.<APP_DOMAIN> -o <DOCROOT>/.htaccess

# local — up.sh does this for you
php scripts/shared-hosting/render-htaccess.php \
    --target=static-export --admin-host=admin.localhost:8081 -o .../api/public/.htaccess
```

Include the port if requests carry one: the predicate anchors on the whole `Host`
header. The value is regex-escaped, so a dot cannot match an arbitrary character.

### The database it must not touch

`DB_NAME`/`DB_USER` default to `ethr_local_prod`/`ethr_localprod`, which nothing else
uses, and **`up.sh` refuses if either matches what `api/.env` holds.** That guard exists
because the defaults were `ethr`/`ethr` until 2026-09-28 and **broke this repository's
own dev environment**. Two ways, both silent:

- `CREATE DATABASE IF NOT EXISTS ethr` does not create anything when `ethr` already
  exists — it **adopts** it. The rehearsal then migrated into the dev schema, applying
  five pending migrations as a second batch.
- `ALTER USER 'ethr'@'127.0.0.1' IDENTIFIED BY '<rehearsal password>'` **changed the
  password `api/.env` authenticates with**, which is empty. Every subsequent dev command
  failed with `ERROR 1045 (28000) Access denied ... (using password: NO)` — a message that
  reads like a wrong password, not like something a setup script did.

The data was recovered and the grants restored, and the lesson is in the guard rather
than in a warning: **"create if not exists" is indistinguishable from "adopt whatever is
there", and a script that names a resource the developer also names will eventually take
it over.** So the rehearsal owns its own schema and its own user, and says so out loud in
step 2 rather than trusting the default.

One consequence worth knowing: two grant hosts are created, `127.0.0.1` **and**
`localhost`, because MariaDB treats them as different accounts — granting only one
produces that same misleading 1045 whenever a client resolves to the other.

---

## 2. What you can browse

`localhost:8081` is a **tenant** host, deliberately — that is the application you
actually want to look at. All of these return **200**, verified:

```
/  /login  /register  /dashboard  /employees  /employees/new  /attendance
/leave  /payroll  /reports  /settings  /organization  /directory  /devices
/shifts  /announcements  /approvals  /profile
/api/v1/health  /api/v1/plans  /api/v1/site-content  /api/v1/templates
```

**`/admin` returns 403 here, and that is correct** — it is the host boundary working.
The platform console lives on the admin host:

```bash
curl -H 'Host: admin.localhost:8081' http://localhost:8081/admin        # 200
```

To browse it, add `127.0.0.1 admin.localhost` to your hosts file and visit
`http://admin.localhost:8081/admin`. `localhost:8081` was **not** made the admin host
on purpose: it would then 302 every tenant route to `/admin/` and the app you want to
see would be unreachable.

---

## 3. Driving the scheduler by hand

There is no cron locally. The endpoints are the same ones production uses:

```bash
T=$(grep -m1 '^CRON_TOKEN=' .local-production/app/api/.env | cut -d= -f2)
curl -X POST -H "X-Cron-Token: $T" http://localhost:8081/api/v1/cron/schedule
curl -X POST -H "X-Cron-Token: $T" http://localhost:8081/api/v1/cron/queue
```

Verified: **404 without a token** (fail-closed, by design), **200 with it**, and
`/api/v1/health`'s `queue_detail` goes from *"scheduler has never run"* to `healthy`
with `problems: []`. Call **both** — eleven of the fourteen scheduled entries only
enqueue, so the scheduler alone fills the `jobs` table and drains nothing.

---

## 4. It runs without error — how that was checked

Not "the pages load". Measured after exercising all 22 routes above:

| Check | Result |
|---|---|
| `verify.sh` | **36 passed, 0 failed** |
| Every route above | **200** |
| Laravel log, `ERROR`+ | **0** |
| Apache `error.log`, non-notice | **0** |
| `migrate` | clean; both `audit_log` triggers created |
| Sibling routes vs the entity shell | **byte-identical to their own files** |

`up.sh` also refuses rather than lying: it fails if the port is taken, **if `DB_NAME` or
`DB_USER` is the one `api/.env` uses**, if the app user cannot connect, if `index.php`
does not take at least two rewrites, if the renderer omits a required directive, or if
the port opens but **nothing answers** — a wedged `httpd` holding a socket looks
identical to a healthy one from `netstat`, which is how this was reported "down" once
when it was merely broken.

---

## 5. Isolation and undo

**Untouched:** `api/.env` (your dev environment, and what the gates run against), the
database and user it names, `api/bootstrap/cache/` (a production `config:cache` there
would break `gates.sh`), XAMPP's own `httpd.conf`, and every database but
`ethr_local_prod`.

```bash
scripts/local-production/down.sh      # stops Apache; keeps everything else
rm -rf .local-production              # ~203 MB, gitignored

# only if you want the database gone too — this is the rehearsal's own, not your dev one
MY=/c/xampp/mysql/bin/mysql.exe
"$MY" -u root -e "DROP DATABASE ethr_local_prod;
                  DROP USER 'ethr_localprod'@'127.0.0.1';
                  DROP USER 'ethr_localprod'@'localhost';"
```

**Read that name before you run it.** Until 2026-09-28 this block named the plain `ethr`
database instead — the dev one, with every tenant and employee in it.

`.local-production/` is gitignored because it holds a generated `APP_KEY`, a database
password and a `CRON_TOKEN`. None of it is evidence about the host.

---

## 6. Knobs

All are environment variables on `up.sh`:

| | Default | |
|---|---|---|
| `PORT` | `8081` | it refuses rather than sharing a busy port |
| `ADMIN_HOST` | `admin.localhost:8081` | the platform console's host |
| `DB_NAME` `DB_USER` | `ethr_local_prod` `ethr_localprod` | dedicated to the rehearsal; it **refuses** either name if `api/.env` uses it |
| `DB_PASS` | `ethr_local_rehearsal` | local only; never a production value |
| `DB_HOST` `DB_PORT` | `127.0.0.1` `3306` | both grant hosts are created, so `localhost` also works |
| `XAMPP` | `/c/xampp` | |

`--no-build` skips the frontend build and reuses `src/out`.

---

## 7. What it cannot tell you

Stated because a green local run is the most tempting thing in this repository to
over-read:

- **G0-B.6 `AllowOverride Options`** — this harness grants `AllowOverride All`.
- **M1 `[F,L]`** — the deny rules are *correct as written*; whether Ethio Telecom's
  Apache honours them is unmeasured.
- **G0-F `CREATE TRIGGER`** — `migrate` succeeded against a `GRANT ALL` user, which is
  the shape Plesk grants by default. It says nothing about that account's grant.
- **G0-I** — MariaDB 10.4.32 here; the host's engine and version are unread.
- **`fpm-fcgi` behaviour** — this is CGI, one process per request. Request-level
  behaviour is exercised; process-lifecycle behaviour is not.
- **M6** — a restore rehearsal here is not a rehearsal on the host.

## Related

- [`CUTOVER-CHECKLIST.md`](CUTOVER-CHECKLIST.md) — the gate register. `CUTOVER READY = NO`
- [`shared-hosting/DEPLOYMENT.md`](shared-hosting/DEPLOYMENT.md) — the real procedure
- [`host-evidence/README.md`](host-evidence/README.md) — the host session
- [`../audit/BASELINE.md`](../audit/BASELINE.md) §21, §22 — what was measured and how
