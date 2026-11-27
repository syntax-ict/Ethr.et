# Service Level Agreement (template)

> **DRAFT — NOT LEGAL ADVICE.** Drafted 2026-11-25. Counsel has not confirmed it. Register row L24. Every `[confirm]` is the owner's to set; the figures shown are proposals.

## Why this SLA promises support, not uptime

The Terms of Service already say ETHR offers **no contractual uptime guarantee** (`src/src/lib/legal/documents.ts`, pinned by `legal-pages.test.tsx`). That stays true until there is evidence to promise more:

- `CUTOVER READY = NO` ([`../deployment/CUTOVER-CHECKLIST.md`](../deployment/CUTOVER-CHECKLIST.md)).
- A restore from backup has not been rehearsed on the production host.
- ETHR runs on shared hosting, where the provider's own maintenance is outside ETHR's control.

A 99.9% figure would be a number nobody can stand behind, and ETHR's landing page once shipped an invented one. So this agreement commits to what ETHR controls: how fast it answers, how fast it acts, and how much data a failure can cost. **Uptime is reported as a target**, measured and published, and becomes a commitment by amendment once the cutover gates pass and a year of measurements supports one.

## 1. Support hours

`[confirm: Monday to Friday, 08:30 to 17:30 East Africa Time, excluding Ethiopian public holidays]`. Severity 1 is answered outside these hours.

Requests go through **Get help** inside ETHR, which records the request and its time ([`../operations/SUPPORT.md`](../operations/SUPPORT.md)), or `[confirm: phone and email]`.

## 2. Severity and response

| Severity | Means | First response | Updates | Work |
|---|---|---|---|---|
| **1: Critical** | ETHR is unavailable to the organisation; payroll cannot be run on the day it is due; a suspected personal data breach | `[confirm: 2]` hours, any time | Every `[confirm: 4]` hours | Continuous until restored or a workaround is in place |
| **2: High** | A major function (attendance, leave, payroll) fails for many users, with no workaround | `[confirm: 4]` support hours | Daily | Within support hours |
| **3: Normal** | A function fails for some users, or a workaround exists | `[confirm: 1]` support day | As progress is made | Scheduled |
| **4: Question** | How-to, configuration, feature request | `[confirm: 2]` support days | — | — |

"First response" means a person acknowledging the problem and saying what happens next, not an automatic reply. A suspected breach is also handled under the DPA §9, whose clock runs separately.

## 3. Data protection objectives

| | Target | What it rests on |
|---|---|---|
| **Recovery point**: the most data a failure can lose | `[confirm: 24]` hours | Nightly backup at 01:00 (`ethr:backup`) ([`../deployment/BACKUP-RESTORE.md`](../deployment/BACKUP-RESTORE.md)) |
| **Recovery time**: from declaring a restore to service back | `[confirm: 8]` hours | **Not yet measured on the production host.** This row stays a target until the rehearsal is done and timed |

## 4. Availability target (not a commitment)

ETHR aims for `[confirm: 99.5]`% monthly availability, measured from outside, excluding announced maintenance. The figure achieved each month is reported to the customer on request. If a month falls short, ETHR explains why and what it changed. No service credit applies while this section is a target.

## 5. Maintenance

Planned maintenance is announced at least `[confirm: 48]` hours ahead, scheduled outside `[confirm: 08:00 to 18:00]` East Africa Time, and avoids the last three working days of the month (payroll). Emergency security fixes may be applied without notice; the customer is told afterwards.

## 6. What the customer does

Name at least one administrator as the support contact. Report problems with what was being done, when, and by whom. Keep its own users' access current.

## 7. Exclusions

The customer's own network, devices, attendance terminals not installed by Syntax Technology, and anything caused by the customer's configuration or instructions. Failures of the hosting provider count against the availability figure: the customer cares whether ETHR works, not whose fault it is.
