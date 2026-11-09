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
   [11, section 2](11-CLAUDE-CODE-AND-PLAYWRIGHT.md#2-which-cases-a-machine-may-run). Mode R with
   the read-only guard on is yours. Mode W only when the person has said so for this session, in a
   browser without the guard. Mode X, anything on a phone or device, and every rate-limit or
   firewall probe: hand back.
3. **Never handle a secret.** Do not ask for, type, read aloud, copy, log or screenshot a password,
   authenticator or sign-in code, reset link, API key, webhook secret, kiosk token or session file.
   When a step needs one, stop and ask the person to do that step in the browser window, then
   continue. Do not read or print anything under `src/e2e-production/.auth/`.
4. **Do not trip the host's firewall.** No scanners, no loops, no parallel browsers, no request
   flood. Never send a `POST` with no body. Secret-path probes (S-05, SEC-004): not yours. If the
   site stops answering, stop: your own machine's fetch is not an outside check (rule 10).
5. **Record, do not fix.** A result goes in the execution-log format of
   [10, section 2](10-TEMPLATES-AND-REFERENCE.md#2-execution-log), with the evidence (URL, quoted
   text, status and `problem+json` body with cookies and tokens removed). A Fail gets a draft defect
   in the format of 10, section 1. Do not open GitHub issues, change settings, or retry a failing
   write to make it pass.
6. **A skipped or blocked step is not a pass.** Say which step you stopped at and why.

## Editing the pack

- Facts come from the code, with a file reference: messages from `api/lang/en/*.php`, limits from
  the class that enforces them. Never write an expected result from memory or from another document.
- Keep case IDs stable; add new cases at the next free number and never renumber. Name the PR in the
  case ("(#669)") and add its row to [01, section 2.3](01-TEST-PLAN.md#23-changes-since-the-first-edition),
  so `scripts/qa-pack-coverage.sh` stops listing it.
- Move the `qa-pack-baseline` marker in [README.md](README.md) only when every file has been
  brought up to date.
- When the pack and the product disagree, say which is wrong; do not quietly change the expectation
  to match what production does.
