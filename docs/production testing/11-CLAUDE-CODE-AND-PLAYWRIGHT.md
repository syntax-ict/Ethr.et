# 11: running the pack with Claude Code, Chrome and Playwright

Added 2026-11-09; the Chrome channel and the service-worker rule added 2026-11-10. This file says
how the cases in 03 to 10 are run by a machine: by the Playwright scripts in `src/e2e-production/`,
and by Claude Code driving a browser, either through the Playwright MCP server or through the
Claude in Chrome extension. **None of them replaces the tester.** They run what is safe to run
unattended, record it in the execution log's format, and hand back every step that needs a person:
a password, an authenticator code, a phone, a device, or an outside effect.

Every rule in [01, section 9](01-TEST-PLAN.md#9-production-safety-rules) applies to a machine
exactly as to a person. An agent that breaks one has caused an incident, the same as a tester.

## 1. Four ways to run a case

| Way | What runs it | Good for | Signs in | Can it write? |
|---|---|---|---|---|
| **By hand** | The tester, in a browser | Everything; the only way for Mode X and anything on a phone or device | The tester | Yes |
| **Scripted** | `npm run test:e2e:production` in `src/` (Playwright CLI) | The release smoke checklist after every deploy (10, section 3): the same checks, every time, in under a minute | Never. A person saves each persona's session first (section 3) | No. The guard aborts it |
| **Agent, guarded** | Claude Code with the Playwright MCP server (section 5) | Read-only cases in 03 to 09 that need judgement: reading a page, an accessibility tree, a network response, a message, and comparing them with the Expected column | Never. Same saved sessions; the agent stops and asks when a step needs a person | No. Same guard |
| **Agent, in Chrome** | Claude Code with the Claude in Chrome extension (section 6) | Public pages signed out; a Mode W session the owner has agreed to; a case where a person must do a step in the same window halfway through | The tester, in a Chrome profile kept for QA | **Yes. Nothing stops it** |

### 1.1 Which browser an agent uses

The two agent browsers are not interchangeable, and the difference is the last column.

- **The guarded Playwright browser is the default for every signed-in Mode R case.** It starts
  empty, holds one persona's session, and cannot send a write even if the agent clicks Save.
- **Chrome is the person's own browser.** It has no guard, it keeps whatever that profile is signed
  in to, and its service worker runs. A mistaken click there is a real write. Use it only where the
  guard would be off anyway or where there is nothing to write:

| Use Chrome for | Do not use Chrome for |
|---|---|
| Public pages, signed out (S-06, S-07, MKT-*, the read-only LAND-* cases) | **The platform console.** An agent never drives a PA session in Chrome: that session can change every customer. Read the console guarded, or by hand |
| A Mode W session in a QA organisation that the owner has agreed to (the guard is off for those in either browser) | A profile that is signed in to anything but the QA personas: the tester's everyday profile, a customer organisation, the owner's console session |
| A case that needs a person's step mid-way (an authenticator code, a sign-in code) and then more reading in the same window | Proving a case is read-only. A Mode R case run signed-in in Chrome is logged "Chrome, unguarded" (section 6.4) |
| How a page behaves in an installed Chrome: the service worker, a stored copy, a returning visitor (MKT-015) | Phone cases. Resizing a desktop window is a layout check, not a phone (section 6.3) |

When both would do, use the guarded browser.

## 2. Which cases a machine may run

The pack does not carry a column for this; it follows from the columns it has. Work it out per
case, in this order, and the first rule that matches wins:

| # | When the case… | Class | Who runs it |
|---|---|---|---|
| 1 | is Mode **X** (an SMS, an email outside the team, a webhook to an outside URL, an invoice, a data export, a delete or restore, a backup) | **H** | A person, with the owner's go-ahead (rule 4) |
| 2 | needs a phone's GPS, camera, selfie, passkey, QR scan, a kiosk tablet, a biometric device, a printer or a second physical person | **H** | A person. An agent may read the result afterwards |
| 3 | needs a password, an authenticator code, a sign-in code, a reset link, an API key, a webhook secret, a device token or a kiosk token to be typed, read or copied | **H** for that step | A person does that step in the browser window; the agent continues after it. A secret is never typed into chat, a prompt, a log or an environment variable |
| 4 | trips a rate limit or the host's firewall on purpose (RL-*, S-05, SEC-004) | **H** | A person, paced as rules 6 and 10 say |
| 5 | is Mode **W** | **C-W** | An agent may run it in QA Audit, Syntax or a `uat-` organisation only, in a session the owner has agreed to: the Playwright browser without the guard (section 5.1), or Chrome (section 6) |
| 6 | is Mode **R** | **C** | An agent, in the guarded Playwright browser. Signed out on a public page, Chrome will also do |
| 7 | is in 10, section 3 and has a script (section 4) | **A** | The Playwright CLI |

A case that mixes classes is split at the boundary: the agent runs its read-only steps, says which
step it stopped at and why, and the log records **Blocked** with "needs a person: step N" until the
tester finishes it.

**The Mode column is a claim, and it can be wrong.** A case marked R whose steps send, save or
submit is a W case with a wrong label: the guard will block it, and in Chrome nothing will. Do not
run it as R; report the label. MKT-004 was one: the contact form stores a lead and mails ETHR's
contact inbox (`ContactController`), so it is Mode X since 2026-11-10.

## 3. One-time setup, and saving a session

On the tester's own machine, from a checkout of `main`:

```bash
cd src && npm ci && npx playwright install chromium
```

The scripted checks and the guarded agent never sign in. **A person saves each persona's session,
with MFA, just before the run**, and the file stays on that machine:

```bash
cd src && npx playwright open --save-storage=e2e-production/.auth/ta.json https://www.ethr.et/qa-audit
```

Sign in as TA in the window that opens (authenticator code included), wait for the dashboard, and
close the window: the session is written when it closes. Repeat for `hr`, `fin`, `sup` and `emp`.
For the platform operator, open `https://admin.ethr.et` and save `pa.json`.

- `src/e2e-production/.auth/` is ignored by Git. **Never commit, attach, upload or paste a session
  file.** It is a signed-in session: anyone holding it is that persona until it expires.
- A session lives **15 idle minutes** (`AuthService::SESSION_MINUTES`). The scripts refuse a file
  older than that and say so; save it again rather than raising the limit.
- Only QA organisations. `src/e2e-production/env.ts` refuses a `QA_SLUG` that is not `qa-audit`,
  `syntax` or `uat-…` (rule 1). Sign out of the window you saved from when the pass ends.

## 4. Running the scripted checks (Playwright CLI)

```bash
cd src && npm run test:e2e:production
```

What it covers, all Mode R:

| Smoke row | Script | Needs a saved session | Not checked by the script (do by hand) |
|---|---|---|---|
| S-01 | `smoke.spec.ts` | No | Set `EXPECTED_COMMIT` to the release commit, or it only reads the commit |
| S-02 | `smoke.spec.ts` | No | Failed jobs not growing (compare two readings) |
| S-03 | None | | A route new in this release; it changes every release |
| S-04 | `smoke.spec.ts` | No | An organisation subdomain other than QA Audit's |
| S-05 | `smoke.spec.ts`, only with `PROD_PROBE_SECRETS=1` | No | Logs path; three probes at most in ten minutes |
| S-06 | `smoke.spec.ts` | No | That prices are shown |
| S-07 | `smoke.spec.ts` | No | The branding |
| S-08 | `signed-in.spec.ts` | TA, HR, FIN, SUP, EMP | The sign-in itself and MFA (the person did them in section 3) |
| S-09 | `signed-in.spec.ts` | PA | KPI cards and the Operations strip by eye |
| S-10 | `signed-in.spec.ts` | TA | "With data" versus a proper empty state, by eye |
| S-11 | `signed-in.spec.ts` | EMP | |
| S-12, S-14, S-15 | None | | By hand, or by the agent (S-12, S-15) |
| S-13 | `signed-in.spec.ts` | EMP | The bottom bar, by eye (the script checks there is no horizontal scroll) |

Run one row with `--grep "S-02"`, a priority with `--grep @P1`. Settings, none of them secret:

| Variable | Default | Meaning |
|---|---|---|
| `PROD_BASE_URL` | `https://www.ethr.et` | The public site; the organisation's address is found from it |
| `PROD_PLATFORM_URL` | `https://admin.ethr.et` | The console |
| `QA_SLUG` | `qa-audit` | The QA organisation (QA organisations only) |
| `EXPECTED_COMMIT` | (none) | The commit under test, from the release notes or `release.json` of the tag |
| `PROD_PROBE_SECRETS` | (off) | `1` runs S-05 |
| `PW_CHANNEL` / `PW_EXECUTABLE_PATH` | (bundled Chromium) | Use an installed Chrome or Edge, or a given binary |

**How it stays read-only.** Two locks, and what each does not cover:

1. Every spec imports `test` from `e2e-production/guard.ts`, which aborts any call under
   `/api/v1/` other than GET, HEAD and OPTIONS before it leaves the browser (the token refresh
   excepted), and lists what it blocked as a `blocked-write` note on the test.
2. Service workers are blocked (`serviceWorkers: "block"` in `playwright.production.config.ts`).
   The guard sees requests a page makes; the app's service worker makes one of its own, replaying
   punches queued while offline with `POST /api/v1/attendance/sync` (`src/public/sw.js`,
   `flushQueue`). With no worker there is no replay. The cost: the scripted run says nothing about
   the PWA, which is tested by hand (09, section 8).

- **A GET is let through.** Read-only here means no state-changing method, not no trace: the
  server may log or count a read.
- **The guard is in the browser only.** `curl`, a script or Claude Code's own shell are not behind
  it. Everything a machine sends to production for a case goes through the guarded browser, except
  a single GET or HEAD the case itself asks for (`curl -I`).

Traces and videos are off because both record cookies; a failure leaves a screenshot in
`src/test-results/production/`, which shows QA data only and is checked for secrets before it is
attached anywhere.

**Into the execution log.** The JSON report becomes rows of [10, section 2](10-TEMPLATES-AND-REFERENCE.md#2-execution-log):

```bash
cd src && node e2e-production/execution-log.mjs
```

A skipped check is **Not run** with its reason, never Pass. The tester is recorded as
`Playwright`; the person who ran it signs the log.

**Three Playwright suites, one for production.** Do not confuse them:

| Suite | Config | Where it may run |
|---|---|---|
| `src/e2e-production/` | `playwright.production.config.ts` | Production. Read-only, never signs in |
| `src/e2e/` | `playwright.config.ts` | Local and CI. Signs in with demo passwords and writes data; never production with its defaults ([01, section 3](01-TEST-PLAN.md#3-approach)) |
| `src/e2e/attendance-payroll/` (#688) | `playwright.attendance.config.ts` | Local and CI only (`./scripts/gates.sh e2e-attendance`). It seeds its own organisations (`E2eAttendanceSeeder`), punches and processes payroll. **Never production, under any setting** |

## 5. Running cases with Claude Code and the Playwright MCP server

### 5.1 Connect the browser

Save the sessions first (section 3). Then, from the repository root, add a Playwright MCP server
for the persona the cases need, with both locks:

```bash
claude mcp add ethr-prod-emp -- npx @playwright/mcp@latest --isolated --block-service-workers --storage-state "$PWD/src/e2e-production/.auth/emp.json" --init-page "$PWD/src/e2e-production/mcp-read-only.ts" --output-dir "$PWD/src/test-results/production/mcp"
```

- `--isolated` keeps the browser profile in memory, so nothing outlives the session but the file
  you saved. `--storage-state` loads it.
- `--init-page` loads `mcp-read-only.ts`: the same guard as the scripts.
  `--block-service-workers` is the second lock of section 4. **Leave both off only for a Mode W
  session the owner has agreed to**, and name that server so it cannot be mistaken
  (`ethr-prod-ta-WRITE`).
- **Use only a server you added this way.** A general-purpose Playwright server or plugin already
  on the machine (often named `playwright`) has neither the saved session nor the guard. It is
  not a production browser for this pack, whatever it can open.
- The browser is headed by default. Keep it that way: the person needs the window for every
  class-H step (section 2), and an MFA prompt mid-case is answered there, never in chat.
- One server per persona; remove each when the pass ends (`claude mcp remove ethr-prod-emp`).
  Flags change between releases of the server: `npx @playwright/mcp@latest --help` lists the ones
  your version has (these were read from that output on 2026-11-10).

### 5.2 Start the session

Start Claude Code in the repository root, so it reads `docs/production testing/CLAUDE.md` (the
rules for an agent running this pack) along with the project's own. Then give it a bounded task:

```text
Run the Mode R cases of docs/production testing/05-E2E-ATTENDANCE-SHIFTS-DEVICES.md section 13
(HOL-*) as EMP, using the ethr-prod-emp browser. Release under test: v2026.11.09.1.
First check S-01 against that release. Follow docs/production testing/11, section 2 to decide
what you may run. For each case report one execution-log row (10, section 2) and the evidence
for it. Stop and ask me before any step that is class H or Mode W/X.
```

Keep a session to one persona and one section. A long session drifts, its sign-in expires after 15
idle minutes, and a small one is easier to check.

### 5.3 How the pack's words map to the browser tools

| The case says | Playwright MCP | Claude in Chrome (section 6) |
|---|---|---|
| Open, go to a page | `browser_navigate` | `navigate` |
| Read the page, "shows", a label, a message | `browser_snapshot` (the accessibility tree; quote the text it finds) | `read_page`, or `get_page_text` for a long page; `find` to locate one element |
| "Announced as", a role or name | `browser_snapshot` | `read_page` |
| The API answers 403, 422 | `browser_network_requests`: method, path, status | `read_network_requests` with a `urlPattern`: method, path, status |
| A `problem+json` body: `title`, `detail` | `browser_network_request` for the one call. Strip cookies and tokens before quoting | Not available: the list carries no body. Quote the message the page shows, or run the case in the Playwright browser. **Never re-send the call to read its answer**: for a write that is a second write |
| "Console clean", no error | `browser_console_messages` | `read_console_messages` with `onlyErrors`, and a `pattern` when looking for one error (`418`) |
| At 375 px | `browser_resize` to 375 x 812, or a server started with `--device "iPhone 13"` | `resize_window`: the window only. No touch, no phone user agent |
| A screenshot for the defect | `browser_take_screenshot`, after checking no secret, token or another organisation's data is on screen | `computer` with `screenshot` or `zoom`, same check |
| Read a value the page does not print (`<html lang>`, a computed style) | `browser_evaluate` | `javascript_tool`, reading only: never `fetch` with a method other than GET, never cookies or stored tokens |
| Click, type, choose (Mode W only) | `browser_click`, `browser_type`, `browser_select_option`, `browser_fill_form`, in a QA organisation, in a session without the guard | `computer` (`left_click`, `type`), `form_input`, in a QA organisation |

The Playwright names are those of server 0.0.84 (2026-11-09); the Chrome names were read from the
extension's tool definitions on 2026-11-10 and **have not yet been used on production from this
pack**, so the first Chrome session also tests this column. Both sets change between releases:
when a name is missing, use what the tool list offers for the same job, and do not fall back to a
tool with wider reach.

Messages are checked in English, the language of the Expected column; the guard sets the interface
to English. A case about Amharic or Afaan Oromoo text is read in that language, against the source
named in 09, section 5.

### 5.4 What the agent hands back

Rows in the format of [10, section 2](10-TEMPLATES-AND-REFERENCE.md#2-execution-log), with
`Claude Code` as the tester and the Claude Code session link in the notes, and under each row the
evidence: the URL, the quoted text or status, and the network call. The notes also name the
browser: `guarded` for the Playwright browser with both locks, `Chrome, unguarded` otherwise.
A Fail comes with a draft in the format of
[10, section 1](10-TEMPLATES-AND-REFERENCE.md#1-defect-report-template) for the tester to file;
**the agent does not open the issue itself**. The person who supervised the session reviews every
Fail and every Pass on a P1 case before the log is signed.

## 6. Running cases with Claude Code and Chrome

Read section 1.1 first. This channel exists because some cases need the person and the agent in
the same real browser. It is the weaker channel for safety, so it carries more rules, not fewer.

### 6.1 A Chrome profile kept for QA

1. In Chrome, add a profile named **ETHR QA** and install the Claude in Chrome extension in that
   profile only for this work. Where the extension lets you choose sites, allow ETHR's own hosts
   and nothing else.
2. In that profile the tester signs in to **one QA persona at a time** (TA, HR, FIN, SUP or EMP of
   QA Audit, Syntax or a `uat-` organisation), authenticator code included. One profile holds one
   session per address: to change persona, sign out and sign in again.
3. **Never sign that profile in to the platform console, to a customer organisation, or to a
   personal account.** If the profile has ever held one, sign out of it first, and check
   `https://admin.ethr.et` opens the sign-in form.
4. When the pass ends, sign out in that profile and close its window.

### 6.2 Connect and start

Start Claude Code with the extension (`claude --chrome`; in the desktop app, turn Claude in Chrome
on for the session). If more than one Chrome is connected, the agent lists them
(`list_connected_browsers`) and the person names the ETHR QA profile. Then a bounded task, as in
section 5.2, with two additions:

```text
Use Chrome, the ETHR QA profile. Before the first step, read the organisation name in the header
and tell me which organisation and persona you see. This session is Mode R: navigate and read
only. (Or: this session is Mode W in QA Audit, agreed with the owner today.)
```

The agent opens its own tab and closes it at the end; it does not take over a tab the person is
using.

### 6.3 What is different in Chrome

- **No guard.** In a Mode R session the agent clicks only to move or reveal: a link, a tab, a
  menu, a filter, a row. It does not press a control that saves, sends, approves, rejects,
  deletes, punches, imports, exports or signs out, and it does not submit a form to see the
  validation message. When a case cannot be read without such a click, it is not a Mode R step.
- **The service worker runs.** A punch queued while offline in that profile is sent when the
  connection returns, whoever is driving. Before a session nothing may be waiting to sync: the
  offline banner shows no pending count.
- **Whatever the profile holds, the agent can reach.** That is why the profile is kept for QA
  (section 6.1) and why the console is never driven there.
- **No response bodies** (section 5.3), and network and console readings start again when the tab
  moves to another host. Read them before leaving the page.
- **Not a phone.** `resize_window` narrows a desktop window. S-13 and the responsive cases pass
  there only as layout checks; touch, the installed PWA and a phone browser are by hand.
- **A recording is evidence and a risk.** A GIF or screenshot of a signed-in page is checked for
  secrets and other organisations' data before it leaves the machine, like any other (rule 3).

### 6.4 In the log

Every row from this channel says `Chrome, unguarded` in the notes. A reviewer treats a Mode R Pass
there as "read and compared", not as "proved to write nothing". Mode W rows name the record
created, so clean-up ([01, section 12](01-TEST-PLAN.md#12-clean-up)) can find it.

## 7. Adding a scripted check

A check belongs in `src/e2e-production/` only when it is Mode R, needs no judgement, and is worth
running after every deploy.

- Import `test` and `expect` from `./guard`, never from `@playwright/test`, so the guard is on.
- Start the title with the case ID (`"S-02 …"`, `"ISO-003 …"`) and tag it with its priority and mode
  (`{ tag: ["@P1", "@R"] }`). `execution-log.mjs` reads the ID from the title.
- Use a saved session through `sessionFile()` and `sessionProblem()` in `env.ts`; never read a
  password from anywhere.
- Add its row to the table in section 4, and run `npx prettier --check`, `npx eslint` and
  `npx tsc --noEmit` in `src/` (the frontend gate covers these files).

## 8. What a green run does not prove

- **That a person can sign in.** The scripts start from a session a person made. S-08's sign-in,
  MFA and the sign-in limits are tested by hand (03, 09 section 3).
- **That the signed-in scripts themselves are right.** Up to 2026-11-10 `signed-in.spec.ts` has
  been linted and type-checked but never run with saved sessions: the runs so far had none, so its
  checks were skipped (Not run). Treat its first signed-in run as a test of the script as much as
  of the product, and read a failure there before filing it.
- **That a page is right.** S-10 proves a page loads with no error; whether its figures are correct
  is the case's job in 04 to 08.
- **Isolation.** A machine reading one organisation proves nothing about another. 09, section 2 is
  run by hand between QA Audit and QA Isolation on every release.
- **The phone, the kiosk, the devices and the PWA.** Nothing here touches GPS, a camera, a passkey,
  a kiosk token or a biometric device, and the guarded browser runs with no service worker.
- **Anything after the guard.** A write the guard blocked was never sent, so a case about what that
  write does has not been run.
- **That Chrome wrote nothing.** Only the guarded browser can say that. In Chrome it rests on the
  agent's care and the reviewer's reading of the session.
