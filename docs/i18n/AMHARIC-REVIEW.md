# Amharic — native-speaker review list

On 2026-10-14 every Amharic text that was still English was translated, and two gates now
stop English from returning: `scripts/i18n-check.js` (frontend, `SAME_IN_BOTH` allowlist) and
`api/tests/Feature/LangParityTest.php` (backend). The translations below were written without
a native speaker. They are correct enough to ship — better than English for an Amharic
reader — and **must be reviewed by one** (plan owner action O7). Change the value in
`src/src/lib/i18n/locales/am.json` or the `api/lang/am/*.php` file; the gates check only
that a value exists and differs from English, never its wording.

## Backend

- `api/lang/am/validation.php` — **the whole file**: all framework validation messages
  (136 were missing and fell back to English) and the five custom-domain messages.
- `api/lang/am/auth.php` — `find_organisation.*` (6 lines).
- `api/lang/am/attendance.php` — `offline_not_own`, `offline_outside_window`.
- `api/lang/am/approval.php` — `leave`, `correction_summary`.
- `api/lang/am/device.php` — `read_failed`; `employee.php` — `supervisor_self`;
  `organization.php` — `department_parent_cycle`.

### Organisation types and statutory locks (2026-11-09)

Drafts written without a native speaker; a wrong label moves no money.

- `organization_types.name_am` for the ten types added by migration
  `2026_11_09_000002_add_lineage_to_organization_types`:
  `sole_proprietor` የግል ነጋዴ, `private_limited_company` ኃላፊነቱ የተወሰነ የግል ማኅበር,
  `share_company` አክሲዮን ማኅበር, `partnership` የሽርክና ማኅበር, `cooperative` የኅብረት ሥራ ማኅበር,
  `foreign_company_branch` የውጭ ኩባንያ ቅርንጫፍ, `foreign_cso` የውጭ ሲቪል ማኅበረሰብ ድርጅት,
  `religious_organization` የሃይማኖት ድርጅት, `regional_government` የክልል ወይም የከተማ መንግሥት
  መሥሪያ ቤት, `diplomatic_mission` ዲፕሎማቲክ ሚሲዮን. Correct them with a new migration that
  updates the rows, never by editing that one.
- `api/lang/am/payroll.php` — `tax_ladder_locked`, `pension_rule_locked`;
  `api/lang/am/validation.php` — `leave_below_statutory_floor`. The Afaan Oromoo
  (`api/lang/om/`) versions of the same three keys need the same review.

### Org-type-aware onboarding, frontend (2026-11-09)

108 keys drafted in Amharic and Afaan Oromoo without a native speaker, in
`src/src/lib/i18n/locales/{am,om}.json`: `auth.org_kind*`, `setup2.payroll_basics*`,
`setup2.staff_band*`, `setup2.pay_*`, `setup2.calendar*`, `setup2.org_kind`,
`statutory.pension_registration_*`, `statutory.payout_*`, `statutory.employer_bank_name`,
`statutory_rules.*`, `settings.org_kind_*`, `payroll_config.tab_*`,
`payroll_config.tax_brackets_locked`, `pension_rules.locked_description`,
`next_steps.payroll_ready_*`, and `next_steps.step.*` for `statutory_override`,
`setup_employer_tin`, `setup_tax_center`, `setup_pension_registration`,
`setup_payout_method`, `setup_employee_salaries`, `leave_below_law`,
`setup_device_linked` and `profile_legal_review`.

### Type-aware narrowing (2026-11-12)

5 keys drafted in Amharic and Afaan Oromoo without a native speaker:
`settings.org_kind_show_all`, `settings.org_kind_show_suggested`, `setup2.show_all_profiles`,
`setup2.show_typical_profiles` and `auth.org_type_required`.

The two sign-up errors, `auth.org_type_required` and `auth.org_kind_required`, read identically in Amharic and Afaan Oromoo. Each now repeats the wording of the label it sits under, so the legal-kind error carries ሕጋዊ and *seeraan* as its label does, and the industry error matches its own label; `settings.org_kind_show_all` in Afaan Oromoo now uses the label's *seeraan*. Drafted by a non-native reviewer from terms the locale already uses; a native speaker's reading is still owed.

### QA defects D-2 and D-3 (2026-11-14)

Drafted, not native-reviewed. The policy names in the setup wizard
(`PolicyProfileCatalog`) lost their em dash, which house style forbids, by
becoming one noun phrase instead of two parts:

| Where | English | Amharic |
|---|---|---|
| `ethiopia_private.label` | Ethiopian private sector | የኢትዮጵያ የግል ዘርፍ |
| `ethiopia_public.label` | Ethiopian public sector (civil service) | የኢትዮጵያ የመንግሥት ዘርፍ (ሲቪል ሰርቪስ) |
| `validation.attributes.branch_public_id` | branch | ቅርንጫፍ (Afaan Oromoo: damee) |

The attribute name reuses the word the branch field already shows
(`attendance.kiosks_page.branch`). The wizard's catalogues still carry no
Afaan Oromoo text at all; that is a wider gap than this fix.

### Dry-run payroll and future-dated kind changes (2026-11-09)

24 keys drafted in Amharic and Afaan Oromoo without a native speaker:
`payroll.preview*`, `payroll.income_tax`, `payroll.pension`, `payroll.pension_total`,
`payroll.employee`, and `settings.organization_kind_from`, `settings.organization_kind_from_help`,
`settings.organization_kind_pending`. Backend: `api/lang/{am,om}/validation.php` →
`organization_type_change_future`, `organization_type_change_month_start`.

### Second-reader review of the 2026-11-09 Amharic (2026-11-10)

The Amharic of the three 2026-11-09 sections above was read again, by a fluent but
**non-native** reviewer, against the terms the rest of `am.json` already uses. It is a second
draft, not the native sign-off this document asks for (O7). The Afaan Oromoo is in the next section.

**Terms aligned with the existing locale and the law's own Amharic names:**

| English | Now | Was | Why |
|---|---|---|---|
| rate (overtime, generic) | ተመን | መጣኔ | `am.json` uses ተመን; መጣኔ is a misspelling of ምጣኔ |
| tax rate | የግብር ምጣኔ | የግብር መጣኔ | the tax proclamations' term |
| pension contribution rate | የመዋጮ መጠን | የመዋጮ መጣኔ | `pension_rules.description` and Proclamation 1268/2022 |
| Labour Proclamation | የአሠሪና ሠራተኛ አዋጅ | ሠራተኛ አዋጅ | Proclamation 1156/2019's Amharic title |
| TIN | የግብር ከፋይ ቁጥር (TIN) | የግብር ቁጥር | `employees.missing.tin`, `next_steps.profile_item.tin` |
| preview | ቅድመ እይታ | በቅድሚያ ማየት | fourteen existing keys |
| payroll, as the thing that applies rules | የደመወዝ ስሌቱ | የደመወዝ ክፍያ | "the salary payment applies" read oddly |
| a setting's value | እሴት | ዋጋ | ዋጋ reads as *price* |

**Meaning corrected:**

| Key | Was | Problem |
|---|---|---|
| `setup2.staff_band_choose` | ክልል ይምረጡ | ክልል reads as *region*, not *range* |
| `payroll.preview_empty_hint` | ለጊዜው ደመወዝ ያላቸው | ለጊዜው means *for now*, not *for the period* |
| `validation.organization_type_change_future` (api) | ከኋላ ካለ ቀን | ከኋላ reads as *earlier*; the date is later |
| `settings.organization_kind_from_help` | ተፈቅዷል | *permitted*; approved pay is ጸድቋል |
| `next_steps.step.setup_pension_registration.reason`, `setup_tax_center.reason` | ይታወጃሉ / የሚታወጅበት | *proclaimed*; declared is ሪፖርት ይደረጋሉ / የሚያሳውቁበት |
| `next_steps.step.profile_legal_review.title` | በማረጋገጥ ላይ ነው | had no subject; now እየተረጋገጠ ነው |
| `setup2.pay_frequency` | ምን ያህል ጊዜ ይከፍላሉ | read as *how much time*; now በየስንት ጊዜው |
| `organization_types.diplomatic_mission` | ዲፕሎማቲክ ሚሲዮን | a transliteration that dropped *embassy*; now ኤምባሲ ወይም ዲፕሎማሲያዊ ሚሲዮን, by migration `2026_11_10_000001` |

`tax_ladder_locked` and `pension_rule_locked` (api) no longer attach a suffix to the
placeholder (`:reference ን`). Word order and wording were also smoothed in:
`auth.org_kind`, `setup2.org_kind`, `auth.org_kind_help`, `leave_below_law.*`, `setup_employee_salaries.*`,
`setup_employer_tin.*`, `setup_payout_method.reason`, `statutory_override.title`, `payroll_config.tax_brackets_locked`,
`pension_rules.locked_description`, `setup2.payroll_basics_hint`, `setup2.staff_band`,
`statutory.payout_cash`, `statutory_rules.*` (description, deduction cap, half pay, limited types, minimum rates),
`payroll.preview`, `payroll.preview_failed`, `payroll.preview_rules`, `payroll.preview_confirm`,
`settings.organization_kind_pending`, and `statutory_rule_not_resettable` (api).

### Afaan Oromoo consistency review (2026-11-10)

The Afaan Oromoo of the same three sections was read against the terms the rest of `om.json`
already uses. The reviewer reads Afaan Oromoo but is **not fluent**, so this aligns terms and
fixes words that plainly mean something else; it is not a review of naturalness, which still
needs a native speaker.

| English | Now | Was | Why |
|---|---|---|---|
| pension (genitive) | sooramaa | soorumaa | the stem is *soorama*; seven existing keys say *sooramaa* |
| pension scheme | sirna sooramaa | karoora soorumaa | *karoora* is a plan; `statutory.pension_scheme` and `settings.organization_kind_help` say *sirna* |
| overtime | sa'aatii dabalataa | yeroo dabalataa | 52 existing keys |
| overtime rates, contribution rates | gatiiwwan … | dhibbantaa … | `payroll_config.overtime_rates`, `pension_rules.description`; overtime rates are multipliers, not percentages |
| tax rate | dhibbeentaa gibiraa | dhibbantaa gibiraa | the spelling `payroll_config.rate_percent` uses |
| version | vershinii | gosa | *gosa* means *type*; `operations_page` says *vershinii* |
| preview | duraan ilaali | dursee / dursii ilaali | four existing keys; *dursii* is not a word |
| calendar | dhaha | dhaha guyyaa | `profile.calendar` |
| Labour Proclamation | Labsii Hojjetaa | Labsii Hojjettootaa | `payroll_config.overtime_rates_desc` |
| employer TIN | TIN qacaraa | lakkoofsa eenyummaa gibira kaffalaa hojjechiisaa | `employee.tin` is *TIN*; employer is *qacaraa* elsewhere in these keys |
| payroll, as what applies rules | shallaggii mindaa | kaffaltii mindaa | as in the Amharic, *the payment* applying rules read oddly |

`settings.organization_kind_pending` said the earlier pay *waits for* (*eega*) the current kind;
it now says it is *worked out under* it (*shallagama*). `settings.organization_kind_from` was a
fragment (*Kan hojii irra oolu irraa*); it is now *Guyyaa hojiirra oolu*. In the api,
`tax_ladder_locked`, `pension_rule_locked` and `statutory_rule_not_resettable` (`lang/om/payroll.php`)
take the same terms. 36 frontend keys and 3 api messages changed in all.

**Still for the native reviewer to decide:** Amharic pension *scheme* is ዕቅድ, as `statutory.pension_scheme`
already had it, though ዕቅድ is also the subscription *plan*; and the ten organisation-type names other
than `diplomatic_mission` read correctly against the Commercial Code's terms but were not checked by a
native speaker. In Afaan Oromoo, a setting's *value* is still *gatii*, which is also *price*
(`admin_plans.price`); no better established word was found in `om.json`.

### Nationality column help in the import guide (2026-11-15)

Drafted, not native-reviewed. The employee import guide had no line for the
`nationality` column; `employees_import_page.col_nationality` adds one. A
native reading is owed for both languages.

| Key | English | Amharic | Afaan Oromoo |
|---|---|---|---|
| `employees_import_page.col_nationality` | Country of citizenship, such as Ethiopian | የዜግነት አገር፣ ለምሳሌ ኢትዮጵያዊ | Biyyi lammummaa, fakkeenyaaf Itiyoophiyaa |

### Employee workbook group headings, example line and note (2026-11-15)

Drafted, not native-reviewed. `setup2.template_group_core`, `_person`, `_contact`, `_job`, `_pay` and
`_source` head the column groups on the template's Instructions sheet, `setup2.template_line_example`
points the reader to the Example sheet and the header notes, and `setup2.template_example_note` is the
line under the Example sheet. A native reading is owed for the am and om strings.

## Frontend (92 keys)

| key | English | Amharic (to review) |
|---|---|---|
| `admin.status.disabled` | Not in use | ጥቅም ላይ ያልዋለ |
| `announcements.confirm_delete_desc` | It is removed for everyone and cannot be recovered. | ለሁሉም ይወገዳል፤ መልሶ ማግኘት አይቻልም። |
| `announcements.confirm_delete_title` | Delete announcement? | ማስታወቂያው ይሰረዝ? |
| `attendance.conflicts.own_hint` | Your own attendance — someone else reviews this. | የራስዎ መገኘት ነው — ሌላ ሰው ይገመግመዋል። |
| `attendance.settings_page.require_pin_where` | Set each employee's PIN on their profile, under Attendance. | የእያንዳንዱን ሠራተኛ PIN በመገለጫው ላይ በመገኘት ስር ያስቀምጡ። |
| `auth.find_org_failed` | Something went wrong. Try again. | የሆነ ስህተት ተፈጥሯል። እንደገና ይሞክሩ። |
| `auth.find_org_link` | Find your organisation by email | ድርጅትዎን በኢሜይል ይፈልጉ |
| `auth.find_org_send` | Email me the link | መግቢያ አገናኙን በኢሜይል ላክልኝ |
| `auth.find_org_sending` | Sending… | በመላክ ላይ… |
| `auth.find_org_sent_body` | If that address belongs to an organisation, we've emailed you its sign-in link. | ያ አድራሻ የአንድ ድርጅት ከሆነ የመግቢያ አገናኙን በኢሜይል ልከንልዎታል። |
| `auth.find_org_sent_title` | Check your email | ኢሜይልዎን ይመልከቱ |
| `auth.find_org_subtitle` | Enter the email address you sign in with. We'll email you the sign-in link for each organisation it belongs to. | የሚገቡበትን ኢሜይል አድራሻ ያስገቡ። አድራሻው ለሚገኝበት ለእያንዳንዱ ድርጅት የመግቢያ አገናኙን በኢሜይል እንልክልዎታለን። |
| `auth.find_org_title` | Find your organisation | ድርጅትዎን ይፈልጉ |
| `auth.find_org_too_many` | Too many requests for this address. Try again in a few minutes. | ለዚህ አድራሻ ብዙ ጥያቄዎች ቀርበዋል። ከጥቂት ደቂቃዎች በኋላ እንደገና ይሞክሩ። |
| `auth.sso_return.account_inactive` | Your account is not active. Contact your administrator. | መለያዎ ንቁ አይደለም። አስተዳዳሪዎን ያነጋግሩ። |
| `auth.sso_return.back` | Back to sign in | ወደ መግቢያ ተመለስ |
| `auth.sso_return.failed` | Your organisation's sign-in service did not confirm who you are. Please try again. | የድርጅትዎ የመግቢያ አገልግሎት ማንነትዎን አላረጋገጠም። እባክዎ እንደገና ይሞክሩ። |
| `auth.sso_return.no_account` | There is no ETHR account for you in this organisation. Ask your administrator to invite you. | በዚህ ድርጅት ውስጥ የETHR መለያ የለዎትም። አስተዳዳሪዎ እንዲጋብዝዎ ይጠይቁ። |
| `auth.sso_return.not_configured` | Single sign-on is not set up for this organisation. Sign in with your password instead. | ለዚህ ድርጅት ነጠላ መግቢያ (SSO) አልተዘጋጀም። በይለፍ ቃልዎ ይግቡ። |
| `auth.sso_return.seat_limit` | Your organisation has reached its plan's employee limit, so a new account could not be created for you. Contact your administrator. | ድርጅትዎ የዕቅዱን የሠራተኛ ገደብ ስለደረሰ አዲስ መለያ ሊፈጠርልዎ አልቻለም። አስተዳዳሪዎን ያነጋግሩ። |
| `auth.sso_return.signing_in` | Signing you in… | በማስገባት ላይ… |
| `auth.sso_return.tenant_inactive` | This organisation's account is not active. | የዚህ ድርጅት መለያ ንቁ አይደለም። |
| `auth.sso_return.title` | Single sign-on did not complete | ነጠላ መግቢያ (SSO) አልተጠናቀቀም |
| `billing.awaiting_confirmation` | Awaiting payment confirmation | የክፍያ ማረጋገጫ በመጠበቅ ላይ |
| `billing.bank_placeholder` | Commercial Bank of Ethiopia | የኢትዮጵያ ንግድ ባንክ |
| `billing.receipt_failed` | Couldn't download the receipt | ደረሰኙን ማውረድ አልተቻለም |
| `command.palette_title` | Command palette | የትዕዛዝ ማውጫ |
| `dashboard.leave_usage_aria` | :type leave: :pct% used | :type ፈቃድ፦ :pct% ጥቅም ላይ ውሏል |
| `employee.cost_center` | Cost centre | የወጪ ማዕከል |
| `employee.detail.nationality_placeholder` | Ethiopian | ኢትዮጵያዊ |
| `employee.education.field_of_study_placeholder` | Computer Science... | ኮምፒውተር ሳይንስ… |
| `employee.education.gpa` | GPA | አማካይ ውጤት (GPA) |
| `employee.emergency.relationship_placeholder` | Spouse, Parent... | የትዳር ጓደኛ፣ ወላጅ… |
| `employee.lifecycle.reason_placeholder` | Optional — context for the transition | አማራጭ — የለውጡ ምክንያት |
| `employee.supervisor` | Supervisor | ተቆጣጣሪ |
| `employee.team` | Team | ቡድን |
| `error.unexpected` | An unexpected error occurred | ያልተጠበቀ ስህተት ተፈጥሯል |
| `holidays_page.delete_desc` | Leave and attendance stop treating this day as a holiday. | ፈቃድና መገኘት ይህንን ቀን እንደ በዓል መቁጠር ያቆማሉ። |
| `holidays_page.delete_title` | Delete this holiday? | ይህ በዓል ይሰረዝ? |
| `holidays_page.edit_holiday` | Edit holiday | በዓሉን አርትዕ |
| `holidays_page.update_failed` | Couldn't update the holiday | በዓሉን ማዘመን አልተቻለም |
| `holidays_page.updated` | Holiday updated | በዓሉ ተዘምኗል |
| `kiosk_pin.change` | Change PIN | PIN ቀይር |
| `kiosk_pin.description` | The employee enters this at a kiosk when your organisation requires a PIN. Tell it to them in person; it cannot be shown again. | ድርጅትዎ PIN ሲጠይቅ ሠራተኛው ይህንን በኪዮስክ ላይ ያስገባል። በአካል ይንገሩት፤ ዳግም ሊታይ አይችልም። |
| `kiosk_pin.failed` | Couldn't save the PIN | PIN ማስቀመጥ አልተቻለም |
| `kiosk_pin.field` | New PIN | አዲስ PIN |
| `kiosk_pin.hint` | Four to six digits. | ከአራት እስከ ስድስት አሃዞች። |
| `kiosk_pin.is_set` | Set | ተቀምጧል |
| `kiosk_pin.not_set` | Not set | አልተቀመጠም |
| `kiosk_pin.remove` | Remove | አስወግድ |
| `kiosk_pin.removed` | Kiosk PIN removed | የኪዮስክ PIN ተወግዷል |
| `kiosk_pin.save` | Save PIN | PIN አስቀምጥ |
| `kiosk_pin.saved` | Kiosk PIN saved | የኪዮስክ PIN ተቀምጧል |
| `kiosk_pin.set` | Set PIN | PIN አስቀምጥ |
| `kiosk_pin.title` | Kiosk PIN | የኪዮስክ PIN |
| `leave_types_page.week_save_failed` | Couldn't save the working week | የሥራ ሳምንቱን ማስቀመጥ አልተቻለም |
| `leave_types_page.week_saved` | Working week saved | የሥራ ሳምንቱ ተቀምጧል |
| `leave_types_page.working_week` | Working week | የሥራ ሳምንት |
| `leave_types_page.working_week_admin_only` | Only an organisation admin can change the working week. | የሥራ ሳምንቱን መቀየር የሚችለው የድርጅት አስተዳዳሪ ብቻ ነው። |
| `leave_types_page.working_week_desc` | The days a leave request counts. Holidays are not counted. | የፈቃድ ጥያቄ የሚቆጥራቸው ቀናት። በዓላት አይቆጠሩም። |
| `offline.page_body` | No internet connection. Check your network and try again. Attendance you record offline will sync automatically when you reconnect. | የበይነመረብ ግንኙነት የለም። አውታረ መረብዎን ይፈትሹና እንደገና ይሞክሩ። ከመስመር ውጪ የመዘገቡት መገኘት ሲገናኙ በራሱ ይመሳሰላል። |
| `offline.page_title` | You are offline | ከመስመር ውጪ ነዎት |
| `org.field.grade_name_placeholder` | G1, Manager I, Level 5... | G1፣ ሥራ አስኪያጅ I፣ ደረጃ 5… |
| `payroll_config.fiscal_year_start` | Fiscal year starts | የበጀት ዓመቱ የሚጀምርበት |
| `payroll_config.fiscal_year_start_help` | Meskerem for most private organisations; Hamle for government. | ለአብዛኞቹ የግል ድርጅቶች መስከረም፤ ለመንግሥት ሐምሌ። |
| `payroll_config.pagumen` | Pagume pay | የጳጉሜ ክፍያ |
| `payroll_config.pagumen_daily_rate` | Its days at the daily rate | ቀናቱ በዕለታዊ ተመን |
| `payroll_config.pagumen_full_month` | A full month | ሙሉ ወር |
| `payroll_config.pagumen_help` | How the 5 or 6 days of Pagume are paid. | የጳጉሜ 5 ወይም 6 ቀናት እንዴት እንደሚከፈሉ። |
| `payroll_config.retirement_age` | Retirement age | የጡረታ ዕድሜ |
| `payroll_config.retirement_age_help` | Retirement cases are dated from this age. | የጡረታ ጉዳዮች ቀን የሚቆጠረው ከዚህ ዕድሜ ነው። |
| `payroll_page.payslips_page.download_failed` | Couldn't download the payslip | የደመወዝ ወረቀቱን ማውረድ አልተቻለም |
| `plan_feature.not_in_plan` | This is not included in your organisation's plan. | ይህ በድርጅትዎ ዕቅድ ውስጥ አልተካተተም። |
| `plan_feature.see_plans` | See plans | ዕቅዶችን ይመልከቱ |
| `reports_page.truncated` | Only the newest 1,000 rows are shown. Narrow the dates to see the rest. | የሚታዩት አዲሶቹ 1,000 ረድፎች ብቻ ናቸው። ቀሪውን ለማየት ቀኖቹን ያጥብቡ። |
| `roles_page.org_scope` | Whose records | የማን መዝገቦች |
| `roles_page.org_scope_hint` | The employees a holder of this role can see and act on. | ይህ ሚና ያለው ሰው ማየትና መሥራት የሚችልባቸው ሠራተኞች። |
| `roles_page.scope_all` | Everyone in the organisation | በድርጅቱ ውስጥ ያሉ ሁሉ |
| `roles_page.scope_branch` | Their branch | የራሳቸው ቅርንጫፍ |
| `roles_page.scope_department` | Their department | የራሳቸው የሥራ ክፍል |
| `roles_page.scope_direct_reports` | Their direct reports | በቀጥታ የሚመሩዋቸው |
| `roles_page.scope_self` | Their own only | የራሳቸው ብቻ |
| `roles_page.scope_team` | Their team | የራሳቸው ቡድን |
| `table.error_loading` | Something went wrong loading this data. | ይህን መረጃ በመጫን ላይ ስህተት ተፈጥሯል። |
| `time.week_short` | w | ሳ |
| `users_page.mfa_on` | 2FA | ባለሁለት ደረጃ |
| `users_page.search` | Search by name or email | በስም ወይም በኢሜይል ይፈልጉ |
| `webhooks_page.active_toggle` | Deliver to this webhook | ወደዚህ ዌብሁክ ላክ |
| `webhooks_page.disabled` | Webhook disabled | ዌብሁኩ ተሰናክሏል |
| `webhooks_page.edit_webhook` | Edit webhook | ዌብሁኩን አርትዕ |
| `webhooks_page.enabled` | Webhook enabled | ዌብሁኩ ነቅቷል |
| `webhooks_page.updated` | Webhook updated | ዌብሁኩ ተዘምኗል |

## Setup wizard catalogues in Afaan Oromoo (2026-11-14)

The setup wizard shows catalogue text that comes from the backend, not from the dictionaries, and it picked Amharic with an `am` check, so an Afaan Oromoo reader saw English. Every `label_am`, `description_am` and `name_am` in the wizard catalogues now has an `_om` beside it, and the frontend picks `field_<locale>` for any shipped locale (`features/onboarding/v2/localized.ts`), falling back to English when the Oromo text is empty.

**Drafted, not native-reviewed.** The text was written by a non-native reviewer from terms the product already ships in `om.json` and `api/lang/om/` (*boqonnaa* leave, *abbaltii* allowance, *damee* branch, *hojjetaa* employee, *shiftii* shift, *Fulbaana* and *Adoolessa* for Meskerem and Hamle, *Labsii Hojjetaa* for the Labour Proclamation). A native speaker's reading is still owed (O7). The names that are least certain are marked below.

| Catalogue | File | `_om` strings |
|---|---|---|
| Industries (labels, and the allowance names in their overrides) | `api/app/Services/Onboarding/IndustryCatalog.php` | 35 labels, 4 allowance names |
| Workforce profiles (labels, descriptions, shift and rotation names) | `api/app/Services/Onboarding/WorkforceProfileCatalog.php` | 10 labels, 10 descriptions, 21 names |
| Policy profiles (labels, descriptions, allowance names) | `api/app/Services/Onboarding/PolicyProfileCatalog.php` | 3 labels, 3 descriptions, 6 names |
| Leave types | `api/app/Services/Onboarding/LeaveTypeCatalog.php` | 13 names |
| The proposed head-office branch | `api/app/Services/Onboarding/IndustryProfileResolver.php` | 1 name |

`OnboardingCatalogCopyTest` fails when an `_am` text has no `_om`, an empty one, or one equal to the English or the Amharic.

Terms to check first, because the English has no settled Oromo equivalent in the product yet: *Sektara Mootummaa* (government sector), *Telekoominikeeshinii*, *Boqonnaa Qorannoo* (sabbatical), *Boqonnaa Gaddaa* (bereavement), *Boqonnaa Gaa'elaa* (marriage), *Hafuu Hayyamamaa* (excused absence), *Waaree Booda* (afternoon and swing shift) and *Torban Guyyaa Jahaa* (six-day week).

### Proposal names and kinds in law (2026-11-15)

Drafted in Amharic and Afaan Oromoo without a native speaker, for QA observation O-2. A wrong
name moves no money.

| What | Where | Strings |
|---|---|---|
| Departments the wizard proposes | `api/app/Services/Onboarding/ProposalNameGlossary.php`, `DEPARTMENTS` | 167, each in Amharic and Afaan Oromoo |
| Positions the wizard proposes | the same file, `POSITIONS` | 151, each in Amharic and Afaan Oromoo |
| The word of a grade | the same file, `GRADE` | ደረጃ, Sadarkaa |
| Kinds of organisation in law | `organization_types.name_om`, migration `2026_11_15_000001_add_oromo_name_to_organization_types` | 16, Afaan Oromoo only |

`ProposalNameGlossaryTest` fails when a proposed department or position has no Amharic or no
Oromo name, one equal to the English, or one in the wrong script. It does not judge the wording.
Correct a kind's name with a new migration that updates the row, never by editing that one; a
glossary entry is corrected where it is.

What to check first:

- **Loan words kept beside an Oromo gloss**, where the product has no settled term: *Underwriting*,
  *Actuarial*, *Treasury*, *Billing*, *Catering*, *DevOps*, *Product*, *Marketing*, *Steward*.
- **The same Amharic for two English names**, on purpose: አሠልጣኝ for Instructor and Trainer,
  ሠራተኛ for Worker and Staff, የጽሕፈት ቤት ኃላፊ for Office Head and Head of Office.
- **Safety and Security** are kept apart: የሥራ ደህንነት / *Nageenya Hojii* and ጥበቃ / *Eegumsa*.
- **Department** in a zone's structure is መምሪያ / *Qajeelcha*, the civil-service word, while the
  product's own word for a department is ክፍል / *Muummee*.

### Terminal number warnings (2026-11-15)

Drafted in Amharic and Afaan Oromoo without a native speaker, awaiting a native reading. A wrong word
moves no money: the person is added either way.

| What | Where | Strings |
|---|---|---|
| Warning that a badge number is not one a ZKTeco terminal takes | `api/lang/{am,om}/onboarding.php`, `migration.terminal_number_digits_only` | 1 |
| Notes that a terminal's own number is too long or not a badge number | the same file, `migration.device_id_too_long`, `migration.device_id_not_badge` | 2 |
| Heading above those warnings in the People step | `src/src/lib/i18n/locales/{am,om}.json`, `setup2.warnings_heading` | 1 |

### Employee import profile columns (2026-11-16)

Drafted in Amharic and Afaan Oromoo without a native speaker. They are the words for eleven new
columns of the employee import (grade, cost centre, probation end, contract type and end date,
bank name, branch and account, emergency contact name, relationship and phone). The English is
final. A wrong word here misleads a person filling in a spreadsheet; it moves no money.

| What | Where | Strings |
|---|---|---|
| Help lines under each new column | `src/src/lib/i18n/locales/{am,om}.json`, `employees_import_page.col_grade` to `col_emergency_phone` | 11 each |
| Five new column names | the same files, `employees_import_page.label_contract_end_date`, `label_emergency_name`, `label_emergency_phone`, `label_bank_branch`, `label_bank_account` | 5 each |
| Problems a list can have | `api/lang/{am,om}/onboarding.php`, `migration.profile_*` (one says a contract starts on the hire date, so the row needs one) | 5 each |
| Row prefix, two field names and the "not stored" line | `api/lang/{am,om}/employee.php`, `import_row`, `import_field_grade`, `import_field_cost_center`, `import_not_stored` | 4 each |

The other six column names reuse words the profile screens already have. What to check first: the
contract type values (`probation`, `fixed_term`, `permanent`, `casual`, `consultancy`) are kept
in Latin letters in both languages, because they are what the column takes; and *Sadarkaa* for a
grade, which the proposal glossary already uses.

### Completing people who are already here (2026-11-16)

Drafted in Amharic and Afaan Oromoo without a native speaker, awaiting a native reading. The English
is final. A wrong word here misleads a reader about what a list will do; it moves no money, and a
difference in a person's job is shown and never written whatever the words say.

| What | Where | Strings |
|---|---|---|
| The choice between adding people and completing those already here, the review's title and hint, "Not found in ETHR, left out", and the confirmation, progress and done lines | `src/src/lib/i18n/locales/{am,om}.json`, `setup2.mode_*`, `setup2.complete_*`, `setup2.not_found_left_out`, `setup2.left_out`, `setup2.completing`, `setup2.complete_commit`, `setup2.complete_continue`, `setup2.outcome_new_complete` ("not found", the count chip of a list that completes people) | 15 each |
| The line under a difference that is left alone ("Left as it is. Change it with a personnel action."), the words for a bank account, emergency contact and contract a list adds, and "Adds :field: :value" | the same files, `setup2.changes_locked`, `setup2.change_bank`, `setup2.change_emergency_contact`, `setup2.change_contract`, `setup2.changes_adds` | 5 each |
| The Download to complete button, its three messages, and the three new gaps a list can be filtered by | the same files, `employees.complete_*`, `employees.missing.department`, `employees.missing.position`, `employees.missing.gender` | 7 each |
| Two refusals from the row endpoint: a list that completes people adds nobody, and a person's job and pay are changed with a personnel action | `api/lang/{am,om}/onboarding.php`, `migration.complete_adds_nobody`, `migration.accept_locked` | 2 each |

What to check first: *personnel action* (the product's own word for a transfer, promotion or demotion
that leaves a record) in `setup2.changes_locked` and `migration.accept_locked`, and the word for
*left out* against the Amharic and Afaan Oromoo the import review already uses for a skipped row.

### Problems by column, and the two files (2026-11-16)

Drafted in Amharic and Afaan Oromoo without a native speaker, awaiting a native reading. The English
is final. A wrong word here misleads a person correcting a spreadsheet; it moves no money and changes
no record.

| What | Where | Strings |
|---|---|---|
| The heading and four column headings of the table of problems ("Lines to fix", "What to fix", "Lines", "Line numbers"), and "Whole list" for a problem about the list itself | `src/src/lib/i18n/locales/{am,om}.json`, `setup2.problem_heading`, `problem_col_what`, `problem_col_count`, `problem_col_lines`, `problem_whole_list` | 5 each |
| The button that downloads the refused lines, and the line shown when the file cannot be built | the same files, `setup2.problem_download_fix`, `problem_fix_failed` | 2 each |
| The button that downloads what happened, the two headings of its sheet that are new ("What happened", "Note"), the words *Added*, *Filled in*, *Already here, nothing to change*, and its failure line | the same files, `setup2.result_download`, `result_what`, `result_note`, `result_added`, `result_filled`, `result_unchanged`, `result_failed` | 7 each |

What to check first: *Already here, nothing to change* (`result_unchanged`), and *Filled in*
against the Amharic and Afaan Oromoo the review already uses for a blank that was filled. The words
for *Left out* and *Not found in ETHR, left out* in the result file are the review's own.

### What a list left at the terminals (2026-11-16)

Drafted in Amharic and Afaan Oromoo without a native speaker, awaiting a native reading. The English
is final. A wrong word here misleads a person about whether someone can clock in at a terminal; it
moves no money and changes no record.

| What | Where | Strings |
|---|---|---|
| The section on the committed screen: its title and line, "N sent or waiting", "N not accepted by the terminal", the last answer line, "N not sent. Their number is not one this terminal takes.", and the two lines for people with no badge number or no branch | `src/src/lib/i18n/locales/{am,om}.json`, `migration_terminals.title`, `desc`, `sent_or_waiting`, `failed`, `last_error`, `not_sent`, `without_number`, `without_branch`, and the singular `_one` form of `sent_or_waiting`, `failed`, `not_sent`, `without_number` and `without_branch` | 13 each |
| The two lines for a terminal ETHR cannot write to ("This terminal only sends" and "ETHR does not send people to this terminal"), the download button, the sheet's two headings and its failure line | the same files, `migration_terminals.receives_only`, `enrol_by_hand`, `download`, `download_aria`, `col_number`, `col_name`, `download_failed`, `employees_link`, and the singular `receives_only_one` and `enrol_by_hand_one` | 10 each |
| The Try again button, its name for a screen reader, and its two answers | the same files, `migration_terminals.retry`, `retry_aria`, `retry_done`, `retry_failed` | 4 each |
| Bringing a badge's held punches in, in several requests: "N brought in, M still to go", Stop, Carry on, "Stopped.", "It stopped before it finished.", and the line when one request did not replay them all on the new-employee screen | the same files, `devices_page.unmatched.progress`, `stop`, `carry_on`, `stopped`, `carry_failed`, and `employee.badge_replayed_part` | 6 each |
| After a list: the people whose held punches can now be brought in ("N people on this list have punches waiting at a terminal"), each line, the warning that it cannot be undone here, the button, the totals, the refused line, the line for matches past the cap, and the link to Devices. Each count has a singular `_one` form | the same files, `migration_terminals.punches`, `punches_one`, `held`, `held_one`, `held_row`, `held_warning`, `held_button`, `held_done`, `held_refused`, `held_refused_one`, `held_more`, `held_more_one`, `devices_link` | 13 each |
| The audit label for bringing held punches in when the link already existed ("Held punches brought in"), and the new-employee line for refused punches, which no longer says they are listed on the attendance | `src/src/lib/i18n/locales/{am,om}.json`, `audit.action.device.held_punches_replayed` and `employee.badge_replayed_some` (reworded) | 2 each |
| The 409 for a badge whose punches are already being brought in by another request | `api/lang/{am,om}/device.php`, `held_busy` | 1 each |

What to check first: the word for a *terminal* (the drafts use the word the Commands tab already uses
for a device, so "terminal" and "device" read alike), and *not accepted by the terminal* against *not
sent* (the first is a refusal by the device, the second is ETHR holding back a number the device would
refuse).

### A list that comes out of other software (2026-11-16)

Drafted in Amharic and Afaan Oromoo without a native speaker, awaiting a native reading. The English
is final. A wrong word here misleads a person about which column of their file is read as a name or
which line holds the column names; it moves no money and changes no record.

| What | Where | Strings |
|---|---|---|
| The three parts of a name, as columns a file's column can be matched to: "First name", "Father's name", "Grandfather's name", and what each takes | `src/src/lib/i18n/locales/{am,om}.json`, `setup2.col_first_name`, `col_father_name`, `col_grandfather_name`, and `employees_import_page.col_first_name`, `col_father_name`, `col_grandfather_name` | 6 each |
| The line that holds the column names: "Column names are on line N.", the choice's name and label, "Line N", "There are no column names", and the button "The column names are on another line" | the same files, `setup2.header_line_found`, `header_line_pick`, `header_line_option`, `header_line_none`, `header_line_other` | 5 each |
| A workbook with several sheets: "This workbook has N sheets. Reading: Sheet1.", and the sheet choice's name for a screen reader | the same files, `setup2.sheets_note`, `sheet_pick` | 2 each |
| The note under a text file that was not UTF-8: "This file was read as Windows-1252 text." | the same files, `setup2.file_read_as` | 1 each |

What to check first: the three part names against how a name is usually asked for on an Ethiopian
form (the drafts use the Amharic words for the father's and the grandfather's name, and the
column's English is "Last name" in many exports, which the server reads as the grandfather's name),
and *Column names* against the word the matcher already uses for a file's first line.

### Lists in progress (2026-11-16)

Drafted in Amharic and Afaan Oromoo without a native speaker, awaiting a native reading. The English
is final. A wrong word here misleads a person about whether a list can still be thrown away; it moves
no money, and a list that has begun to be added cannot be discarded at all.

| What | Where | Strings |
|---|---|---|
| The card above the list box: its title and line, the two kinds of list ("Adding people", "Completing people who are already here"), the three states ("Still being read", "Waiting for your review", "Being added"), "N lines" and "1 line", the three ways to say who started it ("Started 5 min ago by you", "by NAME", "by someone else"), Open, "Opening the list" | `src/src/lib/i18n/locales/{am,om}.json`, `migration_lists.title`, `hint`, `mode_add`, `mode_complete`, `status_staging`, `status_reviewing`, `status_committing`, `lines`, `lines_one`, `started_you`, `started_by`, `started_other`, `open`, `opening` | 14 each |
| Discarding: the question, its two forms of "The N lines on it are removed. Nobody on it has been added.", the button, the two answers | the same files, `migration_lists.discard_title`, `discard_desc`, `discard_desc_one`, `discard`, `discarded`, `discard_failed` | 6 each |
| The line over a list that was still being read when the page was opened, before Carry on | the same files, `setup2.staging_resumed` | 1 each |
| The audit label "Data migration list discarded" | `src/src/lib/i18n/locales/{am,om}.json`, `audit.action.migration.discarded` | 1 each |
| The two refusals when a list is being added or was added | `api/lang/{am,om}/onboarding.php`, `migration.discard_committing`, `discard_committed` | 2 each |

What to check first: *Open* and *Discard* as verbs on a button (the drafts use the imperative; the Amharic
for discard is "remove"), and the three
"started" sentences, where a person's name sits inside the Amharic and Oromo word order. The Oromo for
the second one puts the name before the verb and may need a suffix on the name.

### The console shows the kind in law (2026-11-15)

Four keys drafted in Amharic and Afaan Oromoo without a native speaker, in
`src/src/lib/i18n/locales/{am,om}.json`: `admin_tenants_page.filter_by_kind`,
`admin_tenants_page.all_kinds`, `admin_tenant_detail_page.kind_needs_review` and
`admin_tenant_detail_page.kind_read_only`. The kind's own name and the heading "Kind of
organisation in law" are existing strings (`settings.organization_kind`, the catalogue's
`name_am` and `name_om`), reused as they are. A wrong label moves no money.
