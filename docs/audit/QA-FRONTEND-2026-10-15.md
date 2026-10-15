# Frontend audit — live production sweep, 2026-10-15

Release under test: v2026.10.15.5 (`d24ec2c9`), live on www.ethr.et. Read-only on
production except the QA Audit organisation, per the standing QA rules. This
pass looked at rendered UI quality: language, formatting, layout and console
health. Functional and security behaviour was covered by the earlier passes
([`QA-CONSOLE-PRODUCTION-2026-10-11.md`](QA-CONSOLE-PRODUCTION-2026-10-11.md),
[`QA-ATTENDANCE-PRODUCTION-2026-10-12.md`](QA-ATTENDANCE-PRODUCTION-2026-10-12.md),
[`QA-RELEASE-2026-10-13.md`](QA-RELEASE-2026-10-13.md)).

## Tooling used, per the audit protocol

| Tool | Role | Verified how |
|---|---|---|
| Claude in Chrome (user's browser) | Live rendered inspection of production, signed into QA Audit | Drove real pages; screenshots, console and network reads all answered |
| Browser console/network via the same tools | Runtime diagnostics | Zero errors captured across every page visited |
| Playwright + axe-core (`src/e2e/`, 20 spec files) | The repeatable regression layer: WCAG 2.1 AA (critical/serious incl. color-contrast), responsive, theme, Amharic layout, UX audit | Runs green in CI on every push (the `Playwright (desktop Chromium)` job); not re-run by hand here |
| Vitest + copy-style gate | Unit/regression for the fixes below | Run locally, green |
| `frontend-design` skill (installed plugin) | Design judgment calibration | Read before the sweep; ETHR's own design system and copy rules take precedence where they overlap |

Not used, and why: the built-in browser pane (duplicate of Chrome for live
inspection; kept for local dev previews), the `superpowers-laravel` plugin
(backend refactoring, out of scope), document/office skills (no deliverable of
that kind). Window resizing in Chrome did not change the rendered viewport, so
**responsive checks rest on the CI Playwright matrix (desktop Chromium + iPhone
14 WebKit), not on live mobile inspection** — that is the one deliberately
unverified area of this pass. No missing capability needs a new MCP server or
skill: live inspection, runtime diagnostics and reproducible tests are all
covered by what is installed.

## Pages swept

Marketing en + am, `ethr.et`/`qa-audit.ethr.et` sign-in and portal (full page),
dashboard, employees, attendance, payroll, leave, settings — each with a
console/network error read. **No console errors, no failed requests, anywhere.**

## Findings

| # | Severity | Finding | State |
|---|---|---|---|
| F1 | **S2** | **Attendance is mistranslated as "ቅጥር" (hiring) across 63 keys** — the navigation, dashboard, route titles, device pages, reports, marketing copy. Other keys already said "መገኘት" (presence), so one screen mixed both words, and the employee page's Attendance and Employment tabs carried the **identical label** "ቅጥር". A machine translation confused attendance with employment; the key-parity i18n gate cannot see meaning. | **Fixed** — 63 keys corrected to the መገኘት word family; employment/hire keys keep ቅጥር, which is right for them. `src/src/test/amharic-glossary.test.ts` now pins the glossary: an en source saying attendance may not render the hiring word, and the two employee tabs must differ. Flagged for the native reviewer (owner action O7) as machine-assisted corrections |
| F2 | S3 | **Raw ISO dates in attendance tables** — `2026-10-12` printed verbatim on the attendance, team attendance and attendance intelligence pages, while the app defaults to the Ethiopian calendar and `useCalendar().formatDate` exists and is used by the dashboard. A viewer who chose the Ethiopian calendar still saw Gregorian ISO. | **Fixed** — all three render through `formatDate(dateFromIso(...), locale)`, the dashboard's existing pattern |
| F3 | S3 | **Payroll period label always English Gregorian** — `period_label` is stored once at processing as `format('F Y')` ("September 2026"), so the Amharic UI titled runs in English, and an Ethiopian-calendar viewer saw a Gregorian month for a period cut Meskerem 1–30. The run list and detail also printed raw ISO period dates. | **Fixed** — `lib/calendar/period-label.ts` derives the display label from the period's **midpoint** (an Ethiopian-cut and a Gregorian-cut period both land in the month the period means; the start date would name ጳጉሜ for a Gregorian September). Wired into the run list, run detail (title + breadcrumb) and the accounting journal picker; filenames keep the stored ASCII label deliberately. Pinned by `period-label.test.ts` |
| F4 | S4 | Payslip cards still show the stored English `period_label` — entries carry no period dates in the contract, so localizing them needs `period_start`/`period_end` on `PayrollEntryResource` and a regenerated contract. | Open, noted for a follow-up slice |
| F5 | S4 | Portal about section unbalanced with short content: a two-line paragraph beside a four-chip column leaves a tall empty area. Content-dependent; reads acceptable with real organisations' longer text. | Open, cosmetic |
| F6 | S4 | Settings shows the raw IANA timezone id (`Africa/Addis_Ababa`). Technically correct, cosmetically rough. | Open, cosmetic |

## What this pass does not claim

- Playwright and axe results are CI's, trusted as the repeatable layer, not
  re-derived here.
- Amharic wording beyond the attendance/hiring confusion is unreviewed by a
  native speaker; the corrected strings join the machine-assisted review list.
- Live mobile rendering was not re-inspected this pass (see tooling note).
