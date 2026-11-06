# ETHR — Localization Guide (v2.0)

## Overview

ETHR is built for Ethiopian organizations. Localization is not an afterthought — it is a core architectural concern that affects the database schema, business logic, and every UI component.

---

## Languages

### Shipped with v1.0

| Language | Code | Status | Coverage |
|---|---|---|---|
| English | `en` | Complete | 38 backend files, 6,600+ frontend keys |
| አማርኛ (Amharic) | `am` | Complete | Matching EN |
| Afaan Oromoo (Oromo) | `om` | Complete since 2026-11-02 | Matching EN |

### Architecture Supports (v2.0+)

| Language | Code | Script |
|---|---|---|
| ትግርኛ (Tigrinya) | `ti` | Ethiopic |
| Soomaali (Somali) | `so` | Latin |
| Sidama | `sid` | Latin |
| Afar | `aa` | Latin |

### Translation File Structure

Every `en/*.php` file must have a corresponding `am/*.php` and `om/*.php` file with identical keys. `tests/Feature/LangParityTest.php` holds each translation to that, to the English `:placeholders`, and to not shipping English text.

```
/api/lang/
  /en/
    auth.php
    common.php
    validation.php
    employee.php
    attendance.php
    leave.php
    payroll.php
    shift.php
    device.php
    notification.php
    correction.php
    organization.php
    settings.php
    kiosk.php
    general.php
    dashboard.php
  /am/
    auth.php          (identical keys, Amharic values)
    common.php
    validation.php
    employee.php
    attendance.php
    leave.php
    payroll.php
    shift.php
    device.php
    notification.php
    correction.php
    organization.php
    settings.php
    kiosk.php
    general.php
    dashboard.php
```

### Translation Key Convention

Dot-notation, grouped by module:

```
employee.profile.title          → "Employee Profile"
employee.profile.title          → "የሰራተኛ መረጃ" (am)
attendance.status.present       → "Present"
attendance.status.present       → "ቀርቧል" (am)
payroll.calculation.basic_salary → "Basic Salary"
payroll.calculation.basic_salary → "መሰረታዊ ደመወዝ" (am)
```

### Translation Rules

1. **No hardcoded strings.** Every user-facing text uses a translation key.
2. **Every shipped language required.** A key without an Amharic or an Oromo translation is a build failure — `scripts/i18n-check.js` on the frontend (its `TRANSLATIONS` table lists each language and the values rightly identical to English), `LangParityTest` on the backend.
3. **One list of shipped languages.** `supportedLocales` in `src/src/lib/i18n/config.ts` (`status: "available"`) and `App\Support\Locales::SHIPPED`. Promoting a stub is its dictionary, its `lang/<code>/` files, and those two lists.
4. **Context matters.** Some English words translate differently depending on context (e.g., "leave" as absence vs "leave" as depart).
5. **Gender-aware.** Amharic has grammatical gender — use neutral forms where possible.

### Frontend i18n

ETHR uses its own hook, `useT` (`src/src/lib/i18n/useT.ts`), not `next-intl` or `react-i18next`. It returns `{ t, locale }`; `t` takes the full dot-notation key, an English fallback, and optional `:name` replacements:

```tsx
// Usage in components
const { t } = useT();
<h1>{t('employee.detail.tab.lifecycle', 'Lifecycle')}</h1>

// With variables
t('approvals.reject_description', 'This will be recorded and shown to :name.', { name: employee.name })
```

The language switcher offers only locales marked `available` in `src/src/lib/i18n/config.ts` — today `en`, `am` and `om`. The others are listed as `coming_soon` and cannot be selected.

Frontend translation files are one JSON dictionary per locale:
```
/src/src/lib/i18n/locales/
  en.json
  am.json
  om.json
  ti.json   (stub, coming soon)
  so.json   (stub, coming soon)
  sid.json  (stub, coming soon)
```

---

## Ethiopian Calendar

### Calendar System

Ethiopia uses the Ge'ez calendar (Ethiopian calendar) alongside the Gregorian calendar. The Ethiopian calendar:

- Has 13 months: 12 months of 30 days each + Pagumen (5 or 6 days)
- Is approximately 7-8 years behind the Gregorian calendar
- New Year (Enkutatash): Meskerem 1 = September 11 (or September 12 in Gregorian leap year)
- Does not observe Daylight Saving Time — UTC+3 is constant year-round

### Month Names

| # | Ethiopian | Gregorian Approximation |
|---|---|---|
| 1 | መስከረም (Meskerem) | Sep 11 – Oct 10 |
| 2 | ጥቅምት (Tikimt) | Oct 11 – Nov 9 |
| 3 | ህዳር (Hidar) | Nov 10 – Dec 9 |
| 4 | ታህሣሥ (Tahsas) | Dec 10 – Jan 8 |
| 5 | ጥር (Tir) | Jan 9 – Feb 7 |
| 6 | የካቲት (Yekatit) | Feb 8 – Mar 9 |
| 7 | መጋቢት (Megabit) | Mar 10 – Apr 8 |
| 8 | ሚያዝያ (Miazia) | Apr 9 – May 8 |
| 9 | ግንቦት (Ginbot) | May 9 – Jun 7 |
| 10 | ሰኔ (Sene) | Jun 8 – Jul 7 |
| 11 | ሐምሌ (Hamle) | Jul 8 – Aug 6 |
| 12 | ነሐሴ (Nehase) | Aug 7 – Sep 5 |
| 13 | ጳጉሜን (Pagumen) | Sep 6 – Sep 10 (or Sep 11) |

### Afaan Oromoo (`om`) — shipped 2026-11-02

Oromo names the Ethiopian months by season, mapped one-to-one onto Meskerem … Pagume:
Fulbaana, Onkololeessa, Sadaasa, Muddee, Amajjii, Guraandhala, Bitootessa, Ebla, Caamsaa,
Waxabajjii, Adoolessa, Hagayya, Qaammee. Both sides hold the list once:
`ETHIOPIAN_MONTHS_OM` (`src/src/lib/calendar/ethiopian.ts`) and `ReaderDates::MONTHS['om']`.

- **Clock.** `auto` is the Ethiopian clock for an Oromo reader on the Ethiopian calendar, as for
  Amharic. Periods: *ganama* (morning), *waaree booda* (afternoon), *galgala* (evening), *halkan*
  (night) — `1:30 ganama` is 07:30.
- **Gregorian dates** use the runtime's `om-ET` locale data (`Wiixata`, `Sadaasa 2026`).
  `intlLocale()` in `src/src/lib/i18n/config.ts` falls back to English where a browser's ICU lacks
  `om`, rather than letting it format in the device's own language.
- **E-mails** have built-in Oromo text (`subject_om`/`body_om` in `NotificationTemplates::DEFAULTS`).
  Organisations customise only English and Amharic; an Oromo reader gets the built-in Oromo, as an
  Amharic reader gets built-in Amharic when only English was edited.
- **Employment letters** can be requested in Oromo.

- **The public landing page** has Oromo twins (`heading_om`, `subheading_om`, `title_om`,
  `body_om`, `detail_om`, `image_alt_om` on `marketing_sections`/`marketing_items`), edited at
  `/admin/marketing` beside the Amharic. Built-in Oromo copy is in
  `api/database/seeders/assets/marketing/oromo.php`. `ProductionSeeder` writes it into a field only
  while that field is empty and the English beside it is still the seeded English, so a release
  gives `/om` its Oromo without ever pairing a translation with an operator's rewritten English.
  An empty Oromo field shows the English (`pickLocalised`).

**Writing Oromo strings (style rules, from the review of 2026-11-03).**

- **Address.** Anything addressed to the reader (a sentence, hint, description, message,
  e-mail) uses the polite plural, as the Amharic uses `-ዎ`: *isin*, *keessan*, verbs in
  *-tan*, imperatives in *-aa* (*galmeessaa*, *filadhaa*, *irra deebi'aa yaalaa*). Never *ati*,
  *kee*, *siif*. Short button, menu and column labels keep the bare form (*Olkaa'i*, *Haqi*).
- **Terms.** *raggaasisuu* = approve, *mirkaneessuu* = confirm/verify;
  *sakatta'iinsa* = review (examine), *ilaalcha waliigalaa* = overview; *liqii* = an employee
  loan, *kireedii* = account credit; *Dhibbeentaa* for a rate; *damee* = branch,
  *damee hojii* = sector; *gabatee herregaa* = spreadsheet; *biraawuzarii* = browser;
  *ashaaraa quba* = fingerprint; status labels are passive (*Raggaasifame*, *Didame*).
- **Spelling.** *icciitii*, *quunnamuu*/*quunnamtii* (but *walqunnamtii*, connection),
  *mallatteeffame*.
- **Clock times** in fixed text are written `06:00`, never "sa'aatii 6 ganama": on the
  Ethiopian clock that hour is 12 ganama, and the reader cannot tell which was meant.
- **Kept in English:** ETHR, plan names (Starter, Professional, Enterprise), Webhook(s),
  acronyms (QR, SMS, PIN, TIN, SSO). *Finfinnee* for Addis Ababa.
- **Changing shipped landing copy:** when a value in `oromo.php` changes after a release, add
  its earlier wording to `oromo-superseded.php`, so the next seed replaces it in production.

**Organisation data has no Oromo column, on purpose.** Departments, branches, positions, leave
types, holidays and shifts carry `name` plus `name_am` because Amharic needs a second script. Oromo
is written in the Latin script, so an Oromo-speaking organisation types Oromo into `name` itself,
which is what every reader sees. A third column would only help an organisation that keeps the
same name in English *and* Oromo *and* Amharic.

- **The platform's own labels and the plan catalogue** have Oromo twins too (since 2026-11-03):
  `platform_name_om`, `tagline_om`, `office_address_om`, `metric_uptime_note_om`,
  `testimonial_quote_om`, `testimonial_role_om`, `product_mark_label_om` and
  `payment_instructions_om` on `platform_settings`; `description_om` and `marketing_features_om` on
  `plans`. All are edited in the platform console beside the Amharic. `PlanSeeder` writes built-in
  Oromo for the three plans on the same rule as the landing page: only into an empty field whose
  English is still the seeded English.

- **An organisation's portal page** (`tenants.landing`, read through `TenantLanding`) takes Oromo
  beside English and Amharic for every text it holds: the headline, message, about, address and
  opening hours, and each question, answer, service and description. It is JSON, so no migration.
  A question or service needs its words in at least one of the three languages. The portal shows
  the reader's language, else English, else whichever language the organisation wrote
  (`pickText` in `src/src/lib/auth/landing-text.ts`), so text written only in Oromo still reaches
  an English reader.

### Dual Calendar Display

The organization's calendar is `settings.calendar` — `ethiopian` (the default) or `gregorian` — set at Settings → General → Calendar System. Each user can choose their own on Profile → Preferences, which overrides the organization's choice for them.

- Date inputs show the chosen calendar and the other calendar's date beneath (see *Date Picker Behavior*)

### CalendarService API

```php
// Backend (PHP)
CalendarService::toEthiopian(Carbon $gregorian): EthiopianDate
CalendarService::toGregorian(EthiopianDate $ethiopian): Carbon
CalendarService::formatEthiopian(Carbon $date): string
CalendarService::isLeapYear(int $ethiopianYear): bool
CalendarService::getPagumenDays(int $ethiopianYear): int  // 5 or 6
CalendarService::getMonthName(int $month, string $locale = 'am'): string
```

```typescript
// Frontend (TypeScript)
toEthiopian(gregorianDate: Date): EthiopianDate
toGregorian(ethiopianDate: EthiopianDate): Date
formatEthiopian(date: Date, locale: string): string
isEthiopianLeapYear(year: number): boolean
getPagumenDays(year: number): number
```

### Pagumen Handling

Pagumen (ጳጉሜን) is the 13th month — 5 days (or 6 in leap year). This creates special cases:

| Scenario | Handling |
|---|---|
| Payroll for Pagumen month | Configurable: `full_month` (treat as full salary) or `daily_rate` (annual / 365 × actual days) |
| Leave spanning Pagumen | Count Pagumen working days correctly (max 5-6 days) |
| Monthly accrual during Pagumen | Accrue proportionally if `daily_rate`, full accrual if `full_month` |
| Fiscal year ending at Pagumen | Carry-forward calculation triggered after Pagumen |

Tenant setting: `pagumen_proration_strategy` — `full_month` (default for government) or `daily_rate` (default for private sector).

---

## Ethiopian Holidays

### Auto-Detected Holidays (13)

| Holiday | Ethiopian Date | Type | Computation |
|---|---|---|---|
| Ethiopian New Year (Enkutatash) | Meskerem 1 | National | Fixed |
| Meskel (Finding of True Cross) | Meskerem 17 | Religious | Fixed |
| Timkat (Epiphany) | Tir 11 | Religious | Fixed |
| Adwa Victory Day | Yekatit 23 | National | Fixed |
| Good Friday (Siklet) | — | Religious | Computed (Easter tables) |
| Easter (Fasika) | — | Religious | Computed |
| Ethiopian Patriots Day | Miazia 27 | National | Fixed |
| International Labour Day | Miazia 23 / May 1 | National | Fixed |
| Downfall of the Derg | Ginbot 20 | National | Fixed |
| Genna (Christmas) | Tahsas 29 | Religious | Fixed |
| Eid al-Fitr | — | Religious | Computed (Islamic calendar, approximate) |
| Eid al-Adha | — | Religious | Computed (Islamic calendar, approximate) |
| Mawlid (Prophet's Birthday) | — | Religious | Computed (Islamic calendar, approximate) |

**Note:** Islamic holidays are approximate when auto-detected. The actual dates depend on moon sighting. Tenants can adjust the auto-detected dates.

### Holiday Integration Points

| Module | How Holidays Are Used |
|---|---|
| Leave | Holidays skipped when counting leave days |
| Attendance | Holiday flag on attendance records, holiday shifts |
| Payroll | Holiday overtime rate: 2.0x normal, 2.5x night |
| Calendar | Holiday columns highlighted in team calendar |
| Dashboard | Upcoming holidays shown on employee dashboard |

### Which year an auto-detect run covers

A run for a year produces the holidays **dated in that Gregorian year**, January to December. The fixed Ethiopian dates are placed in whichever of the two overlapping Ethiopian years falls inside it. So a 2026 run gives Genna on 7 January 2026, Adwa on 2 March 2026 and Enkutatash on 11 September 2026.

*(Corrected 2026-10-07, audit N57. A run used to cover the Ethiopian year beginning in September. Genna, Timkat and Adwa then landed in the next Gregorian year while Labour Day and Easter stayed in this one, so a tenant created in 2026 never had 2026's Genna, Timkat or Adwa. Attendance and payroll treated those days as working days.)*

Onboarding runs auto-detect for the current year and the next, so an organisation set up in October already has next January's holidays. After that, `RollForwardHolidaysJob` keeps the next year filled in (below).

### Branch-Specific Holidays

Some holidays apply only to one branch (e.g. a regional holiday). Each holiday row has a nullable `branch_id`:
- `null` = applies to all branches
- a branch = applies to that branch only, alongside the tenant-wide holidays

*(Corrected 2026-10-07: this described a `branch_scope` JSON list of branches, which does not exist. A regional holiday for several branches is one row per branch.)*

### Recurring Holidays

A holiday marked **Recurring every year** (`recurring = true`) is created for the next year by `RollForwardHolidaysJob`. The job runs on the 1st of every month for every tenant, and the run is idempotent.

- **Statutory holidays** for the next year come from auto-detect, so movable feasts (Easter, the Islamic holidays) get that year's computed date.
- **A tenant's own recurring holiday** is copied to the same month and day, if it is a Gregorian, non-estimated holiday. An Ethiopian-calendar or estimated holiday moves between Gregorian years by a rule a copy cannot know, so it is not copied by date. 29 February is skipped in a year without it.
- **Only the next year is touched, never the current one.** A holiday a tenant removed this year stays removed.

*(Corrected 2026-10-07, audit N58. This said "auto-created for new years by the yearly holiday generation job". There was no such job, and the flag was saved and read by nothing.)*

---

## Currency

### Ethiopian Birr (ETB)

| Aspect | Convention |
|---|---|
| Storage | `BIGINT` minor units (cents). Column suffix: `_cents` |
| Arithmetic | Integer only. No `FLOAT` or `DECIMAL` for money. |
| Rounding | Round half up at the final step only |
| Display format | `1,234.56 ETB` (comma thousands, period decimal) |
| Input | `CurrencyInput` component — accepts formatted input, stores integer cents |
| API | All amounts transmitted as integer cents |

### Formatting Helper

```php
// Backend
formatETB(int $cents): string
// formatETB(123456) → "1,234.56 ETB"
// formatETB(0) → "0.00 ETB"
// formatETB(-50000) → "-500.00 ETB"
```

```typescript
// Frontend
formatETB(cents: number): string
// Same behavior as backend
```

### Rules

1. **Never use `FLOAT` or `DECIMAL` for currency.** Period. Use `BIGINT` cents.
2. **Never divide before multiplying.** Compute `(salary_cents * rate) / 100`, not `(salary / 100) * rate`.
3. **Round only at the final step.** Intermediate calculations keep full integer precision.
4. **Assert no floats in payroll tests.** Pest tests should verify no float operations in the payroll pipeline.

---

## Phone Numbers

### Ethiopian Format

| Type | Format | Example |
|---|---|---|
| Local mobile | `09XX XXX XXXX` | `0911 234 567` |
| International | `+251 9XX XXX XXXX` | `+251 911 234 567` |
| Landline | `011 XXX XXXX` | `011 551 7700` |

### PhoneInput Component

- Accepts: `09...` or `+251...` format
- Stores: international format (`+2519XXXXXXXX`)
- Displays: formatted with spaces (`+251 911 234 567`)
- Validates: Ethiopian phone number regex
- Mobile: triggers numeric keypad (`inputMode="tel"`)

---

## Address

### Ethiopian Address Structure

Ethiopian addresses follow this hierarchy:

| Level | English | Amharic | Example |
|---|---|---|---|
| Region | Region | ክልል | Addis Ababa |
| City/Zone | City | ከተማ | Addis Ababa |
| Subcity | Subcity | ክፍለ ከተማ | Bole |
| Woreda | Woreda | ወረዳ | Woreda 03 |
| Kebele | Kebele | ቀበሌ | 01 |
| House # | House Number | የቤት ቁጥር | 123 |

### AddressForm Component

> **Not built.** There is no `AddressForm` component in the frontend. A branch's address is a single free-text field, and employee records have no address field. The list below is the intended design.

The `AddressForm` component would render these fields in the correct hierarchy:
- Region dropdown (Ethiopian regions + Addis Ababa + Dire Dawa)
- City/Zone text input
- Subcity text input
- Woreda text input
- Kebele text input
- Additional address (free text for house number, building name)

---

## Time Zone

### Ethiopia Does Not Observe DST

- Ethiopia uses East Africa Time (EAT): **UTC+3 constant, year-round**
- There is no daylight saving time adjustment — ever
- All timestamps stored in UTC in the database
- All timestamps displayed in EAT (UTC+3) in the UI
- Server-side: `Carbon::setTimezone('Africa/Addis_Ababa')`
- Client-side: format with `Africa/Addis_Ababa` timezone

### Common Pitfall

JavaScript's `Date` object uses the browser's local timezone. Always format dates with explicit timezone:

```typescript
const formatter = new Intl.DateTimeFormat('en-ET', {
  timeZone: 'Africa/Addis_Ababa',
  hour: '2-digit',
  minute: '2-digit',
});
```

---

## Fiscal Year

Ethiopian organizations use different fiscal year starts:

| Organization Type | Fiscal Year Start | Calendar |
|---|---|---|
| Government | Hamle 1 (July 8 Gregorian) | Ethiopian |
| Private sector | Meskerem 1 (Sep 11 Gregorian) or Jan 1 | Varies |

Configurable per tenant: `settings.fiscal_year_start_month` (1-13, where 13 = Pagumen), set by a Tenant Admin on the Payroll Schedule card at Settings → Payroll Rules.

Fiscal year affects:
- Leave balance accrual and carry-forward
- Payroll annual calculations
- Report date ranges
- Holiday generation year boundaries

---

## Date Display Conventions

| Context | Format (EN) | Format (AM) | Dual Calendar |
|---|---|---|---|
| Full date | July 15, 2026 | ሐምሌ 8, 2018 ዓ.ም. | July 15, 2026 / ሐምሌ 8, 2018 |
| Short date | Jul 15, 2026 | ሐም 8, 2018 | 07/15/2026 / 08/08/2018 |
| Date + time | Jul 15, 2026 10:30 AM | ሐም 8, 2018 10:30 ጥዋት | Both shown |
| Relative | 2 hours ago | ከ2 ሰዓት በፊት | — |
| Day of week | Monday | ሰኞ | — |

### Date Picker Behavior

Date inputs use `DualCalendarDateInput` (`src/src/components/shared/dual-calendar-date-input.tsx`), a single input — not two calendars side by side. Its value is always an ISO Gregorian `YYYY-MM-DD` string, whichever calendar is shown.

When the calendar system (Settings → General) is **Ethiopian**:
- The input is three parts: Ethiopian year, month (Meskerem … Pagume) and day
- The day list follows the month's length, so Pagume offers 5 or 6 days
- The Gregorian equivalent is shown beneath the input

When it is **Gregorian**:
- The browser's native date input
- The Ethiopian equivalent is shown beneath the input

---

## Working Days

Many Ethiopian organizations work **Monday through Saturday** (6 days); others (particularly banks and NGOs) work Monday through Friday (5 days). ETHR's default, until an organization sets its own, is **Monday through Friday**.

Configurable per tenant: `settings.working_days` — array of ISO day numbers, **1 = Monday … 7 = Sunday** (`api/app/Support/WorkingWeek.php`). An organization admin sets it on the **Working week** card at Settings → Leave Types.

Working days affect:
- Leave day counting (skip non-working days and holidays)

Attendance expectations come from each shift's own `working_days`, which use the same ISO 1–7 numbering.

---

## Localization Testing Checklist

### Translation Coverage
- [ ] Every `en/*.php` key exists in `am/*.php`
- [ ] No orphan keys in `am/` (keys not in `en/`)
- [ ] Variable placeholders match between languages (`{name}`, `{count}`)
- [ ] No English text in Amharic files (copy-paste errors)

### Calendar Correctness
- [ ] Ethiopian ↔ Gregorian conversion for standard dates
- [ ] Conversion around New Year boundary (Sep 10-12)
- [ ] Conversion during Pagumen (Sep 6-10)
- [ ] Leap year handling (Ethiopian and Gregorian)
- [ ] Holiday auto-detection produces correct dates for known years

### Currency
- [ ] `formatETB(0)` → `"0.00 ETB"`
- [ ] `formatETB(100)` → `"1.00 ETB"`
- [ ] `formatETB(123456789)` → `"1,234,567.89 ETB"`
- [ ] `formatETB(-5000)` → `"-50.00 ETB"`
- [ ] No float operations in payroll pipeline

### Typography
- [ ] Amharic renders in Noto Sans Ethiopic (not fallback font)
- [ ] Line height 1.6-1.8 for Amharic text
- [ ] DataTable columns accommodate Amharic width
- [ ] Truncation with ellipsis works for Amharic
- [ ] Search works with Amharic input
- [ ] Sorting works with Amharic text (utf8mb4_unicode_ci)

### UI
- [ ] Language switcher toggles all visible text
- [ ] Form labels switch language
- [ ] Validation error messages switch language
- [ ] Status badges switch language
- [ ] Empty states switch language
- [ ] Date pickers show correct calendar based on settings
