# User guides

Illustrated guides for the people who use ETHR, as PDF. The first edition is in Amharic.

| Guide | For | Source | PDF |
|---|---|---|---|
| የሠራተኛ መመሪያ (Employee) | every employee | [`employee.md`](employee.md) | [`pdf/ethr-employee-guide-am.pdf`](pdf/ethr-employee-guide-am.pdf) |
| የኃላፊ መመሪያ (Manager) | supervisors, department and branch heads | [`manager.md`](manager.md) | [`pdf/ethr-manager-guide-am.pdf`](pdf/ethr-manager-guide-am.pdf) |
| የአስተዳዳሪ መመሪያ (Administrator) | tenant admins and HR admins | [`admin.md`](admin.md) | [`pdf/ethr-admin-guide-am.pdf`](pdf/ethr-admin-guide-am.pdf) |

The English guides in [`docs/user-guide.md`](../../user-guide.md), [`docs/manager-guide.md`](../../manager-guide.md) and [`docs/admin-guide.md`](../../admin-guide.md) were the starting point, but the Amharic guides were written from the screens as they are now. Where the two disagree (approval chains, overtime requests, help requests), the Amharic guide follows the product.

**Status: draft for native-speaker review.** The text uses the interface's own Amharic labels, taken from `src/src/lib/i18n/locales/am.json`, so a step names the button the reader sees. It has not yet been read by a native speaker; follow [`docs/i18n/AMHARIC-REVIEW.md`](../../i18n/AMHARIC-REVIEW.md) before the guides are given to customers.

## Rebuilding

The screenshots are taken from the running app, in Amharic and the Ethiopian calendar, against a scratch copy of the demo organisation. Nothing is drawn by hand, so a release that changes a screen is one command away from a matching guide.

**1. A scratch database, seeded and dressed.** The demo seeder names people with English placeholder names; `prepare-data.php` renames the demo organisation to the fictional «ናሙና ድርጅት», gives everyone Ethiopian names in both scripts, makes one supervisor with a team of eight, and sets four accounts to Amharic and the Ethiopian calendar. It refuses to run against `ethr` or `ethr_local_prod`.

```bash
DB_DATABASE=ethr_guide php api/artisan migrate:fresh --seed --force
DB_DATABASE=ethr_guide php scripts/user-guide/prepare-data.php
```

**2. The app against it.** The API with `DB_DATABASE=ethr_guide` and the web app pointing at it. The capture expects the web app at `http://demo.localhost:3140`; set `GUIDE_WEB` for another address.

**3. Screenshots, then PDFs.**

```bash
cd src
npm run guide:capture       # every shot, in order; about ten minutes
npm run guide:build         # all three PDFs
```

`npm run guide:capture -- emp-leave sup-approvals` retakes named shots and `--guide=manager` one guide's. Some shots submit forms (a leave request, a correction) and a supervisor then decides them, so the manager's queue and the employee's notifications have something in them. Take the full set on a freshly dressed database; retaking one shot alone is fine as long as it does not submit anything.

The capture drains the queue once, as the production cron caller would, so it needs `php` on the path and `GUIDE_API_ROOT` when the API is not this checkout's `api/`.

Playwright's own Chromium is used when installed; otherwise the scripts fall back to the Chrome on the machine.

## What is in this folder

- `*.md`: the guide text.
- `shots/<id>.png`: the screenshots, at twice (desktop) or three times (phone) screen resolution.
- `shots/<id>.json`: the picture's size and the box of each numbered marker, in pixels. `build.mjs` draws the rings and numbers from these, so a marker is always on its control.
- `pdf/`: the built guides. Commit them with the source that made them.

The shot list is [`src/scripts/user-guide/shots.mjs`](../../../src/scripts/user-guide/shots.mjs). Each shot names its account, page, what to click first, and its markers by their Amharic text or accessible name. A label renamed in `am.json` shows up as `marker N not found` on the next capture.

## Writing guide text

The source is Markdown with a few additions. A heading `#` is a chapter and starts a page; `##` is a numbered section in the contents.

| Write | Get |
|---|---|
| `![caption](shots/emp-leave.png)` | the screenshot with its markers, numbered «ምስል 6.1» |
| `![caption](shots/emp-leave-form.png){half}` | a smaller picture, for a dialog or menu |
| `![caption](shots/emp-mobile-home.png){side}` | a phone picture beside the text that follows it |
| a numbered list `1.` | steps with gold numbers matching the markers |
| `> [!tip]`, `> [!note]`, `> [!warning]`, `> [!why]` | a coloured box; text after the tag is its title |
| `((2))` | a marker number in running text |
| `[[key:Ctrl]]` | a key |
| `[[qr:https://ethr.et/login\|caption]]` | a QR code |
| `:::card Title` … `:::` | a card; `:::cols` holds two side by side |

Front matter sets the cover: `title`, `kicker`, `subtitle`, `english`, `audience`, `edition`, `footer` and `back`.

The owner's copy style applies, as in the interface: no long dashes, no emoji, no exclamation marks. Keep a step's number equal to its marker's, and use the label exactly as `am.json` has it, even where its wording could be better; the guide and the screen must agree. Raise the wording as a change to `am.json` instead.

## Found while writing

Screens that show English or Gregorian text inside the Amharic interface, seen while capturing (2026-10-31). They are product issues, not guide issues, and are not fixed here:

- Payslip cards and the dashboard's last-salary card name the month in English («August 2026»).
- Profile overview shows role, status, gender and employment status in English (Employee, Active, Female, Confirmed) and the hire date as `2023-05-08`.
- Approval rows give leave dates with Gregorian month names («ዲሴም 02 - ዲሴም 04») while the reader's calendar is Ethiopian.
- A correction's notification gives its date as `2026-10-27`.
- The overtime request form and the phone check-in screen show Gregorian dates («mm/dd/yyyy», «ቅዳሜ፣ ኦክቶበር 31»).
- Payroll schedule shows the pay period as `monthly`.
- Shift cards name the working days in English (Mon, Tue, Wed).
- Holiday, shift, leave type, department and position names are shown from their English column on several screens; the guide database writes Amharic into both columns to hide it.
- Interface wording worth a native speaker's look: «የይለፍ ቃል ረሳኽ?» (masculine, informal) and the reject button «ተቀበልም».
