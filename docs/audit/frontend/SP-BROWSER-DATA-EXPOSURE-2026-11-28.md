# What the browser keeps: frontend data exposure, 2026-11-28

Slice SP of the frontend upgrade plan (`docs/audit/frontend/FRONTEND-UPGRADE-PLAN-2026-11-28.md`). It is a read of `src/` at `main@190fa0fd46`.

Authorisation is the server's job and is out of scope. This report asks a different question: **once the server has answered, what stays on the device, and who else can read it?** That covers storage, logs, URLs and memory.

## At a glance

- **E-1 (S2): the service worker keeps far more personal data than its own rules intend.**
  - It deliberately refuses to store admin, auth, payroll, report, bank-details and contract answers.
  - It does store everything else under `/api/v1`, for 7 days. That includes the organisation's data-export ZIP, the full employee CSV, the accounting export, attendance selfies, document and letter downloads, employee identities, and every search.
  - **On a shared office computer, these survive until a sign-out that reaches the cleanup.**
- **E-2 (S2): signing in to a second organisation in the same browser keeps the first one's stored answers.**
  - On `ethr.et/{slug}`, the organisation travels in the `X-Tenant` header, not the URL.
  - The API sends no `Vary: X-Tenant` on these answers.
  - So offline, organisation B's session can be shown organisation A's stored list for the same address.
  - Sign-in clears the query cache only, not the stored answers.
- **E-3 (S3): three exits skip the cleanup.**
  - Ending an impersonation.
  - "Sign out" on the forced password change.
  - `AuthGuard`'s redirect.
- **E-4 (S3): names stay in the browser after sign-out.**
  - Recent searches keep employee names, codes and positions.
  - Saved employee views keep their free-text search.
- **E-5 (S3): some secrets stay in memory after "Hide".**
  - The backup encryption keys, the two-factor secret, the kiosk token and device tokens remain in mutation results.
- **E-6 (S4): smaller items.**
  - Search text in the address bar and in Sentry breadcrumbs, if Sentry is ever switched on.
  - Salary, TIN, national ID and bank fields without `autoComplete="off"` and `spellCheck={false}`.
  - Stale impersonation flags.
- **Already right:**
  - Sign-out through the menu and an unrecoverable 401 clear the query cache, the stored API answers, the offline identity and the offline grants.
  - There is no persisted query cache.
  - `sendDefaultPii: false`, replay off, and a key-name scrubber.
  - One-time codes and starting passwords are dropped when their dialog closes.
  - The kiosk queue is sealed with RSA, so no PIN is readable on the device.
  - The offline identity holds no email, phone or photo.

**Every fix is frontend only.** `src/public/sw.js` and `src/src/` change. `api/` does not.

## E-1: what the service worker stores

**The rule today** is in `src/public/sw.js:37-39` and `neverCached()`, `:60-65`. Network-first applies to `/api/*`, and every `response.ok` is stored, stamped and served offline for up to 7 days (`API_MAX_AGE_MS`). The exclusions are:

```
NEVER_CACHED_API = ['/api/v1/admin/', '/api/v1/auth/', '/api/v1/payroll/', '/api/v1/reports/']
NEVER_CACHED_API_PARTS = ['/bank-details', '/contracts']
OFFLINE_PAY_API = ['/api/v1/payroll/payslips/my']   // allowed back in
```

**Stored, and should not be.** None of these is needed to punch in or to read your own day offline:

| Answer | Why it matters |
|---|---|
| `/api/v1/settings/data-export/{id}/download` | The organisation's whole data export, as a ZIP |
| `/api/v1/employees/export?search=…` | Every employee as CSV (`{csv, count}`) |
| `/api/v1/accounting/export/{run}` | The payroll journal: every person's pay as accounting lines |
| `/api/v1/attendance/photos/{id}/{which}` | Selfies. The React Query `gcTime: 0` at `features/attendance/api.ts:560-576` protects memory only |
| `/employees/{id}/documents`, `/profile/documents/{id}` (a signed `download_url`), `/profile/letters/{id}/pdf`, `/announcements/{id}/files/{file}`, `/billing/invoices/{id}/receipt` | Documents and their download links |
| `/employees/{id}/identities`, `/employees/possible-duplicates` | Identity numbers and matches |
| `/api/v1/search?q=…`, `/api/v1/employees?search=…` | Every search is its own cache entry, keyed by the text typed |

**Stored, and reasonable to keep:** the employee list and detail without a search, attendance for today, leave, the profile, and your own payslips. These back the offline pages the worker precaches (`/dashboard`, `/attendance`, `/leave`, `/payroll/payslips`).

**What `service-worker-api-cache.test.ts` pins today:** the admin, auth, payroll, bank and contract refusals; the 401 wipe; the 7-day expiry; and that `/employees` and `/attendance/today` are still stored. It pins none of the rows above.

## E-2: a second organisation in the same browser

- **What sign-in does.**
  - `login-form.tsx:233-253` writes `tenant`, then calls `queryClient.clear()`. The OTP form (`:152`, `:197`), the MFA form (`:69`) and the SSO return (`sso-return.tsx`) do the same or less.
  - None of them calls `clearApiCache()`, `clearOfflineIdentity()` or `clearOfflineOnSignOut()`.
- **Why the stored copy is not separated by organisation.**
  - The worker stores by request URL.
  - On the apex host, the organisation is the `X-Tenant` header (`api/client.ts:87-90`), not part of the URL.
  - `Cache.match()` honours `Vary`, but the API sends `Vary: Host, X-Tenant` only on the landing feed and tenant context endpoints (`LandingFeedController`, `TenantContextController`), not on data answers.
- **Effect.** Offline, organisation B's session on the same browser can be answered with organisation A's stored copy of the same address, until a network answer replaces it.
- **Who is exposed.** The case is narrow, but it is real for an accountant or HR consultant who serves several organisations from one office PC.
- **Fix.** Clear the stored API answers and the offline identity on every successful sign-in, not only on sign-out. A backend `Vary` would also fix it, but it is out of scope here.

## E-3: exits that skip the cleanup

| Exit | What it does today | What it should also do |
|---|---|---|
| End impersonation (`impersonation-banner.tsx:25-58`) | Restores `tenant` and removes the two flags | `clearApiCache()`, `clearOfflineIdentity()`. The operator was reading another organisation's data |
| "Sign out" on the forced password change (`change-password-form.tsx:81-90`) | `queryClient.clear()` | `clearApiCache()`, `clearOfflineIdentity()`, `clearOfflineOnSignOut()` |
| `AuthGuard` on 401 or 419 (`auth-guard.tsx:32-37`) | `clearOfflineIdentity()` and a soft redirect | `clearApiCache()` and `queryClient.clear()`. The axios 401 path usually runs first, but a 419 that fails twice does not reach it |

`useLogout` (`features/auth/api.ts:297-313`) and the axios 401 path (`api/client.ts:341-353`) do not remove `impersonating` and `original_tenant`. They should, so that a stale banner cannot return.

## E-4: names left in localStorage after sign-out

- **`ethr.search.recent.<user>`** (`components/shared/recent-searches.ts:53`): up to 5 entries. For each one:
  - the employee's name;
  - "code · position · department";
  - for leave, the person's name and dates.
- **`datatable:employees:views`** (`useSavedViews`): saved views, whose `search` can be a name, an email or a code.

**Fix.** Remove both on sign-out. Saved views are a preference, so keep the views but drop their free-text `search` on sign-out, or store them without it.

## E-5: secrets kept after the screen hides them

| Secret | Where it stays | Fix |
|---|---|---|
| Backup encryption key, S3 and Google Drive (`features/admin/backups.ts:174-180`, `:246-252`) | The mutation result, for the default 5 minutes after the page unmounts. "Hide" clears only local state | `gcTime: 0`, and `reset()` on Hide |
| Two-factor (TOTP) secret (`features/auth/api.ts:199-204`, `profile/security/page.tsx:190`, `:390`, `:445`) | The state and the mutation result after Cancel or closing the dialog | Clear `setupData` and `reset()` whenever the dialog closes |
| Kiosk token, device webhook token (`features/attendance/api.ts:696-723`, `features/devices/api.ts:233-247`) | The mutation result | `gcTime: 0` |
| API key, webhook secret, SCIM token (`settings/api-keys`, `webhooks`, `scim`) | The mutation `data` while the page stays open, after Dismiss. SCIM has no Dismiss | `reset()` on Dismiss, and a Dismiss for SCIM |

## E-6: smaller items

- **Search text in the address bar.** The employees, directory, users, tenants, devices and shifts lists put `?q=` in the URL, so a name typed into search sits in history.
  - This is a trade-off the plan accepted for shareable filters.
  - Keep it, but never put `q` in a URL that leaves the app.
- **Sentry, if a DSN is ever set.** `scrubEvent` redacts `request`, `extra` and `contexts` by key, but there is no `beforeBreadcrumb`. Navigation and fetch breadcrumbs would carry full URLs with `?q=` and `?search=`.
  - **Fix:** a `beforeBreadcrumb` that strips query strings from `data.url` and `data.to`/`from`, the way `recent-failures.ts` already does.
- **Sensitive fields.** Salary (`CurrencyInput`, contract, personnel, grade step), TIN, national ID and bank account inputs have no `autoComplete="off"` and no `spellCheck={false}`.
  - Some browsers send spell-checked text to a cloud service.
  - **Fix:** the WIG register already plans these (S10, W-26 and W-37), so no separate slice is needed.
- **Third-party links.** The GPS links to openstreetmap.org (integrity, visits) and the map picker's Nominatim search send coordinates or typed text to a third party when used. They are user-initiated and visible. Keep them, with `rel="noreferrer"`.
- **The bank transfer CSV** includes full account numbers, and the screen shows none. This is the server's authorisation decision, not exposure. It is noted for the owner, not fixed here.

## Fix order

One PR, frontend only, in this branch:

1. **E-1.** Extend `NEVER_CACHED_API` / `NEVER_CACHED_API_PARTS` to the table in E-1. Never store an answer to a request with a `search` or `q` parameter. Bump `CACHE_VERSION` so stored copies from earlier versions are dropped. Pin each row in `service-worker-api-cache.test.ts`.
2. **E-2 and E-3.** One `clearSessionData()` helper that clears:
   - the query cache;
   - the stored API answers;
   - the offline identity;
   - the offline grants;
   - the two impersonation flags;
   - recent searches.

   Call it on every sign-in, every sign-out and the impersonation exit.
3. **E-4 and E-5**, as in the tables above.
4. **E-6 Sentry:** `beforeBreadcrumb`.

The input metadata in E-6 stays with S10.
