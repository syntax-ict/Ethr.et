# Marketing deck: ETHR for HR teams

A 13-slide deck for selling ETHR to HR teams and the people who approve buying it in Ethiopian organisations. It is presented from a Claude Slides artifact, [ETHR for HR Teams](https://claude.ai/artifact/DntTEreLpe59iUbr13nQMv). The artifact is private to its owner until it is shared from its Share menu. This folder holds the deck's source, so it can be rebuilt or changed without the artifact.

| File | What it is |
|---|---|
| [`deck.json`](deck.json) | The slide order, the three sections and the two typefaces (Inter, Noto Sans Ethiopic) |
| `slides/<id>.html` | One slide each, on a fixed 1920×1080 canvas, with its speaker notes in the closing `<aside>` |

## The slides

| # | Slide | Says |
|---|---|---|
| 1 | [`cover`](slides/cover.html) | Attendance, payroll and HR, built for Ethiopian organisations. A live phone checks in offline, then syncs |
| 2 | [`problems`](slides/problems.html) | Six things that go wrong in an HR office today |
| 3 | [`statement`](slides/statement.html) | One system, two languages, works without a network |
| 4 | [`attendance`](slides/attendance.html) | Phone, kiosk, QR code and web check-in, with a live feed |
| 5 | [`offline`](slides/offline.html) | Check-ins queue on the device and upload when the network returns |
| 6 | [`payroll`](slides/payroll.html) | Income tax under Proclamation 1395/2025, pension, Pagume proration, approval |
| 7 | [`selfservice`](slides/selfservice.html) | Shift, leave, payslips and details from a phone; one approvals inbox |
| 8 | [`language`](slides/language.html) | Amharic and English, Ethiopian and Gregorian dates side by side |
| 9 | [`records`](slides/records.html) | Employee records and workforce analytics |
| 10 | [`trust`](slides/trust.html) | Data stored in Ethiopia, AES-256 at rest, tenant isolation, audit trail |
| 11 | [`setup`](slides/setup.html) | Three steps to go live, and the eight sector templates |
| 12 | [`pricing`](slides/pricing.html) | Starter free, Professional 999 ETB, Enterprise 8,999 ETB a month |
| 13 | [`close`](slides/close.html) | Start the 6-month free trial at ethr.et |

## Rules for changing it

- **Say only what the product does today.** The deck's wording comes from the live landing page, and the same limits apply: no performance reviews, training, biometric device brands, Excel import or certified filing formats, and no claim of full legal compliance (the employer is the data controller under Proclamation 1321/2024). [`MARKETING-SITE.md`](../MARKETING-SITE.md) lists the claims the server refuses.
- **Check the prices before presenting.** Slide 12 copies ethr.et/pricing as it was on 31 October 2026. Plan prices are edited at `/admin/plans`, so the site can change without this deck changing.
- **Amharic comes from the product's own dictionary.** Take terms from `src/src/lib/i18n/locales/am.json` rather than translating afresh.
- **Follow the house copy style:** no em dashes, emoji or exclamation marks.
- **Never show real figures in an animated panel.** The payslip on slide 6 draws bars, not amounts; the attendance feed is labelled as an illustration.

## What the live panels do

Slides 1, 4, 5, 6 and 8 each carry a small animated page (`<x-embed>`). They show the time in Addis Ababa, convert today's date to the Ethiopian calendar, and loop through a check-in, an offline sync, a payslip and the English/Amharic switch. They animate in the online deck only: a PowerPoint or PDF download freezes them, and viewers cannot click inside them. The slides scale to any screen but do not rearrange for a phone held upright.
