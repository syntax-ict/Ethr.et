# ETHR on social media

The product's design system ([`DESIGN_SYSTEM.md`](../../DESIGN_SYSTEM.md)) applied to a feed. Read it with the [kit README](README.md).

## What ETHR is, in one breath

> Attendance, payroll and HR, built for Ethiopian organisations.
>
> ለኢትዮጵያ ድርጅቶች የተሠራ የመገኘት፣ የደመወዝና የሰው ሀብት መድረክ

Every post supports one of three proofs, in this order of weight:

1. **It works where the network does not.** Check-ins queue on the device and upload when the connection returns.
2. **It knows Ethiopian rules.** Income tax under Proclamation 1395/2025, pension 7% + 11%, the 13-month calendar with Pagume, Ethiopian public holidays.
3. **It speaks both languages.** Every screen, payslip and notification in Amharic and English, chosen by each person.

The audience is the people who choose and run HR systems: HR managers, finance managers and general managers in government offices, banks, hospitals, manufacturers, NGOs, hotels, universities and private companies. Employees are the secondary audience; they meet ETHR on their phone after their employer chooses it.

## Look

| Element | Rule |
|---|---|
| Background | Deep navy `#0A2E4A` fading to `#071F33`, or paper `#F8FAFC`. Alternate them so a grid of posts does not read as one block |
| Accent | Ethiopian gold `#E8A838`, for one thing per image: the call to action, the overline, the highlighted item |
| Interactive blue | `#3282B8` for lines and secondary marks; primary `#0F4C75` for the logo tile |
| Type | Inter for Latin, weights 600 to 800. Noto Sans Ethiopic for Amharic at line height 1.4 (headlines) to 1.7 (body); Amharic clips below 1.5. JetBrains Mono for codes and times |
| Logo | The `E` tile beside the `ETHR` wordmark, top left. Never stretched, recoloured or set on a busy photo |
| Signature | The tibeb band: gold diamonds between two blue threads, along one edge of the image. One cultural element, used with restraint |
| Pictures | Drawn product moments (a check-in, a payslip, a calendar) or real screenshots of the demo organisation. No stock photos of handshakes |

**Not used, from the design system's "identity, not kitsch" rule:** flags, coffee ceremonies, maps, or traditional dress as decoration. Ethiopian identity comes from the language, the calendar, the tibeb band and the gold.

## Voice

Plain, specific, calm. Write the way the landing page does: name the real problem in the office, then say what ETHR does about it.

<!-- claims-check: off -->
| Do | Do not |
|---|---|
| "A regional branch loses the network for an afternoon, and its records for the day go with it." | "Revolutionise your workforce!" |
| "Income tax under Proclamation 1395/2025" | "Fully compliant" |
| "Every payslip keeps the working behind its figures." | "Powered by AI" |
| British spelling, as on the site: *organisation* | Exclamation marks, em dashes, emoji |
<!-- claims-check: on -->

The house style rules out em dashes, exclamation marks and emoji everywhere, captions included. Use a comma, a full stop or a colon. `source/check.mjs` enforces it.

In Amharic, keep to the product's own terms: መገኘት (attendance), ደመወዝ (payroll), ፈቃድ (leave), የደመወዝ ወረቀት (payslip), ጳጉሜን (Pagume). Write Amharic first and English second in a bilingual post on Facebook and Telegram, where most readers read Amharic first; English first on LinkedIn and X.

## Claims: what the kit may say

Every claim below is true of the product today and appears on the live site.

- Check-in from a phone (with location and photo), a QR code, a shared kiosk or the web
- Works offline; check-ins queue on the device and upload themselves when the connection returns
- A location check: a phone check-in from outside a workplace's area can be refused
- Rotating and night shifts, grace periods and overtime rules per department
- Income tax under Proclamation 1395/2025; pension 7% employee and 11% employer; overtime; Pagume proration; loan and cost-sharing deductions
- A calculation trail on every payslip; a payroll run is approved before employees see it
- Payslips on any phone; a bank transfer file for each run
- Leave requests from a phone, decided from one approvals inbox; monthly accrual and carry-forward; public holidays not counted as leave days
- Every screen, payslip and notification in Amharic and English; the Ethiopian calendar beside the Gregorian one
- Data stored on servers in Ethiopia, on Ethio Telecom hosting in Addis Ababa or the customer's own, as Article 22 of Proclamation 1321/2024 requires
- Bank account, TIN and national ID numbers encrypted at rest with AES-256
- Each organisation's data kept apart by a guard that fails closed
- Sensitive actions recorded in an audit trail that cannot be edited or deleted
- Sector templates for government, banking, healthcare, manufacturing, NGO, hospitality, education and general
- A 6-month free trial with every feature, no credit card

## Claims: what the kit may not say

<!-- claims-check: off -->
The marketing console refuses these, and so does `check.mjs`. The reasons come from the capability audit of 17 October 2026 (`api/app/Support/MarketingClaims.php`).

| Do not say | Why |
|---|---|
| Performance reviews, appraisals, training, certifications, goals, OKRs | Not built |
| Any device brand (Hikvision, ZKTeco, Suprema), "biometric", "fingerprint" | No device has been verified on real hardware, and biometric data is sensitive under Proclamation 1321/2024. Say "attendance terminals that send punches over the internet; we confirm your model first" |
| Excel import | Import is pasted spreadsheet rows (CSV), not an `.xlsx` file |
| Delegation, maker-checker | Not built. Multi-step approval chains do exist |
| "Fully compliant", "no data leaves", "your data, your servers", "every action is logged", "no cross-tenant access is possible" | Legal conclusions are the employer's to draw as data controller; the audit trail covers sensitive actions; isolation fails closed but is not absolute |
| "500+", "50,000+", "1M+", "99.9%", "uptime" | No figure can be substantiated and there is no SLA |
| Telebirr, "bank integration" | No Telebirr integration; payroll produces a bank transfer file |
<!-- claims-check: on -->

Also leave out anything about speed of setup ("live in five minutes"), customer numbers and testimonials until there is a measured figure or a named customer who has agreed to be quoted.

## Accessibility

- Put the headline in the caption too: screen readers do not read text inside an image.
- Fill the platform's alt text field with the image's headline and the line under it; [`source/content.mjs`](source/content.mjs) holds both for every image.
- Caption every video; Amharic captions use Noto Sans Ethiopic, never a fallback that draws boxes.
- Gold on white fails contrast. Gold sits on navy, or behind dark text, never as text on paper.
