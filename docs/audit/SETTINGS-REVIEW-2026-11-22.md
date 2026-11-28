# Settings review, 2026-11-22

> **At a glance**
> - **What:** every tenant Settings page at `https://qa-audit.ethr.et/settings/`, checked on live against the docs and the code, rated, and turned into an upgrade plan.
> - **Live build:** `75d7e0f458` (`release.json`, built 2026-11-22 10:35Z). Local `main` is the same commit. `origin/main` is 3 ahead (#824, #826, #834, merged but not deployed).
> - **Method:** Chrome signed in as the QA Audit organisation admin, read-only (no Save pressed, no setting changed). Playwright (headless Chrome) signed out, GET only. `curl` for headers and timings. Code and docs read in this checkout.
> - **Verdict:** **7.0 / 10.** The pages work, load, and are well written and translated. One defect reaches the sign-in page (SSO), one write is guarded by a view permission (Accounting), and the area as a whole lacks search, deep links, unsaved-change protection and a few backend settings.
> - **Not verified:** saving any form, phone layout while signed in, Amharic and Afaan Oromoo rendering, and server timings without local CPU load (see *Limits*).

---

## 1. What was checked

| Route | Live result | API calls on open (warm) |
|---|---|---|
| `/settings` General (4 tabs) | Loads; all 12 fields labelled; one `h1` | `settings`, `organization-types` |
| `/settings/attendance` | Loads; 3 setup warnings shown; 19 switches all named | 4 calls, 270-336 ms |
| `/settings/leave-types` | 9 types; statutory defaults correct | 2 calls |
| `/settings/holidays` | 26 holidays, 2026 and 2027 | 1 call |
| `/settings/approval-chains` | Empty state, clear explainer | 3 calls |
| `/settings/exit-clearance` | 5 lines; balance checkbox works | 1 call |
| `/settings/payroll` | Schedule, statutory filing, rules, tax, pension, overtime | 4 calls + sidebar refetch |
| `/settings/opening-balances` | CSV templates for leave and loans | none |
| `/settings/users` | 3 users, owner card, reminders | 2 calls |
| `/settings/roles` | 1 custom role | 2 calls |
| `/settings/api-keys` | Loads (contents not recorded: they are keys) | 1 call |
| `/settings/webhooks` | Empty state | 1 call |
| `/settings/accounting` | 8 mapping rows | 2 calls |
| `/settings/notification-templates` | Templates with placeholders | 1 call |
| `/settings/scim` | Token, base URL, capabilities | none |
| `/settings/audit-logs` | Two charts and a 50-row table | 2 calls |
| `/settings/data-export` | Explainer, request button, history | 1 call |

No console error on any page. No horizontal overflow at 1280 px. After the first visit, every page settled in 0.3-1.2 s. The first visit (Attendance, cold, CPU at 100 %) took 7.7 s.

**Statutory values, cross-checked against `docs/payroll/STATUTORY-RULES-RESEARCH.md`:**
- Income tax ladder: 0/15/20/25/30/35 % bands with 300/500/850/1,350/2,050 ETB deductions, Proclamation 1395/2025. Matches T1 and T2.
- Pension: private and public 7 % employee + 11 % employer; military and police 7 % + 33 %. Matches P4.
- Overtime: 1.5 normal, 1.75 night, 2.0 rest day, 2.5 holiday. Matches Labour Proclamation 1156/2019 Art. 68.
- Leave types: annual 16, maternity 120, paternity 3, bereavement 3. Correct.

**Signed out (Playwright, desktop and Pixel 7):** `/settings/` and `/settings/payroll/` both end at `/login`. The only API call is `auth/me`, which returns 401. The page has `CSP`, `HSTS` (1 year, includeSubDomains), `X-Frame-Options: DENY`, `nosniff`, a strict referrer policy and `br` compression. About 940 KB to 1.1 MB of JavaScript is transferred. A cold first load took 12 s; a warm one took 0.9 s.

---

## 2. Findings

Severity follows the QA pack: S1 is data loss or a security breach, S4 is cosmetic.

| # | Sev | Finding | Evidence |
|---|---|---|---|
| F1 | **S2** | **Sign-in offers SSO that cannot work.** QA Audit's SSO switch is on, but the IdP entity ID and SSO URL are both empty. The public sign-in context still returns `sign_in.sso: true`, and the sign-in form offers SSO from that flag. The SSO controller itself refuses (`isConfigured()`), so anyone who tries the button fails. | Live `GET /api/v1/auth/tenant-context` → `{"sso":true,…}`. `login-form.tsx:472` (`offerSso = … tenantSignIn.sso`). `TenantContextController.php:58` checks `is_enabled` only; `SamlProvider::isConfigured()` (`SamlProvider.php:129-138`) checks all four. `UpdateSsoRequest` has no `required_if:is_enabled,true` on the IdP fields. The Settings card says "Enabled". |
| F2 | **S2** | **A view permission authorises a write.** Saving the chart of accounts needs only `payroll.viewAll`. The built-in **Finance Admin** holds that but not `payroll.manageConfig`, whose seeder comment says configuration "stays at tenant-admin". Finance Admin, and any custom role given "View all payroll data", can therefore rewrite the accounting export mapping. | `AccountingController.php:60-62`; `PermissionSeeder.php:131,141,321-333` |
| F3 | S4 | **The SCIM page sends people to support for something they can do themselves.** It says "To revoke a token, contact support." SCIM tokens are API keys with the `scim` ability: `GET /api-keys` lists them and `DELETE /api-keys/{id}` revokes them, for anyone with `apikey.manage`, whatever the plan. The SCIM page lists none of its own tokens. *(First written as S3, "cannot be listed or revoked". Corrected the same day after reading `ApiKeyController::index()` and `destroy()`.)* | `settings.scim_revoke_hint`; `api.php:999-1007`; `ApiKeyController.php:37-52,89-98` |
| F4 | S3 | **Payroll rules are gated on a role name, not a permission.** HR or finance with `payroll.manageConfig` cannot open the page; the backend checks permissions. | `payroll/page.tsx:25` `RoleGate minRole="tenant_admin"` |
| F5 | S3 | **Estimated holidays are not marked.** Eid al-Fitr, Eid al-Adha and Mawlid carry `is_estimated: true` in the API but the table shows nothing. This is O-3 from the 2026-11-17 pass, still open. Holidays stop at 2027-09-28, and nothing prompts for 2028. | Live `GET /api/v1/holidays` |
| F6 | S3 | **Notification templates have no Afaan Oromoo.** Only `subject_am` and `body_am` exist, although om shipped on 2026-11-02. | `UpdateNotificationTemplateRequest.php:22-24` |
| F7 | S3 | **Backend settings with no screen:** `password_policy`, `activation_code_hours`, `login_identifiers` (onboarding wizard only), SSO `idp_slo_url` and `attribute_mapping`. | `UpdateSettingsRequest.php:121-123`; `UpdateSsoRequest.php` |
| F8 | S3 | **Tabs do not deep-link.** `?tab=` is read but never written back, so a General, Branding, Security or SSO link cannot be shared and Back leaves the page. The hook's own docblock says the opposite (W-12). | `useQueryTab.ts:34-40` |
| F9 | S3 | **No settings search.** The command palette offers one "Settings" row; "SSO", "webhooks" or "tax" find nothing. | `command-palette.tsx`; `navigation/registry.ts:589-595` |
| F10 | S3 | **No unsaved-changes guard, no live save status** on any settings form (W-21, W-22, W-42). #801 and #802 do not touch settings. | no `useUnsavedChangesWarning` / `aria-live` under `settings/` |
| F11 | S3 | **The sidebar refetches `/dashboard/manager` on settings pages** to draw badge counts. It is the heaviest dashboard call (4.9-33 s under load today) and repeats every 5 minutes or on focus. | Live resource timings; `sidebar-nav.tsx`, `features/dashboard/api.ts:58-61` |
| F12 | S4 | **Copy:** "Organization, branding, and workspace configuration" (American spelling, `route-meta.ts:298`); sidebar "Pin to favorites: Organization"; default subject "Missing {punch_type} — {date}" uses an em dash; template names in title case ("Leave Requested"); the Users table shows "—" for an empty username. | Live page text |
| F13 | S4 | **Accessibility details:** the Users search box is named only by its placeholder; the five exit-checklist checkboxes are all named "Shows what is owed", with no line named; section titles on General are not headings (one `h1`, no `h2`). | Live DOM |
| F14 | S4 | **Locale list written by hand:** `in:en,am,om,ti,so` accepts two languages that do not ship (convention 9: `Locales::SHIPPED`). | `UpdateOrganizationRequest.php:32` |
| F15 | S4 | **Breadcrumb says "General settings" for every page** because `/settings` is both the hub and the General page. Data export reads "Admin › General settings › Data export". | `route-meta.ts:295-340` |
| F16 | S4 | **No plan or switch notice** on Payroll rules, Data export, SSO, or SCIM when the feature-control centre has turned `scim` off (it answers 503). | agent map, `ScimAuth` |
| F17 | Docs | `admin-guide.md:476-492` says only `settings.manage` holders see Settings; the nav deliberately shows HR and finance the pages their permissions allow (`settings-nav.tsx:67-73`, audit N91). The guide also omits Approval chains, Exit checklist, Opening balances, Display, Employee numbers, the public website, Statutory rules and Printed documents. | |

**Checked and fine:** `PUT /settings/sso` is guarded by `Gate::authorize('settings.manage')` (`SettingsController.php:328`), so F1 is not an authorisation hole. `SsoSetting` uses `BelongsToTenant`, so the `tenant-context` query is scoped. All 321 settings-area translation keys exist in am and om; only the 7 allowlisted ones match English.

---

## 3. Rating

Scores are out of 10. **Overall is the mean of the six cells, minus 1 for each S2 finding on the page**, rounded to the nearest half point.

| Page | Works | Backend matches UI | Accessibility | Copy and i18n | Docs | Tests | **Overall** |
|---|---|---|---|---|---|---|---|
| General: Organisation, Address, Display, Numbers | 9 | 8 | 7 | 8 | 6 | 9 | **8.0** |
| General: Branding and public website | 9 | 9 | 7 | 8 | 5 | 9 | **8.0** |
| General: Security | 8 | 5 (no password policy, identifiers, activation hours) | 8 | 9 | 6 | 8 | **7.5** |
| General: SSO | 4 (F1) | 5 (no SLO, mapping) | 8 | 8 | 6 | 8 | **5.5** |
| Attendance rules | 9 | 9 | 8 | 9 | 8 | 8 | **8.5** |
| Leave types | 9 | 9 | 8 | 9 | 8 | 8 | **8.5** |
| Holidays | 7 (F5) | 7 | 8 | 8 | 7 | 8 | **7.5** |
| Approval chains | 8 | 8 | 8 | 9 | 6 | 8 | **8.0** |
| Exit checklist | 8 | 8 | 6 (F13) | 8 | 4 (not in guide) | 5 (no Playwright) | **6.5** |
| Payroll rules | 9 | 8 | 8 | 8 | 7 | 9 | **8.0** (gate F4 noted) |
| Opening balances | 8 | 8 | 8 | 9 | 4 | 7 | **7.5** |
| Users & access | 8 | 8 | 7 (F13) | 7 (F12) | 7 | 8 | **7.5** |
| Roles & permissions | 8 | 8 | 8 | 8 | 7 | 8 | **8.0** |
| API keys | 8 | 8 | 7 (W-38, W-42) | 8 | 8 | 8 | **8.0** |
| Webhooks | 8 | 9 | 7 | 9 | 8 | 9 | **8.5** |
| Accounting | 8 | 5 (F2) | 8 | 9 | 6 | 7 | **6.0** |
| Notification templates | 8 | 6 (F6) | 8 | 6 (F12) | 7 | 7 | **7.0** |
| SCIM provisioning | 7 | 6 (F3, F16) | 7 | 7 (F3) | 7 | 8 | **7.0** |
| Audit log | 9 | 9 | 8 | 8 | 8 | 9 | **8.5** |
| Data export | 9 | 9 | 8 | 9 | 7 | 8 | **8.5** |
| **Settings area: navigation, search, deep links, unsaved changes** | 6 | n/a | 6 | 8 | 6 | 7 | **6.5** (mean of 5) |

*(Added 2026-11-28.)* The Attendance rules row was scored on a pass that tested no save and did not read the audit entry a save writes. A closer review of that one page found three S2 findings, one of them from the code alone, and is in [`ATTENDANCE-SETTINGS-REVIEW-2026-11-28.md`](ATTENDANCE-SETTINGS-REVIEW-2026-11-28.md), with its own plan. By this table's own rule (minus 1 for each S2) the row would read 5.5. The figures above are left as written.

**Overall: 7.0 / 10.** The 21 rows average 7.6, with a median of 8. This one figure is a judgement, not a mean: it is held below the average because F1 reaches every visitor to the sign-in page and F2 lets a built-in role change configuration it was designed not to touch. The cross-cutting gaps (F8-F11) are the rest.

---

## 4. Upgrade plan

Each step is one Sonnet-sized PR. Under the model rule, **steps marked Opus** get an Opus plan check before implementation and an Opus review after it, because they touch security or tenant access. Each PR runs `./scripts/gates.sh` before merge. A migration step is run on scratch MariaDB first (memory: *MariaDB migration check*).

### Wave 1: correctness and security (do first)

| PR | Fix | Files | Tests | Review |
|---|---|---|---|---|
| 1 | **SSO is "on" only when it can work** (F1). `UpdateSsoRequest`: `required_if:is_enabled,true` for `idp_entity_id`, `idp_sso_url`, and a certificate either stored or sent. `TenantContextController`: `sign_in.sso` from `SamlProvider::isConfigured()`. SSO card: "Not ready" state listing what is missing. QA Audit: the owner turns it off or completes it. | `UpdateSsoRequest.php`, `TenantContextController.php`, `sso-card` | Pest: enable without IdP → 422; half-configured tenant → `sso:false`. Vitest: "Not ready" state | **Opus** |
| 2 | **Accounting write needs `payroll.manageConfig`** (F2). Read stays on `payroll.viewAll`; the UI hides Save without the write permission. | `AccountingController.php`, `accounting/page.tsx` | Pest: viewer → 403 on PUT, 200 on GET | **Opus** |
| 3 | **SCIM page shows its own tokens** (F3). List the `scim` keys from the existing `GET /api-keys` (name, prefix, created, last used, expiry) and revoke through the existing `DELETE /api-keys/{id}`, shown to `apikey.manage` holders; others get a line pointing to who can. Replace the "contact support" hint. No new endpoint. | `scim/page.tsx`, `features/api-keys/api.ts`, locales | Vitest: list, revoke confirm, no-permission state | Sonnet |
| 4 | **Payroll rules gated on permission** (F4). Swap `RoleGate` for `can.managePayrollConfig`. Add plan and switch notices for Payroll, Data export, SSO and SCIM (F16). | `payroll/page.tsx`, `settings-nav.tsx`, `scim/page.tsx` | Vitest: nav-permissions cases | Sonnet |

### Wave 2: honest data and missing settings

| PR | Fix | Files | Tests |
|---|---|---|---|
| 5 | **Holidays:** an "Estimated" badge (en/am/om) when `is_estimated`. A notice when the last listed year ends within 90 days. Closes O-3. | `holidays/page.tsx`, locales | Vitest |
| 6 | **Notification templates in Afaan Oromoo** (F6): `subject_om` and `body_om` (migration, request, resource, UI), om fallback to English at send time. Default names in sentence case; the "Missing punch" subject loses its em dash (F12). | migration, request, `NotificationTemplateController`, `notification-templates/page.tsx` | Pest + Vitest; MariaDB scratch run |
| 7 | **Security tab completes** (F7): password policy, sign-in identifiers (moved from wizard-only), activation code lifetime. Each is added to `writableKeys()` with rules. | `UpdateSettingsRequest.php`, `settings/page.tsx` security tab | Pest: TenantSettingsValidationTest cases |
| 8 | **SSO advanced fields** (F7): SLO URL and attribute mapping, behind a "More options" disclosure. Depends on PR 1. | SSO card | Vitest + SsoSamlTest |
| 9 | **Locales from `Locales::SHIPPED`** (F14). | `UpdateOrganizationRequest.php` | Pest |

### Wave 3: the area as a whole

| PR | Fix | Files | Tests |
|---|---|---|---|
| 10 | **Deep-linked tabs** (F8): `useQueryTab` writes `?tab=` with `history.replaceState`; fix its docblock. Covers General and Payroll. | `useQueryTab.ts` | Vitest: tab change updates URL; Back leaves the page once |
| 11 | **Settings search** (F9): index every settings page, General tab and card title in the command palette, permission-filtered, in en/am/om. | `command-palette.tsx`, `settings-nav.tsx` | Vitest |
| 12 | **Unsaved changes and save status** (F10): one `useUnsavedChangesWarning` on every settings form, and an `aria-live` status region for "Saved" and errors. Closes W-21, W-22 and W-42 on settings. | shared form hook, settings cards | Vitest + `accessibility.spec.ts` |
| 13 | **Light badge counts** (F11): a small `GET /navigation/badges` (approvals, attendance) for the sidebar instead of `/dashboard/manager`. | new controller + route, `sidebar-nav.tsx` | Pest (tenant predicate), Vitest |
| 14 | **Copy and accessibility sweep** (F12, F13, F15): British spelling in route-meta and sidebar; no "—" in the Users table; a label on the Users search; checkbox names that include the line; `h2` section titles on General; breadcrumb parent "Settings" (not "General settings"). | route-meta, users, exit-clearance, General cards | Vitest; i18n gate |

### Wave 4: docs and proof

| PR | Fix |
|---|---|
| 15 | `admin-guide.md` settings chapter rewritten (F17). The QA pack gets cases for PRs 1-14. Exit checklist added to `ux-audit.spec.ts`. |
| 16 | **E2E gate before any deploy (model rule step 5):** the local Playwright suite (`npx playwright test`) and the signed-in production read pass (`e2e-production/signed-in.spec.ts`, QA Audit, a session the owner saves by hand) extended with one read per settings page. Report which journeys ran and which could not. |

**Order:** Wave 1 PRs 1-3 are independent and can run in parallel worktrees. PR 4 shares `settings-nav.tsx` with PR 11, so it merges first. PR 8 waits for PR 1. Deploy after each wave; PR 6 carries a migration, so it deploys with `migrate`.

---

## 5. Prerequisites before building

| Check | State | Needed before building |
|---|---|---|
| Live vs local | Live `75d7e0f458` = local `main` | `git pull` once nothing else is building here |
| Local vs origin | `main` 3 behind (#824, #826, #834) | pull, as above |
| Other work in this checkout | **A `next build`, `build-shared-hosting.mjs` and a `next dev` + `artisan serve` were running in `F:\ethr.et` during this pass, CPU at 100 %.** Not started by this review. | Let it finish; build each PR in its own worktree (mind the worktree junction trap) |
| GitHub over SSH | port 22 times out; fetch over HTTPS works | `insteadOf` HTTPS, or `ssh.github.com:443`, before pushing |
| Pre-push hook | `core.hooksPath` unset, so `gates.sh quick` does not run on push | `git config core.hooksPath .githooks` |
| Playwright browsers | `chromium_headless_shell-1254` missing; this pass used the installed Chrome (`channel: "chrome"`) | `npx playwright install chromium` in `src/` (needed for PR 16) |
| Toolchain | Node 24.15.0 (`.nvmrc` 24), PHP 8.3.36, Composer 2.9.8, `api/vendor`, `src/node_modules`, `api/.env` present, MariaDB running | none |
| Untracked files | root `package-lock.json` (empty, stray); `src/AGENTS.md` (written by `next dev`) | owner: delete, ignore or commit |
| Open PRs | #835 (auto-merge on), #801 and #802 (guideline fixes, not settings), #702, 8 Dependabot | none block settings work; #801 overlaps PR 12's inputs, so merge it first |
| Worktrees | 37 | none for this plan |
| Local docs gate | `gates.sh docs` red on this machine: 6 broken links, all in `.design\gtm\legacy\marketing-pack-2026-11-01\`. `.design/` is excluded in `.git/info/exclude`, so CI does not see it | `quick` runs the docs gate, so once `core.hooksPath` is set the pre-push hook will refuse pushes from this checkout until that folder's links are fixed or the gate skips it |
| Production writes | none in this pass | a QA Audit write pass (PR 16, saves) needs the owner's agreement that session |

---

## 6. Limits of this pass

- **No form was saved.** Every rating of "Works" means it loads, shows the stored values, and its controls respond. Saving is covered by the existing Vitest and Pest suites, not by this pass.
- **Phone layout while signed in was not seen.** Resizing the Chrome window did not take effect (it stayed at 1280 px). Playwright on a Pixel 7 profile reached only `/login`, which had no overflow.
- **Timings are confounded.** The local CPU was at 100 % (see §5). The 33-80 s API times in Chrome are mostly local. Server-side, `curl` saw 4.1 s to first byte for the 19 KB settings HTML, 2.3 s for `release.json` and 7.8 s for `/api/v1/plans`. That is slow for static files and worth measuring again from an unloaded machine (BASELINE §13e: no shared-host baseline exists).
- **The SSO button on QA Audit's sign-in page was not seen on screen** (that needs a signed-out window). F1 rests on the live `tenant-context` response and `login-form.tsx:472`.
- **Amharic and Afaan Oromoo were checked by key, not on screen.** Switching the interface language writes a user preference, which this read-only pass did not do.
- **The API keys page contents were not recorded**, since they are keys.
