# Custody — who holds what

**Names and places only. Never a value, a password, a token or an address in this file.**
Written 2026-10-14 because every credential below sat with one person and nothing said so:
if that person is unreachable, this table is how someone else finds out what they need and
whom to ask. Fill the *Holders* column with names and keep **at least two** per row — a row
with one holder is a single point of failure, and the review below exists to find those.

| Credential / access | Where it lives | Holders (≥ 2) | What breaks without it | Rotation |
|---|---|---|---|---|
| Plesk panel login (subscription) | Ethio Telecom hosting account | _owner_, _second_ | Deploys, `.env` edits, domains, certificates, databases | Panel → change password; update this row's date |
| Domain registrar / DNS zone for the apex domain | Registrar account | _owner_, _second_ | Custom domains, subdomain wildcard, mail records | Registrar's own procedure |
| GitHub organisation admin | GitHub | _owner_, _second_ | Rulesets, environments, secrets, merging | Add the second admin before you need them |
| GitHub `production` environment secrets (`ETHR_CRON_TOKEN`, `ETHR_CRON_BASE_URL`) | GitHub → Settings → Environments | _org admins_ | The scheduled caller (cron.yml) | Rotate with the host `CRON_TOKEN` and the second caller, together |
| Second cron caller account | The URL-fetch service chosen 2026-10-12 (cron-job.org) | _owner_, _second_ | Background work during quiet hours, **and the uptime monitor** (job *ETHR uptime (health)*, since 2026-10-16), whose alerts go to this account's email address | Same token as above. The monitor needs no token; check its email address stays a person who reads it |
| Host `.env` — `APP_KEY` | Host file `ethr/api/.env` + an offline copy (where: _fill_) | _owner_, _second_ | **Every encrypted column and every backup.** Never rotated | Never. Losing it loses the data |
| Host `.env` — database credentials | Host `.env`; Plesk → Databases | _owner_, _second_ | The whole application | Plesk → Databases → user; then `clear`/`optimize` |
| Host `.env` — mail account (`MAIL_USERNAME`/`MAIL_PASSWORD`, `MAIL_PEER_FINGERPRINT`) | Host `.env`; the provider's mail panel | _owner_, _second_ | Every email (resets, activation, notifications) | Provider's mail panel |
| Host `.env` — `CRON_TOKEN` | Host `.env` | _owner_, _second_ | Scheduler and queue calls (404) | With the GitHub secret and the second caller |
| Host `.env` — `MAINTENANCE_TOKEN` | Host `.env`, **empty between releases** | _whoever releases_ | Release tasks (migrate, seed, optimize) | Set for a release, emptied after |
| Platform super-admin accounts (with MFA) | ETHR console | _owner_, _second_ | The platform console | `ethr:reset-mfa` exists for a lost authenticator; needs a holder of the host |
| Backups | `storage/app/backups` on the host (+ off-host copy: _fill — none yet_) | _owner_, _second_ | Recovery after data loss | Not a secret; who can reach them is |
| Ethio Telecom support channel | Account contact on file | _owner_, _second_ | Host incidents, certificate renewal | — |

## Review

Every quarter, and whenever someone joins or leaves: read each row, confirm two named holders
can actually use it (sign in, not just "has the password somewhere"), and write the date here.

| Date | Reviewed by | Rows with fewer than two working holders |
|---|---|---|
| | | |

Related: [`ON-CALL.md`](ON-CALL.md) for what to do in an incident;
[`../ENGINEERING-ONBOARDING.md`](../ENGINEERING-ONBOARDING.md) for a new maintainer's first day.
