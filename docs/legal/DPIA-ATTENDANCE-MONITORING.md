# Data protection impact assessment: attendance monitoring

> **DRAFT — NOT LEGAL ADVICE.** Prepared 2026-11-25 from the code, for ETHR as processor. Each customer organisation, as controller, remains responsible for its own assessment of how it uses these features; this document is written so it can adopt or adapt it. Counsel has not confirmed it. Register row L13.

**Why an assessment is needed.** Art. 47 requires one before processing likely to put people's rights at high risk, naming large-scale sensitive data and systematic monitoring (class 2). Attendance in ETHR can do both: it can take a facial image at check-in (biometric, Art. 2(6), class 1), record where a person was, and do it every working day for whole workforces.

## 1. What is assessed

| Feature | What it records | Default | Who turns it on |
|---|---|---|---|
| **Selfie at phone check-in** | A photo, re-encoded at 1080 px, and its 64-bit perceptual hash | Off until the organisation enables it, **and** refused for any employee who has not consented (`PhotoConsent`, notice version `2026-10-17.2`) | Organisation; each employee separately |
| **Liveness check** | Only a result: passed, failed, no face, unavailable. The model runs on the phone (MediaPipe); no image leaves the phone for it | Off (`attendance_settings.liveness_check`) | Organisation |
| **GPS check-in** | Latitude, longitude, accuracy, compared with the organisation's attendance sites | Required for phone check-in | Organisation, by allowing phone check-in |
| **Presence checks** | Location at a moment during the shift | Organisation setting | Organisation |
| **Device enrolment** | On the terminal: fingerprint and face templates. In ETHR: only counts of them, the device user id, name, card number | Whenever a terminal is connected | Organisation; Syntax Technology technicians install terminals on site |
| **Kiosk PIN** | A PIN, stored hashed | Organisation setting | Organisation |
| **Device and network data** | IP address, user agent, keyed hash of the install id, passkey public key | Always, for every punch | — |

**Not done, and assessed as not done:** ETHR does not match a selfie to a person's face. It does not identify anyone from a picture, track location continuously, or make any automated decision about a person (Art. 31, class 2). A selfie is evidence a human reviews; the record and the punch time are what payroll reads.

## 2. Necessity and proportionality

| Question | Answer |
|---|---|
| What problem does it solve? | Buddy punching and false attendance, the reason organisations buy attendance systems. Payroll depends on it |
| Is there a less intrusive way? | Yes, and ETHR offers it: badge or card, PIN, QR, device punch without biometrics on ETHR's side. **A private employer must offer one of these to anyone who refuses a selfie or biometric enrolment**, or consent is not free (Art. 8(2), class 1) |
| Is the data kept no longer than necessary? | Selfies: 7 days by default (1 to 90, set by the organisation), then deleted after a 48-hour review ([`../operations/ATTENDANCE-PHOTO-RETENTION.md`](../operations/ATTENDANCE-PHOTO-RETENTION.md)). Presence checks: 90 days. Raw device events: 7 days. Punch times and attendance records: kept, because payroll and the attendance audit rely on them |
| Is the minimum taken? | Selfies are reduced on the phone before upload. Templates are never taken. The liveness check returns a word, not an image |
| Is the person told? | A consent notice in English, Amharic and Afaan Oromoo before the first selfie (`attendance.photo_consent.*`); withdrawal at `/profile/photo-consent`. No notice exists yet for device enrolment: that is the customer's, using the form in [`BIOMETRIC-DATA-POLICY.md`](BIOMETRIC-DATA-POLICY.md) |

## 3. Risks and what reduces them

| # | Risk to the person | Likelihood before measures | Measures in place | Residual |
|---|---|---|---|---|
| R1 | A selfie is seen by someone with no reason to see it | Medium | `attendance.viewPhotos` only, scoped to the holder's employees; every view audit-logged (`attendance.photo_viewed`); supervisors and finance do not hold it | Low |
| R2 | Selfies accumulate into a facial archive | High without retention | Deleted after the retention period; a hold needs a reason and only HR releases it; deletions audit-logged | Low |
| R3 | Consent is not free because refusing costs the job | High for private employers | Consent recorded per person with version and time; withdrawal is one click; the server refuses a selfie without consent. **Not in place:** anything that forces the organisation to offer an alternative. That obligation sits in the DPA (Annex 2B) | Medium, for private customers, until the customer confirms an alternative |
| R4 | Location data shows where a person goes | Medium | Location only at check-in and at presence checks, never continuously; presence checks deleted after 90 days | Low to medium: check-in locations stay with the attendance record |
| R5 | A breach exposes biometric data | Medium | Tenant isolation fails closed; MFA for platform staff; HSTS and strict CSP; [`../operations/DATA-BREACH-RESPONSE.md`](../operations/DATA-BREACH-RESPONSE.md). **Not in place:** encryption of photo files on disk | Medium. Encrypting stored selfies would lower it |
| R6 | Biometric data leaves Ethiopia | Low for live data, medium for backups | Hosting in Ethiopia. In production, backups go only to storage stated to be in Ethiopia and never to Google Drive (`BackupResidency`, register L16) | Low, provided the location statement is true |
| R7 | Enrolment on a terminal happens without a person's knowledge | Medium | ETHR stores no templates. **The terminal does, and enrolment is outside ETHR's control.** Syntax Technology technicians who enrol on site do so on the organisation's instruction (DPA Annex 4) | Medium: depends on the customer collecting written consent |
| R8 | A false integrity flag leads to a penalty without a human looking | Low | Integrity flags are for review; nothing deducts pay or disciplines on its own ([`../EMPLOYEE-LIFECYCLE.md`](../EMPLOYEE-LIFECYCLE.md), rule 3) | Low |

## 4. Actions that would lower the residual risk

Product work, tracked separately, not promised here:

1. Encrypt stored selfie files at rest (R5).
2. Record, per organisation, that a non-biometric method is enabled before selfies can be required (R3).
3. A consent record for device enrolment, if the organisation wants ETHR to hold it (R7).

## 5. Sign-off

| | Name / role | Date | Decision |
|---|---|---|---|
| Owner, for Syntax Technology | `[confirm]` | | Residual risk accepted / not accepted |
| DPO | `[confirm]` | | |
| Counsel | `[confirm]` | | Whether prior consultation with the Authority is required (Art. 48, class 3) |

Review this assessment when any feature in §1 changes, and at least once a year.
