# Release steps over HTTP: a token-guarded maintenance endpoint

> **OWNER DECIDED, 2026-10-09.** The install and release steps (`key:generate`, `migrate`,
> `db:seed --class=ProductionSeeder`, `ethr:create-admin`, and the caches) run through
> `POST /api/v1/maintenance/*`, guarded by `MAINTENANCE_TOKEN`. This **supersedes the
> artisan route** of [`OWNER-DECISION-LARAVEL-TOOLKIT.md`](OWNER-DECISION-LARAVEL-TOOLKIT.md).
> The document root `<APP_ROOT>/api/public` from that decision stands.

## What forced it

That decision chose the Laravel Toolkit and recorded that whether it ran artisan on this
account was **unverified** until someone tried. On 2026-10-09, with the document root at
`ethr/api/public` and `artisan` in `ethr/api`, the Toolkit's **Scan** answered *"Attached
0 application(s)"*, twice. No application, so no Artisan tab.

Every other way to run artisan was already closed:

| Route | Measured |
|---|---|
| SSH | Forbidden on the plan |
| Plesk Git deployment actions | A chroot with no PHP (2026-10-06) |
| Scheduled Tasks | The section does not exist (G0-D) |
| Laravel Toolkit | Attaches no application (2026-10-09) |
| PHP Composer extension | Runs composer, not artisan |

The database was empty (0 tables), so nothing could go live.

The 2026-10-06 decision had listed a token-guarded HTTP endpoint as the alternative, noting
that it needed a deliberate design of its own. This is that design.

## The design

| Property | How |
|---|---|
| Off unless switched on | `MAINTENANCE_TOKEN` unset, or shorter than 32 characters → **404**, the routes do not admit they exist |
| Its own secret | Not `CRON_TOKEN`, which sits in GitHub secrets and is sent every five minutes |
| Header only | `X-Maintenance-Token`. Never `?token=`, which would land in access logs |
| Brute-force brake | 5 requests a minute per address, counted **before** the comparison, in the **file** cache |
| Fixed tasks | `key`, `migrate`, `seed`, `optimize`, `clear`, `create-admin`. Each maps to arguments written in the controller, never to a command name from the request |
| `key` is one-shot | Refused (409) once `APP_KEY` is set: a second key makes every encrypted column unreadable |
| Secrets stay out | The generated key and the admin password are never echoed or logged |
| One at a time | A lock in the file cache; a second call gets 409 |
| Out of the API contract | `#[ExcludeRouteFromDocs]`, as for the cron routes |

**Why it sits outside the `api` group.** That group's `api-global` throttle counts in the
database cache, and its `cache` table does not exist until `migrate` — the step this exists
to run — has run. So every `/api` route answered 500 on the fresh install. The routes are
registered in `bootstrap/app.php` (`then:`), with `VerifyMaintenanceToken` as their only
middleware, and under `/api/v1/` because that is all `.htaccess` hands to Laravel.
`ApiRateLimitTest` pins this as the single exception, with its own brake.

## The cost, stated

These routes can migrate the production database and create a platform administrator from
the internet. Fail-closed and a 32-character secret make that safe while the token is set,
and harmless while it is not. **So set it for a release and empty it afterwards.** A token
left in `.env` permanently is a standing credential with that power.

## How to use it

[`deployment/PLESK-GO-LIVE.md`](../deployment/PLESK-GO-LIVE.md) Part 5.
