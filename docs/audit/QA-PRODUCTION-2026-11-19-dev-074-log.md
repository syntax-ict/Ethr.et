# Production QA execution log, 2026-11-19: DEV-074, held punches that record themselves

Case: DEV-074 in [`docs/production testing/05-E2E-ATTENDANCE-SHIFTS-DEVICES.md`](<../production testing/05-E2E-ATTENDANCE-SHIFTS-DEVICES.md>). Format: [10, section 2](<../production testing/10-TEMPLATES-AND-REFERENCE.md#2-execution-log>). The measurement it led to is [BASELINE 25u](BASELINE.md).

| | |
|---|---|
| Release under test | `v2026.11.19.6`, commit `979221c23c` |
| Organisation | QA Audit, the organisation's receives-only Hikvision test device |
| When | 2026-11-19, 17:00 to 17:17 UTC |
| Tester | Claude Code |

## How the punches were sent

Six punches for three invented badges (DEV74A, DEV74B, DEV74C; check-in about 08:30 and check-out about 17:05 Addis time on 2026-11-18) were sent from the signed-in page to the device's own webhook address, without reading its token. A browser POST needs the session's XSRF header (419 without), and the page's CSP blocks posting cross-origin to www, so this is not exactly how a terminal connects. All six answered `accepted`. They were held after the next queue drain (three badges, two punches each) and the device went Online.

## Results

| Badge | Setup | Result | Notes |
|---|---|---|---|
| A | DEV74A typed into one test employee's Badge / device ID at 17:03:45Z | Pass | The card read "This is :name" and "Matched by its number. The held punches are being recorded." Recorded at 17:05:52Z: one record for 2026-11-18, 08:30 to 17:05 Addis, source biometric. Audit `device.held_punches_auto_recorded`, reasons `badge_number`, 2 replayed, 0 refused |
| B | Two holders: the badge number on one test employee, the employee number on another | Pass | Verdict `ambiguous`, `recording_itself` false, still held after the 17:10Z and 17:15Z sweeps, no attendance for either. Both values were put back afterwards |
| C | Only a name from the terminal that fits one person | Pass | Verdict `new`, "Possibly :name", still held after both sweeps |

Health after the run: healthy, 0 failed jobs.

## Findings

1. **Case text error.** DEV-074 said to type B into two people's Badge number. That cannot be done: the API refuses a badge number a second person already holds (422, "The badge number has already been taken."). The case now gives B two holders as a badge number on one person and an employee number on another.
2. **Defect, fixed in the pull request that carries this log.** The audit entry `device.held_punches_auto_recorded` carried the user, IP address and browser of the signed-in person whose page request drained the queue, who had done nothing. The cause and the fix are in BASELINE 25u. Existing rows were not rewritten.
3. **Not a defect, recorded so nobody reports it again.** A refused badge number is shown as a short toast at the top right of the employee page, and nothing stays under the field.

## Left in QA Audit

One test employee keeps badge DEV74A and that one day of attendance. DEV74B and DEV74C stay held, two punches each.
