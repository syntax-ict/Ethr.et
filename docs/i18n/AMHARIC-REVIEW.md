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
