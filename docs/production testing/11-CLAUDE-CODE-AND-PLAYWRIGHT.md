# 11: running the pack with Claude Code and Playwright

Added 2026-11-09. This file says how the cases in 03 to 10 are run by a machine: by the Playwright
scripts in `src/e2e-production/`, and by Claude Code driving a browser through the Playwright MCP
server. **Neither replaces the tester.** They run what is safe to run unattended, record it in the
execution log's format, and hand back every step that needs a person: a password, an authenticator
code, a phone, a device, or an outside effect.

Every rule in [01, section 9](01-TEST-PLAN.md#9-production-safety-rules) applies to a machine
exactly as to a person. An agent that breaks one has caused an incident, the same as a tester.

## 1. Three ways to run a case

| Way | What runs it | Good for | Signs in |
|---|---|---|---|
| **By hand** | The tester, in a browser | Everything; the only way for Mode X and anything on a phone or device | The tester |
| **Scripted** | `npm run test:e2e:production` in `src/` (Playwright CLI) | The release smoke checklist after every deploy (10, section 3): the same checks, every time, in under a minute | Never. A person saves each persona's session first (section 3) |
| **Agent** | Claude Code with the Playwright MCP server | Read-only cases in 03 to 09 that need judgement: reading a page, an accessibility tree, a network response, a message, and comparing them with the Expected column | Never. Same saved sessions; the agent stops and asks when a step needs a person |

## 2. Which cases a machine may run

The pack does not carry a column for this; it follows from the columns it has. Work it out per
case, in this order, and the first rule that matches wins:

| # | When the case… | Class | Who runs it |
|---|---|---|---|
| 1 | is Mode **X** (an SMS, an email outside the team, a webhook to an outside URL, an invoice, a data export, a delete or restore, a backup) | **H** | A person, with the owner's go-ahead (rule 4) |
| 2 | needs a phone's GPS, camera, selfie, passkey, QR scan, a kiosk tablet, a biometric device, a printer or a second physical person | **H** | A person. An agent may read the result afterwards |
| 3 | needs a password, an authenticator code, a sign-in code, a reset link, an API key, a webhook secret or a kiosk token to be typed, read or copied | **H** for that step | A person does that step in the browser window; the agent continues after it. A secret is never typed into chat, a prompt, a log or an environment variable |
| 4 | trips a rate limit or the host's firewall on purpose (RL-*, S-05, SEC-004) | **H** | A person, paced as rules 6 and 10 say |
| 5 | is Mode **W** | **C-W** | An agent may run it in QA Audit, Syntax or a `uat-` organisation only, in a session the owner has agreed to, without the read-only guard (section 5.1) |
| 6 | is Mode **R** | **C** | An agent, with the read-only guard on |
| 7 | is in 10, section 3 and has a script (section 4) | **A** | The Playwright CLI |

A case that mixes classes is split at the boundary: the agent runs its read-only steps, says which
step it stopped at and why, and the log records **Blocked** with "needs a person: step N" until the
tester finishes it.

## 3. One-time setup, and saving a session

On the tester's own machine, from a checkout of `main`:

```bash
cd src && npm ci && npx playwright install chromium
```

The scripted checks and the agent never sign in. **A person saves each persona's session, with
MFA, just before the run**, and the file stays on that machine:

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

**How it stays read-only.** Every spec imports `test` from `e2e-production/guard.ts`, which aborts
any API call other than GET, HEAD and OPTIONS before it leaves the browser (the token refresh
excepted), and lists what it blocked as a `blocked-write` note on the test. Traces and videos are off
because both record cookies; a failure leaves a screenshot in `src/test-results/production/`, which
shows QA data only and is checked for secrets before it is attached anywhere.

**Into the execution log.** The JSON report becomes rows of [10, section 2](10-TEMPLATES-AND-REFERENCE.md#2-execution-log):

```bash
cd src && node e2e-production/execution-log.mjs
```

A skipped check is **Not run** with its reason, never Pass. The tester is recorded as
`Playwright`; the person who ran it signs the log.

This suite is separate from `src/e2e/` (`playwright.config.ts`), which signs in with demo passwords
and writes data, and must never be pointed at production with its defaults
([01, section 3](01-TEST-PLAN.md#3-approach)).

## 5. Running cases with Claude Code and the Playwright MCP server

### 5.1 Connect the browser

Save the sessions first (section 3). Then, from the repository root, add a Playwright MCP server
for the persona the cases need, with the read-only guard:

```bash
claude mcp add ethr-prod-emp -- npx @playwright/mcp@latest --isolated --storage-state "$PWD/src/e2e-production/.auth/emp.json" --init-page "$PWD/src/e2e-production/mcp-read-only.ts" --output-dir "$PWD/src/test-results/production/mcp"
```

- `--isolated` keeps the browser profile in memory, so nothing outlives the session but the file
  you saved. `--storage-state` loads it.
- `--init-page` loads `mcp-read-only.ts`: the same guard as the scripts. **Leave it off only for a
  Mode W session the owner has agreed to**, and name that server so it cannot be mistaken
  (`ethr-prod-ta-WRITE`).
- The browser is headed by default. Keep it that way: the person needs the window for every
  class-H step (section 2), and an MFA prompt mid-case is answered there, never in chat.
- One server per persona; remove each when the pass ends (`claude mcp remove ethr-prod-emp`).
  Flags change between releases of the server: `npx @playwright/mcp@latest --help` lists the ones
  your version has (these were read from 0.0.84).

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

| The case says | The agent uses |
|---|---|
| Open, go to, sign out of a page | `browser_navigate` |
| Read the page, "shows", a label, a message | `browser_snapshot` (the accessibility tree; quote the text it finds) |
| Read the accessibility tree, "announced as", a role or name | `browser_snapshot` |
| The API answers 403, 422, a `problem+json` body | `browser_network_requests`, then `browser_network_request` for the one call: status, `title`, `detail`. Strip cookies and tokens before quoting |
| "Console clean", no error | `browser_console_messages` |
| At 375 px, on a phone | `browser_resize` to 375 x 812, or a server started with `--device "iPhone 13"` |
| A screenshot for the defect | `browser_take_screenshot`, after checking no secret, token or another organisation's data is on screen |
| Click, type, choose (Mode W only) | `browser_click`, `browser_type`, `browser_select_option`, `browser_fill_form`, in a QA organisation, in a session without the guard |

Messages are checked in English, the language of the Expected column; the guard sets the interface
to English. A case about Amharic or Afaan Oromoo text is read in that language, against the source
named in 09, section 5.

### 5.4 What the agent hands back

Rows in the format of [10, section 2](10-TEMPLATES-AND-REFERENCE.md#2-execution-log), with
`Claude Code` as the tester and the Claude Code session link in the notes, and under each row the
evidence: the URL, the quoted text or status, and the network call. A Fail comes with a draft in the
format of [10, section 1](10-TEMPLATES-AND-REFERENCE.md#1-defect-report-template) for the tester to
file; **the agent does not open the issue itself**. The person who supervised the session reviews
every Fail and every Pass on a P1 case before the log is signed.

## 6. Adding a scripted check

A check belongs in `src/e2e-production/` only when it is Mode R, needs no judgement, and is worth
running after every deploy.

- Import `test` and `expect` from `./guard`, never from `@playwright/test`, so the guard is on.
- Start the title with the case ID (`"S-02 …"`, `"ISO-003 …"`) and tag it with its priority and mode
  (`{ tag: ["@P1", "@R"] }`). `execution-log.mjs` reads the ID from the title.
- Use a saved session through `sessionFile()` and `sessionProblem()` in `env.ts`; never read a
  password from anywhere.
- Add its row to the table in section 4, and run `npx prettier --check`, `npx eslint` and
  `npx tsc --noEmit` in `src/` (the frontend gate covers these files).

## 7. What a green run does not prove

- **That a person can sign in.** The scripts start from a session a person made. S-08's sign-in,
  MFA and the sign-in limits are tested by hand (03, 09 section 3).
- **That a page is right.** S-10 proves a page loads with no error; whether its figures are correct
  is the case's job in 04 to 08.
- **Isolation.** A machine reading one organisation proves nothing about another. 09, section 2 is
  run by hand between QA Audit and QA Isolation on every release.
- **The phone, the kiosk and the devices.** Nothing here touches GPS, a camera, a passkey, a kiosk
  token or a biometric device.
- **Anything after the guard.** A write the guard blocked was never sent, so a case about what that
  write does has not been run.
