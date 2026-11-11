# Rules for an agent running this pack

Claude Code loads this file when it works in `docs/production testing/`. It applies to every agent
that runs, reads or edits these cases. The human version of each rule is in
[01, section 9](01-TEST-PLAN.md#9-production-safety-rules); how to run the pack by machine is
[11](11-CLAUDE-CODE-AND-PLAYWRIGHT.md).

## Running cases on production

1. **Production is real.** `www.ethr.et`, `admin.ethr.et`, every `{slug}.ethr.et` and every
   organisation's own domain belong to real customers. Sign in, click, type or call an API only in
   **QA Audit (`qa-audit`), Syntax (`syntax`) or a `uat-` organisation**, and read the
   organisation name in the header before every step. Seeing another organisation's data is a P1
   security defect: stop and tell the person at once.
2. **Decide what you may run before you run it**, with the table in
   [11, section 2](11-CLAUDE-CODE-AND-PLAYWRIGHT.md#2-which-cases-a-machine-may-run). Mode R is
   yours. Mode W only when the person has said so for this session. Mode X, anything on a phone or
   device, and every rate-limit or firewall probe: hand back. Read the steps, not only the Mode
   column: a case marked R that sends, saves or submits is not R. Say so and do not run it.
3. **Know which browser you are in, and say it.**
   - **The guarded Playwright browser** (a server you were told to use, named `ethr-prod-…`, started
     with the guard and with service workers blocked) is the default for every signed-in Mode R
     case. It cannot send a write.
   - **Chrome** (the Claude in Chrome extension) has no guard. Use it only on public pages signed
     out, in a Mode W session the person has agreed to, or when the person asks for it. Confirm
     with the person that it is the Chrome profile kept for QA, and stop if the profile is signed
     in to the platform console or to an organisation that is not a QA one. **Never drive a
     platform operator (PA) session in Chrome.** In a Mode R session there, click only to move or
     reveal (a link, a tab, a menu, a filter); never a control that saves, sends, approves,
     deletes, punches, exports or signs out.
   - **Any other browser** (a general Playwright server or plugin, the desktop app's built-in
     browser) has neither the saved session nor the guard. Public pages signed out only, and only
     when the first two are not available; say which one you used.
4. **Never handle a secret.** Do not ask for, type, read aloud, copy, log or screenshot a password,
   authenticator or sign-in code, reset link, API key, webhook secret, device or kiosk token, or
   session file. When a step needs one, stop and ask the person to do that step in the browser
   window, then continue. Do not read or print anything under `src/e2e-production/.auth/`, and do
   not read cookies or stored tokens from a page.
5. **Everything goes through the browser.** The guard does not cover your shell. Do not call the
   production API with `curl`, a script or a page's own `fetch` to make a case work, and never
   re-send a request to read its answer. The one exception is a single GET or HEAD that the case
   itself asks for.
6. **Do not trip the host's firewall.** No scanners, no loops, no parallel browsers, no request
   flood. Never send a `POST` with no body. Secret-path probes (S-05, SEC-004): not yours. If the
   site stops answering, stop: your own machine's fetch is not an outside check (rule 10).
7. **Record, do not fix.** A result goes in the execution-log format of
   [10, section 2](10-TEMPLATES-AND-REFERENCE.md#2-execution-log), with the evidence (URL, quoted
   text, status and `problem+json` body with cookies and tokens removed) and the browser it came
   from (`guarded`, or `Chrome, unguarded`). A Fail gets a draft defect in the format of 10,
   section 1. Do not open GitHub issues, change settings, or retry a failing write to make it pass.
8. **A skipped or blocked step is not a pass.** Say which step you stopped at and why.

## Editing the pack

- Facts come from the code, with a file reference: messages from `api/lang/en/*.php`, limits from
  the class that enforces them. Never write an expected result from memory or from another document.
- **Write cases for what is on `main`.** A change in an open pull request may get a case, marked
  with the PR and "(open)", because it can be read and will be reviewed. Work that is not committed
  or has no pull request gets none: it can change before anyone tests it. Say it is missing instead.
- Each group of cases says which commit must be live before it is run (S-01). What is merged, what
  is built and what is live are three different commits more often than not: read
  `scripts/release-status.sh https://www.ethr.et` rather than assuming.
- Keep case IDs stable; add new cases at the next free number and never renumber. Name the PR in the
  case ("(#669)") and add its row to [01, section 2.3](01-TEST-PLAN.md#23-changes-since-the-first-edition),
  so `scripts/qa-pack-coverage.sh` stops listing it. Run it with `--all` as well: a pull request
  titled `test:` or `chore:` can carry a fix a tester would see (#688 did).
- Move the `qa-pack-baseline` marker in [README.md](README.md) only when every file has been
  brought up to date.
- When the pack and the product disagree, say which is wrong; do not quietly change the expectation
  to match what production does.
