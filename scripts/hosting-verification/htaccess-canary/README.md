# Web-server canary

Answers the one class of question the main probe structurally cannot.

`../ethr-hosting-check.php` lives in `~/` and is never served by Apache — deliberately, because it prints `disable_functions`, database grants and the filesystem layout. But *"is `.htaccess` honoured?"* can only be answered by something the web server actually serves.

This directory is the opposite trade: **it is web-reachable and discloses nothing.** Four booleans, no environment detail, no database, no writes, no mail.

## Files

| File | Purpose |
|---|---|
| `.htaccess` | The rules under test — rewrite, headers, deny, `Authorization` forwarding |
| `canary.php` | Reports what arrived, prints the three `curl` checks to run |
| `secret.txt.probe` | Bait. Must return **403**. Contains nothing confidential |

## Use

```
1. Upload all three to  httpdocs/ethr-canary/
2. Open                 https://<host>/ethr-canary/canary.php
3. Run the three curl commands it prints
4. Record results in    docs/deployment/GATE-0-RESULT.md  (G0-B rows)
5. DELETE THE DIRECTORY
```

Step 5 matters even though nothing here is secret: a stray `.htaccess` in a live document root is a configuration surprise waiting to happen.

## What each check means

| Check | Why ETHR cares |
|---|---|
| **G0-B.1** `mod_rewrite` | The deployment routes everything through a repointed `index.php`. Without it, nothing resolves. |
| **G0-B.2** `mod_headers` | `src/next.config.ts:33-77` defines CSP, HSTS, X-Frame-Options and Permissions-Policy. Serving the frontend statically drops them with the Node server; they must come from the web server instead. |
| **G0-B.3** deny rules | **The one that matters.** The deployment blocks `.env`, `.git`, `composer.json` and `storage/` by exactly this mechanism. |
| **G0-B.4** `Authorization` | Sanctum auth and the CSRF flow need `Authorization` and `X-XSRF-Token` forwarded to PHP. CGI/FastCGI strips them unless `.htaccess` restores them. |

## Why G0-B.3 is a deployment blocker

If Apache is not reading `.htaccess` — nginx-only, or `AllowOverride None` — every rule is ignored **silently**. No error, no log entry.

The failure mode is not a 500. It is `api/.env` becoming web-readable, carrying `APP_KEY`, the database password and `ETHIOTELECOM_SMS_PASSWORD`, while the application continues to work perfectly and nothing looks wrong.

So verify positively: request `/.env` on the real deployment and require **403**.

**A 404 is not a pass.** It means the file is not there yet — say, before the first deploy — and the same request will return 200 the moment it is.

If this fails, the fix is to translate every directive into Plesk → **Apache & nginx Settings → Additional nginx directives**, and not to deploy until `/.env` returns 403.
