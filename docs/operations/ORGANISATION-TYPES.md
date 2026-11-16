# Organisation types

| At a glance | |
|---|---|
| **Purpose** | Say what changes when an organisation is one kind or industry rather than another, and what never does, so a matter that depends on it is answered from the code and not from a guess. |
| **Open it when** | A customer says the pension agency, the pension rate, the fiscal year or the starting setup is wrong for what they are, or you need to know or change what kind of organisation one is. |
| **For** | A platform operator or the owner, on the platform console, answering an organisation's administrator. |
| **Organisation types** | Varies by kind and industry. This is the document that says how. |
| **Source of truth** | `api/app/Models/OrganizationType.php`, `api/app/Services/Payroll/StatutoryRules.php`, `api/app/Services/Tenancy/OrganisationProfile.php`, `api/app/Support/OrganisationSectors.php`, `api/app/Services/Onboarding/IndustryCatalog.php`, `PolicyProfileCatalog.php` and `IndustryProfileResolver.php` in the same folder, and the migrations `2026_10_27_000002_*` and `2026_11_09_000002_*`. Where this page and those files disagree, the files are right. |

Written 2026-11-15 from the code. Until then nothing in this folder said that an organisation has a kind at all, although the product knows sixteen of them and the kind decides which pension scheme, and so which agency, a customer's payroll is filed under.

**What the code settles and what the law settles.** This page states what ETHR does. Whether a rule is the law is stated in [`STATUTORY-PARAMETERS.md`](../payroll/STATUTORY-PARAMETERS.md) (every value, its source and whether it is confirmed) and [`ACCOUNTANT-SIGN-OFF.md`](../payroll/ACCOUNTANT-SIGN-OFF.md) (what the accountant has yet to confirm). Where a point below is unconfirmed it says so. Do not tell a customer that an unconfirmed point is the law, or that it is not.

---

## 1. Two answers, never one

Every organisation answers two separate questions. They are separate columns, and neither is derived from the other.

| | Kind of organisation in law | Industry |
|---|---|---|
| **Column** | `tenants.organization_type` | `tenants.type` |
| **A value is** | A code from the catalogue of sixteen kinds (section 2) | An industry key or one of eight bases: government, ngo, university, hospital, bank, manufacturing, hotel, general |
| **Asks what** | Which law the staff fall under | What the organisation does, so setup can propose a sensible start |
| **Decides** | The statutory payroll rules (section 3) | The setup proposals and the attendance baseline (section 4). Never a statutory rule |
| **Asked at sign-up as** | "Kind of organisation in law" | "Organization Type" (the sign-up form's own spelling) |
| **Asked in the setup wizard as** | "Kind of organisation in law", on the Configure step | The industry chosen on the same step |
| **Settings, then General, card Organisation, field** | "Kind of organisation in law" | "Industry" |
| **History** | Dated: each change is an assignment with an effective date | One value, overwritten |

They are independent on purpose. A bank may be a private company or a public enterprise, a university may be a government office or private. `api/app/Support/OrganisationSectors.php` records which groups of kind each industry base plausibly is, and that narrows the lists a person sees:

| Industry base | Groups of kind suggested |
|---|---|
| government | public |
| ngo | non_profit, international |
| university | public, private, non_profit |
| hospital | private, public, non_profit |
| bank, manufacturing, hotel | private, public |
| general | every group |

The narrowing is a suggestion with a link to show every kind ("Show every kind of organisation in law" in Settings, "Show every option" in the wizard). It never hides a kind for good. An unusual pair is accepted and flagged for the administrator to confirm, never refused (owner decision 2026-11-12).

### The naming trap

The same word means different things in different places. This has misled people before.

| Where | What it calls the industry | What it calls the kind |
|---|---|---|
| Platform console, organisations list | Printed under the organisation's name, no heading | Not shown |
| Platform console, organisation page | "Type" | Not shown |
| Sign-up form | "Organization Type" | "Kind of organisation in law" |
| Sign-up request (`POST /auth/register`) | `organization_type` | `organization_kind` |
| `GET /settings`, the `organization` block | `type` | `organization_type` |
| `PUT /settings/organization` | `type` | `organization_type` |
| `GET /api/v1/organization-types` | Not about industries | The catalogue of kinds, despite the name |
| Database | `tenants.type` | `tenants.organization_type` |

The sign-up mapping is in `api/app/Services/AuthService.php:65-71`. Read "Type" in the console as the industry, always.

### Who sets and changes each

| | Kind | Industry |
|---|---|---|
| **Sign-up** | Chosen on the form. A client that sends none gets `government` for a government industry, `ngo` for an ngo industry, otherwise `private` (`OrganisationSectors::defaultKindFor`) | Chosen on the form. It must be an industry key or one of the bases, and is stored as given |
| **Setup wizard** | May be set on apply, which flags or clears the review flag (section 7, situation 3) | Set on apply. The wizard stores the industry's **base** in `tenants.type` and keeps the finer key (for example `ministry`) in the organisation's settings and as its sector |
| **Settings, then General** | Changed by anyone holding `settings.manage`. Dated and never retroactive once pay is approved (situation 2) | Changed by the same people, from the eight bases |
| **Platform console** | **Cannot be changed.** There is no route | **Cannot be changed.** There is no route |

---

## 2. The sixteen kinds

The catalogue is global, written by migrations, and has no editor in the console (section 11). A kind with a parent owns no rule rows of its own and resolves through its parent, one level deep (`OrganizationType::lineage()`).

| Group | Name as shown (English) | Code | Inherits from | Default pension scheme | Pension schedule goes to | Fiscal year starts | Starting policy profile | Pension coverage not yet confirmed |
|---|---|---|---|---|---|---|---|---|
| private | Private organisation | `private` | — | private | POESSA | Meskerem | ethiopia_private | — |
| private | Sole proprietor | `sole_proprietor` | `private` | private | POESSA | Meskerem | ethiopia_private | **Yes**, Next steps item |
| private | Private limited company (PLC) | `private_limited_company` | `private` | private | POESSA | Meskerem | ethiopia_private | — |
| private | Share company (S.C.) | `share_company` | `private` | private | POESSA | Meskerem | ethiopia_private | — |
| private | Partnership or joint venture | `partnership` | `private` | private | POESSA | Meskerem | ethiopia_private | — |
| private | Cooperative society | `cooperative` | `private` | private | POESSA | Meskerem | ethiopia_private | **Yes**, Next steps item |
| private | Branch of a foreign company | `foreign_company_branch` | `private` | private | POESSA | Meskerem | ethiopia_private | — |
| public | Public enterprise | `public_enterprise` | — | public | PSSSA, Civil Service Pension Fund | Meskerem | ethiopia_private | The scheme row is unconfirmed (research O1). No Next steps item |
| public | Government office (civil service) | `government` | — | public | PSSSA, Civil Service Pension Fund | **Hamle** | ethiopia_public | — |
| public | Regional or city government office | `regional_government` | `government` | public | PSSSA, Civil Service Pension Fund | **Hamle** | ethiopia_public | — |
| public | Defence or police | `defence_police` | — | public_military | PSSSA, Military and Police Service Pension Fund | Meskerem | ethiopia_public | — |
| non_profit | Charity or society | `ngo` | — | private | POESSA | Meskerem | ethiopia_private | — |
| non_profit | Foreign civil society organisation | `foreign_cso` | `ngo` | private | POESSA | Meskerem | ethiopia_private | — |
| non_profit | Religious organisation | `religious_organization` | `ngo` | private | POESSA | Meskerem | ethiopia_private | **Yes**, Next steps item |
| international | International organisation or embassy | `international_organization` | — | private | POESSA | Meskerem | ethiopia_private | **Yes**, Next steps item |
| international | Embassy or diplomatic mission | `diplomatic_mission` | `international_organization` | private | POESSA | Meskerem | ethiopia_private | **Yes**, Next steps item |

How to read it:

- **Default pension scheme** is the value the organisation gets unless it has its own (situation 1). An organisation may choose any of the three schemes whatever its kind: validation only requires the scheme to be one of them (`ValidatesPensionScheme.php`).
- **Pension schedule goes to.** The schedule's header names the agency of the scheme, in full, as POESSA (Private Organization Employees' Social Security Agency) or PSSSA (the Public Servants' Social Security Agency) with the fund. The scheme used is the one the payroll run logged, and for a run that logged none, the scheme in force for the organisation today, including its own choice (`api/app/Services/Payroll/StatutorySchedules.php:374-387`). So the agency follows the **scheme**, not the kind. The income tax schedule is always the Ministry of Revenues, whatever the kind. Every layout carries the note that it was prepared by ETHR and is to be confirmed with the agency before filing (owner action O3).
- **Rates by scheme**, employee then employer: private 7 and 11 percent (Proclamation 1268/2022, Art. 10), public 7 and 11 (1267/2022, Art. 10), public_military 7 and 33 (1267/2022, Art. 11). They depend on the scheme and on nothing else.
- **Fiscal year starts.** Meskerem is month 1, Hamle is month 11. The Hamle row for `government` is an ETHR convention, not confirmed. See the note in situation 6 on what it moves, which today is nothing.
- **Starting policy profile** is what the setup wizard starts from (section 3), not a payroll rule.
- **Pension coverage not yet confirmed.** `OrganizationType::COVERAGE_UNCONFIRMED` lists five kinds. Situation 4 says what that means.

The catalogue also holds, for each kind, an Amharic and an Afaan Oromoo name, a description, the group, and which industry bases the kind is suggested for. The Amharic names of the ten inheriting kinds and the Afaan Oromoo names are drafts awaiting a native reader (section 11).

---

## 3. What the kind changes

This is the complete list. If a behaviour is not here, the kind does not drive it.

| What | How the kind drives it | Today's difference between kinds |
|---|---|---|
| **Which statutory rule row applies** | `StatutoryRules` finds each value for the organisation's kind on the payroll date: a row for its sector, then for its kind, then for its parent kind, then the row for every kind. The organisation's own row then replaces it unless the rule is locked. No sector row is seeded | Only the three rows below |
| **`pension.default_scheme`** | Seeded per kind | private for every kind except `public_enterprise` and `government` (and so `regional_government`), which are public, and `defence_police`, which is public_military |
| **`fiscal_year.start_month`** | Seeded per kind | 1 (Meskerem) for every kind except `government` (and so `regional_government`), which is 11 (Hamle) |
| **`payroll.working_days_per_month`** | The six kinds without a parent each have a row | All 22, so no difference. The ten inheriting kinds resolve through their parent |
| **The pension agency on the schedule** | Follows the scheme, so follows the kind through the default (section 2) | As the scheme |
| **The setup wizard's starting policy profile** | A kind whose lineage contains `government` or `defence_police` starts from `ethiopia_public`, every other kind from `ethiopia_private`. The kind beats the industry's own suggestion, which stands only when no kind is known (`IndustryProfileResolver.php:77-80`) | See below |
| **The Next steps item for pension coverage** | Shown to people holding `settings.manage` for the five unconfirmed kinds (situation 4) | Five kinds |
| **The review flag** | Set against the kind, shown on the Settings card (situation 3) | — |

**What the two policy profiles hold** (`PolicyProfileCatalog.php`). Both propose annual, sick, maternity, paternity, marriage, bereavement and unpaid leave and the same three allowances (transport, housing, position), created switched off. `ethiopia_public` adds one leave type, study leave, and a different set of review notes for the administrator to read: it replaces the note about private leave with one saying civil-service leave values are ETHR's general defaults and must be confirmed against the law that applies, and adds one saying government salary scales and allowances are not loaded and must be entered before payroll. The proposal is what the wizard offers. Nothing is created until the administrator applies it, and an administrator may pick another profile.

**The fiscal year is not in the profile.** The profiles carry a fiscal year (private 1, public 11), but the resolver deletes it before anything is stored (`IndustryProfileResolver.php:230-247`) so the setup never writes an organisation's own value over its kind's default. The consequence is in situation 6: `defence_police` starts from the public profile and still has a Meskerem fiscal year, unless it sets its own.

**What the fiscal year moves.** A search of `api/app` for every reader of `fiscal_year.start_month` (2026-11-15) found two. The value is shown in Settings (`SettingsController.php`, the `payroll` block) and written into each payroll entry's calculation log as an input (`PayrollEngine.php`). Nothing reads it to calculate an amount. A wrong fiscal year therefore changes what the screen and the log say, not what anyone is paid. This may change when something starts reading it.

---

## 4. What the industry changes

Complete list. The industry never selects a statutory rule.

| What | How |
|---|---|
| **What the setup wizard proposes** | Departments, positions, an eight-step grade ladder with illustrative salary bands, allowances, leave types that belong to a sector rather than a law (`study`, `sabbatical`, `injury`, `r_and_r`), an employee number prefix (for example GOV, BNK, HSP), and the attendance method. The attendance method is biometric for government, bank, hospital, manufacturing and university, mobile for ngo and general, kiosk for hotel (`api/database/seeders/OrganizationTemplateSeeder.php`) |
| **The workforce profile**, and from it shifts, rotations and the working week | Set per industry key, for example hospital is continuous 24/7, manufacturing is rotating three shift, the government keys are standard office (`IndustryCatalog.php`) |
| **The attendance baseline** | At sign-up, and then hourly through `attendance:baseline`, an organisation that has no active shift is given its industry's usual shifts, rotations and working week. One that has any active shift is left alone, except that a lost default shift is restored. When the industry is unknown it gets office hours. The command's description also mentions holidays; the holiday set is the national calendar, and nothing in the code varies it by industry |
| **How serious an unassigned rotation looks** | The attendance health check "crews not assigned" is a blocker for the continuous 24/7 and rotating three shift profiles and a warning for every other (`AttendanceHealth.php`) |
| **Which report presets are suggested first** | Ordered, never restricted. Every preset stays available (`ReportPresets.php`) |
| **The default kind at sign-up** | Only when a client sends no kind (section 1) |
| **Which kinds are listed first** | The narrowing in section 1 |
| **What the console prints** | The industry, as "Type" |
| **The sector recorded with the kind** | The wizard stores the industry key as the sector of the dated assignment. Sign-up stores none. A statutory row can be scoped to a sector, but none is seeded, so the sector changes no rule today |

**Create-only.** Provisioning from the wizard adds what is missing and skips what an organisation already has: a record whose natural key exists is reported as skipped and left alone, and a settings key the organisation already holds is not overwritten (`OrganizationProvisioner.php`). Applying a profile again does not undo an administrator's edits. The exception is the organisation's own industry value, which the wizard writes every time.

### Industry keys by base

The base is what `tenants.type` normally holds. The key is what the wizard remembers.

| Base | Industry keys |
|---|---|
| government | federal_government, ministry, federal_agency, regional_government, regional_bureau, zonal_administration, city_administration, woreda_administration, sector_office |
| university | university, tvet, school |
| hospital | hospital, health_center |
| ngo | ngo |
| bank | bank, insurance, microfinance |
| manufacturing | manufacturing, construction, agriculture |
| hotel | hotel, restaurant, retail, wholesale |
| general | professional_services, logistics, transport, telecom, security_company, bpo, technology_company, small_business, multi_branch, custom |

The console's label map knows the eight bases and `private`: General company, Private company, Government, University / TVET, NGO, Bank / Finance, Hospital / Health, Manufacturing, Hotel / Hospitality. A value outside that map is shown as stored on the organisations list and with underscores replaced on the organisation page.

---

## 5. What neither changes

For each area below, the behaviour is the same whatever the kind or industry unless the row says otherwise. Go to the linked document for the area itself.

| Area | Differs by kind or industry? | Read |
|---|---|---|
| Scheduled jobs and commands | Same for every kind. The one exception is `attendance:baseline`, which reads the industry (section 4) | [`COMMANDS-AND-SCHEDULE.md`](COMMANDS-AND-SCHEDULE.md) |
| Leave year and carry-forward | Same for every kind. The leave year is `settings.leave_year`: Ethiopian for every new organisation, Gregorian if chosen (`api/app/Services/Leave/LeaveYear.php`). It is not the fiscal year (situation 5) | [`COMMANDS-AND-SCHEDULE.md`](COMMANDS-AND-SCHEDULE.md) |
| Plans, prices, the trial, invoices, dunning | Same for every kind. Every sign-up gets a six-month trial on the starter plan (`AuthService.php:73`) | [`PLATFORM-CONSOLE.md`](PLATFORM-CONSOLE.md) |
| Feature controls | Same for every kind (`api/app/Services/Features`) | [`PLATFORM-CONSOLE.md`](PLATFORM-CONSOLE.md) |
| Tenant journey stages, lifecycle and health reasons | Same for every kind. The readiness score reads the leave floors, which are type-aware in code, but every seeded floor is for every kind, so no score differs today | [`TENANT-JOURNEY.md`](TENANT-JOURNEY.md) |
| Deletion and restore | Same for every kind | [`TENANT-DELETION.md`](TENANT-DELETION.md) |
| Data export and backup | Same for every kind | [`DATA-EXPORT.md`](DATA-EXPORT.md) |
| Impersonation mechanics | Same for every kind. Impersonation refuses a change of the kind, see situation 8 | [`IMPERSONATION.md`](IMPERSONATION.md) |
| SMS | Same for every kind | [`SMS-DELIVERY.md`](SMS-DELIVERY.md) |
| The support desk | Same for every kind | [`SUPPORT.md`](SUPPORT.md) |
| Attendance selfie consent and retention | Same for every kind | [`ATTENDANCE-PHOTO-CONSENT.md`](ATTENDANCE-PHOTO-CONSENT.md), [`ATTENDANCE-PHOTO-RETENTION.md`](ATTENDANCE-PHOTO-RETENTION.md) |
| The organisation's public pages and the Trusted by list | Same for every kind | [`ORGANISATION-NEWS.md`](ORGANISATION-NEWS.md), [`ORGANISATION-FAQ.md`](ORGANISATION-FAQ.md), [`ORGANISATION-SERVICES.md`](ORGANISATION-SERVICES.md), [`TRUSTED-BY.md`](TRUSTED-BY.md) |
| The organisation's address | Same for every kind | [`ORGANISATION-ADDRESS-RENAME.md`](ORGANISATION-ADDRESS-RENAME.md) |
| Dashboard figures | Same for every kind. The definitions take neither the kind nor the industry. Their known limit, that the period attendance rate divides by weekdays, matters most to six-day and shift-based workforces, which in practice means some industries | [`METRIC-DEFINITIONS.md`](METRIC-DEFINITIONS.md) |
| Payslip layout, income tax schedule, income tax ladder | Same for every kind | [`STATUTORY-PARAMETERS.md`](../payroll/STATUTORY-PARAMETERS.md) |
| Hours, overtime floors, leave floors, sick-pay tiers, the deduction cap | Same for every kind: one row each | [`STATUTORY-PARAMETERS.md`](../payroll/STATUTORY-PARAMETERS.md) |
| **Pension scheme, agency and fiscal year** | **Differs by kind** (sections 2 and 3) | This page |
| **Setup proposals and the attendance baseline** | **Differs by industry** (section 4) | This page |

---

## 6. Finding out what kind an organisation is

The platform console does not show it. The organisations list and the organisation page carry the industry only. Neither response holds the kind, the review flag or a pending change. Search matches name and address only, and no filter or sort uses kind or industry (`AdminTenantController.php`). Use one of these.

| Way | How | Needs |
|---|---|---|
| **Ask the administrator** | They open Settings, then General, and read "Kind of organisation in law" on the Organisation card | Nothing. A reply on a help request ([`SUPPORT.md`](SUPPORT.md)) is the cleanest record |
| **Read it while impersonating** | The same card, as the administrator sees it. Look only | Platform MFA and a fresh code, see [`IMPERSONATION.md`](IMPERSONATION.md). Look only: a save of the kind is refused (situation 8) |
| **Read what payroll used** | In a payroll run, the calculator icon on an entry opens its calculation log, which carries the organisation type and, for each rule, its value, layer and source | Impersonation, or the administrator's screenshot |
| **See whether the pension scheme is the kind's or the organisation's own** | Settings, then Payroll Rules, then the Statutory rules tab, row "Your pension scheme". A badge "Your value" with "Default: ..." beneath means the organisation's own value, not the kind's. The line under the rule names the layer: "Set for your kind of organisation", "Set for the group your kind of organisation belongs to", "The law, for every organisation" or "Your organisation's own value" | Settings access. "Payroll Rules" is shown to tenant administrators |
| **Read the audit trail** | The organisation's own audit log records the kind at sign-up and at every change (section 9). The console's lists show action names and times only, last twenty, with no details, so they cannot tell you the kind | The organisation's administrator |
| **Ask engineering** | `tenants.organization_type` holds it, but on the day a scheduled change takes effect it lags until Settings, a save of the organisation card, or the setup wizard next reads the kind (section 11). To be sure, read the assignment in force on `tenant_profile_assignments` | A database read |

Since 2026-11-15 the Settings card shows the kind in force on the day a scheduled change takes effect, the same as payroll applies. For a pay question the calculation log is still the record of what was used.

---

## 7. Situations

### 1. "Our pension goes to the wrong agency", or "the rate is wrong"

**Why.** The agency and the rate follow the **scheme**. Three causes, most likely first:

1. **A pinned scheme from before 2026-10-27.** Every organisation that existed then was given the kind its settings suggested, flagged for review, and where it had no scheme or fiscal year set, had the private scheme and a Meskerem fiscal year written down as its own values (`2026_10_27_000002_*`, later converted to its own rule rows by `2026_11_09_000004_*`). The pin was deliberate: a guessed kind must not move anyone's pay. The result is that an old organisation guessed as `government` stays on the private scheme and POESSA until its administrator removes the pin. It does not follow its kind. An organisation registered after that date gets its kind's defaults.
2. **A scheme the organisation chose.** An administrator may pick any of the three schemes. Some do it on purpose, for example a public enterprise that is certain of its scheme.
3. **A wrong kind.** Check it (section 6) and see situation 2.

**What to do.**

1. Ask the administrator to open Settings, then Payroll Rules, then the Statutory rules tab, and read the row "Your pension scheme".
2. If it shows "Your value" with a line "Default: ..." that differs from what they expect, it is their own value. If they want the kind's default, they press "Reset to default". Needs `payroll.manageConfig`. The rule's own value is then removed, and the next run uses the kind's scheme.
3. To choose a scheme, they use Settings, then Payroll Rules, then the Payroll setup tab, card "Statutory filing", field "Pension scheme".
4. A pension schedule built from a run that was already approved keeps the scheme that run logged. Only later runs follow the change.
5. If the row shows no "Your value" and the scheme is still not what they expect, the kind is the cause. Go to situation 2.

Two things are not a fault. A kind whose scheme is not confirmed (situation 4, and `public_enterprise`) is paid as stated, by design. And the schedule's layout note, "prepared by ETHR; to be confirmed with the agency before filing", is on every pension schedule whatever the kind.

Resetting is a DELETE, so you cannot do it while impersonating. It is the administrator's decision in any case.

### 2. The kind is wrong

**Who changes it.** The organisation, in Settings, then General, card Organisation, field "Kind of organisation in law", then "Save organisation". It needs `settings.manage`. The console cannot do it.

There are two regimes, set by whether any payroll run has been **approved** (a run with status approved or paid):

| | Before any payroll is approved | After the first approved run |
|---|---|---|
| **What a change is** | A correction. The organisation always was that kind, its history is replaced and every period resolves to the new kind | A change from a date, never retroactive |
| **What the administrator does** | Picks the kind and saves | Picks the kind, fills "Applies from" and saves. The date must be the first day of an organisation month, never Pagume, after the last approved period and not before today. The field names the earliest date |
| **What an earlier period does** | Nothing is paid yet | Keeps the rules that were in force then |
| **What it looks like** | Saved | A line "Becomes (kind) on (date). Payroll before then keeps the current kind." with a link "Cancel this change" |

A newer scheduled change replaces an older one that has not started. "Cancel this change" withdraws the scheduled change and leaves the kind in force and all history alone. Saving the kind already held changes nothing and does not disturb a scheduled change.

**What to tell them.** If the change is after approved payroll, the new rules apply from the date they chose and not before. If a period was already paid on the wrong kind, a change of kind does not repair it: that is a payroll correction for their accountant, and a matter to raise with engineering before anyone edits records.

**Audit.** `settings.organization_updated` (with the kind before and after) and `organization_profile.assigned` (kind, sector, effective date). A cancellation is `organization_profile.change_cancelled`.

### 3. A banner asks the administrator to confirm the kind

**What they see.** On the Organisation card: "Confirm the kind of organisation below. Payroll applies the pension scheme and fiscal year set for it in law." and a button "Confirm".

**When the flag is set** (`tenants.organization_type_needs_review`):

- for every organisation that existed before 2026-10-27, because its kind was guessed;
- at sign-up, when the kind was not sent or the pair of industry and kind is unusual (`AuthService.php:70-71`);
- when the setup wizard applies a kind that is an unusual pair for the industry. The wizard also clears the flag when the pair is usual.

**How it clears.** "Confirm", or any save that carries the kind, even the kind already held, clears it. The Settings save does not re-check whether the pair is usual.

**What is blocked meanwhile.** Nothing. The flag is read only to show the banner. Payroll never reads it. For a legacy organisation that matters less than it seems: its values were pinned, so confirming a kind does not by itself move its pay (situation 1).

### 4. "Pension coverage for your kind of organisation is being confirmed"

**What they see.** A Next steps item with that title, the reason "Until the accountant confirms it, payroll applies the rules of the group your kind of organisation belongs to." and a button "Review organisation" that opens Settings. People holding `settings.manage` see it.

**Which kinds.** The five in `COVERAGE_UNCONFIRMED`, and what each is paid as meanwhile:

| Kind | Paid as | Meaning in practice |
|---|---|---|
| `sole_proprietor` | `private` | Private scheme |
| `cooperative` | `private` | Private scheme |
| `religious_organization` | `ngo`, which has no rows of its own | The rule for every kind, so the private scheme |
| `international_organization` | No parent | The rule for every kind, so the private scheme |
| `diplomatic_mission` | `international_organization` | The rule for every kind, so the private scheme |

**Why.** ETHR's research notes that the private pension proclamation (1268/2022, Art. 3(4)) excludes some employees: its research reading is that these include the employees of governmental international organisations and foreign diplomatic missions, and sole owners and their managers and employees. Whether it covers the staff of each of these five kinds, and what applies instead, is not confirmed. See [`STATUTORY-RULES-RESEARCH.md`](../payroll/STATUTORY-RULES-RESEARCH.md) (P9 and O2) and row 11 of [`ACCOUNTANT-SIGN-OFF.md`](../payroll/ACCOUNTANT-SIGN-OFF.md). ETHR pays them on the private scheme until it is decided, so nobody is left without a pension by default.

**What to do.** Nothing a platform operator can clear. It waits on the accountant, and then on a migration that changes the rule or removes the kind from the list. Do not tell the customer that the private scheme is right or wrong for them. Tell them the item is a notice, that nothing is blocked, and that ETHR will change the rule if the accountant's answer differs. The organisation may choose a different scheme itself (situation 1) if its own advisers are sure. `public_enterprise` is a related open point (research O1): it is paid on the public scheme, the row is unconfirmed, and it gets no Next steps item.

### 5. A government office expects leave to carry forward at Hamle

**Why.** The leave year and the fiscal year are two settings. A government office has a Hamle fiscal year by default and an Ethiopian leave year, which starts on Meskerem 1. Carry-forward runs on each of the first seven days of the organisation's **leave year** (1 January for a Gregorian one, Meskerem 1 for an Ethiopian one), at 01:00 UTC (`api/routes/console.php`). The fiscal year moves no leave.

**What to do.** Tell the administrator that carry-forward follows the leave year, which is its own setting, shown by `GET /settings` as `leave.leave_year`. If the organisation needs a different leave year, that is a decision about its leave policy, not a kind setting.

### 6. A defence or police organisation is on the public profile with a Meskerem fiscal year

**Why.** By design. `defence_police` starts from `ethiopia_public` because its staff are outside the Labour Proclamation, but the fiscal year never comes from the profile (section 3), and the only fiscal-year row by kind is the one for `government`. So `defence_police` has Meskerem.

**What to do.** If it matters, the administrator sets its own: Settings, then Payroll Rules, then the Payroll setup tab, card "Payroll schedule", field "Fiscal year starts". The field's hint reads "Meskerem for most private organisations; Hamle for government." Saving the card sends only what was changed. Remember what the fiscal year moves today (section 3): a displayed value and a log input, not an amount.

### 7. The kind an organisation needs is not in the list

**Why.** The catalogue is written by migrations only. There is no editor and no console route for it.

**What to do.**

1. Have the administrator choose the general kind of the nearest group meanwhile: `private`, `ngo`, `government`, `international_organization`, `public_enterprise` or `defence_police`.
2. A kind with a parent pays exactly as its parent today, so choosing the general kind of the group gives the same pay as a more specific kind would.
3. Raise it with engineering with the organisation's name, what it is in law, and the law that governs its staff. A new kind is a migration, and its pension coverage is an accountant question (section 11).

### 8. An operator needs to see the kind

Use the ways in section 6.

**You cannot save the kind while impersonating, and should not try.** Since 2026-11-15 impersonation refuses, with `403 Action Restricted` and no write, a save that carries the kind, a date for a change of it, the withdrawal of a scheduled change, or the pension scheme or another statutory setting, whether by Settings or by the setup wizard's apply (`api/app/Http/Middleware/BlockImpersonatedActions.php`, `blockedFields()`). The wizard always sends the kind, so an operator cannot apply the wizard while impersonating; the organisation's own administrator does. The wizard's preview records no choice and stays open. The kind decides which statutory rules pay that organisation's people, so a change of it is the organisation's own decision, made by its own administrator. Read it, close the session and, if it should change, tell the administrator what and why. You can still correct the organisation's name, industry, timezone or language for a customer who asks.

Resetting an organisation's own statutory value is refused too, as every `DELETE` is. One path is not covered: `POST /payroll/statutory-rules/use-law` ends the organisation's own tax ladder and pension rates, so payroll applies the law's from today, and it is not refused. It changes pay only for an organisation that still has such rates, and it cannot create them. Do not use it while impersonating; whether to refuse it is the owner's decision. See [`IMPERSONATION.md`](IMPERSONATION.md).

---

## 8. Messages ready to send

English only. An Amharic or Afaan Oromoo version needs a native reviewer before it is sent: the product's own translations of the kind and the policy names are drafts for the same reason (see [`AMHARIC-REVIEW.md`](../i18n/AMHARIC-REVIEW.md)). Replace the brackets.

**The pension agency or rate looks wrong**

> Hello [name]. Your pension schedule goes to the agency of the pension scheme your payroll uses. To see it, open Settings, then Payroll Rules, then the Statutory rules tab, and find "Your pension scheme". If it says "Your value", that scheme was set for your organisation, either when it first registered or by someone in your team. If you want the scheme that goes with your kind of organisation, press "Reset to default". If you are not sure which scheme your staff are covered by, ask your accountant before changing it. Runs you have already approved keep the scheme they were calculated with.

**Please confirm your kind of organisation**

> Hello [name]. In Settings, then General, the Organisation card asks you to confirm the kind of organisation in law. Please check that "Kind of organisation in law" matches how [organisation] is registered, then press "Confirm". Nothing is blocked while it waits. If the kind is wrong and you have already approved a payroll run, the change starts on the first day of a later month that you choose, and runs before it keep the old kind.

**What the pension coverage notice means**

> Hello [name]. The notice "Pension coverage for your kind of organisation is being confirmed" is information. ETHR has not yet had the accountant confirm which pension rules apply to [kind]. Until then payroll applies the private pension scheme, and nothing is blocked. We will change the rule if the answer is different, and your payroll before the change keeps what it used then. If you already know your staff are covered by another scheme, you may choose it under Settings, then Payroll Rules, then Payroll setup, "Statutory filing", "Pension scheme".

---

## 9. What the audit log records

| Action | Written when | Details | Where an operator sees it |
|---|---|---|---|
| `tenant.registered` | Sign-up | The administrator's email. **Not the kind** | Organisation page, "Recent privileged actions" (matches `tenant.%`) and "Recent Activity" |
| `organization_profile.assigned` | Sign-up, the wizard's apply, and every Settings save that carries a kind (before payroll is approved, even the kind already held) | The kind, the sector, the effective date | "Recent Activity" only, as an action name and a time |
| `organization_profile.change_cancelled` | "Cancel this change" | The kind and date withdrawn | "Recent Activity" only |
| `settings.organization_updated` | Any save of the Organisation card (name, industry, timezone, language or kind) | The kind before and after, when a kind was sent | "Recent privileged actions" (matches `settings.%`) and "Recent Activity" |
| `onboarding.configuration_applied` | The wizard's apply | The industry key, the profiles, what was created | "Recent Activity" only |
| `statutory_parameter.overridden` | A pension scheme, fiscal year, Pagume strategy or working-days value saved by the organisation | The code, the value before and after | "Recent Activity" only |
| `statutory_parameter.reset` | "Reset to default" | The code and the value removed | "Recent Activity" only |
| `settings.updated` | `PUT /settings`, which saves the Payroll setup cards | The fact only | "Recent privileged actions" and "Recent Activity" |

Both lists on the organisation page show the action name and the time, twenty entries at most, and no details. They cannot tell you which kind. The organisation's own administrators have Settings, then Audit Log. Anything done while impersonating also carries `impersonated_by`.

---

## 10. Checking the catalogue

`GET /api/v1/organization-types` on the platform's own address. No sign-in. Throttled as `public-catalogue` (`api/routes/api.php`, `OrganizationTypeController.php`). It is what the sign-up form, the setup wizard and Settings read, so if it is wrong or empty, those screens are.

It answers `{"data": [ ... ]}`: the **active** kinds in sort order, each with `code`, `name`, `name_am`, `name_om`, `description`, `group`, `parent_code`, `suggested_for` (the industry bases the kind is suggested for) and `policy_profile`.

**A healthy answer:**

| Check | Expected |
|---|---|
| Rows | Sixteen, in the order of section 2 (sort 10, 11, 12, 13, 14, 15, 16, 20, 30, 31, 40, 41, 42, 50, 51, 60) |
| Groups | private 7, public 4, non_profit 3, international 2 |
| `parent_code` set | Ten rows: the six private children, `foreign_cso`, `religious_organization`, `regional_government` and `diplomatic_mission` |
| `policy_profile` | `ethiopia_public` for `government`, `regional_government` and `defence_police`, `ethiopia_private` for the other thirteen |
| Names | `name` and `name_am` present on every row. `name_om` too, once migration `2026_11_15_000001_add_oromo_name_to_organization_types` has run |

A kind missing from the list has been switched off in the table (`is_active`), which also removes it from sign-up and Settings, and a save of it is refused as not valid. There is no console route to switch one back on. Raise it with engineering.

---

## 11. Not built, and open questions

Stated plainly, so nobody promises a customer what the product does not do.

- **The console does not show, filter or change the kind.** It shows the industry as "Type". Nothing filters or sorts by either. The list in section 6 is the workaround.
- **No editor for the catalogue.** A new kind or a changed rule is a migration.
- **No sector-specific statutory rule is seeded.** The sector is recorded and the resolver would use a sector row, but none exists.
- **Tax brackets and pension rules can be scoped to a kind. None are.** One ladder and the three scheme rates serve every kind.
- **Pension coverage is unconfirmed** for the five kinds in situation 4 and for `public_enterprise` (research O1, O2 in [`STATUTORY-RULES-RESEARCH.md`](../payroll/STATUTORY-RULES-RESEARCH.md); row 11 of [`ACCOUNTANT-SIGN-OFF.md`](../payroll/ACCOUNTANT-SIGN-OFF.md)). The Hamle fiscal year for `government` and the working-days figure are conventions, not confirmed.
- **Civil-service leave is not confirmed.** The setup wizard's review note for the public profile still cites Proclamation 1064/2017, which the research says 1353/2024 repealed (row 12 of the sign-off).
- **The Amharic names** of the ten inheriting kinds, and the Afaan Oromoo names of all sixteen, are drafts awaiting a native reader ([`AMHARIC-REVIEW.md`](../i18n/AMHARIC-REVIEW.md)).
- **Impersonation refuses a change of kind and of the statutory settings, but not `POST /payroll/statutory-rules/use-law`**, which ends the organisation's own saved tax ladder and pension rates (situation 8).
- **The fiscal year moves no amount** (section 3).
- **`tenants.organization_type` lags on the day a scheduled change takes effect, until something reads the kind.** Fixed for the Settings card on 2026-11-15: `GET /settings`, a save of the organisation card and the setup wizard's preview and apply bring the column into step with the assignment in force (`OrganisationProfile::inForce()`), so the card shows the new kind with no pending line and choosing it again is a no-op. No scheduled job writes it, because the production host has no worker. A reader that reads the column without going through those, such as a direct database read, can still see the old kind until an administrator opens Settings.

---

## 12. Related

| Document | For |
|---|---|
| [`STATUTORY-PARAMETERS.md`](../payroll/STATUTORY-PARAMETERS.md) | Every statutory value, its source, whether it is confirmed, and how a value is found |
| [`ACCOUNTANT-SIGN-OFF.md`](../payroll/ACCOUNTANT-SIGN-OFF.md) | What the accountant has yet to confirm |
| [`STATUTORY-RULES-RESEARCH.md`](../payroll/STATUTORY-RULES-RESEARCH.md) | The open legal questions behind the unconfirmed rows |
| [`AMHARIC-REVIEW.md`](../i18n/AMHARIC-REVIEW.md) | Which translations await a native reader |
| [`IMPERSONATION.md`](IMPERSONATION.md) | Looking inside an organisation, and what it refuses |
| [`TENANT-JOURNEY.md`](TENANT-JOURNEY.md) | Stages and health, the same for every kind |
| [`PLATFORM-CONSOLE.md`](PLATFORM-CONSOLE.md) | What the console shows and does |
| [`SUPPORT.md`](SUPPORT.md) | How a customer's question reaches you |
| [`COMMANDS-AND-SCHEDULE.md`](COMMANDS-AND-SCHEDULE.md) | `attendance:baseline` and the carry-forward schedule |
| [`README.md`](README.md) | The index of this folder |
