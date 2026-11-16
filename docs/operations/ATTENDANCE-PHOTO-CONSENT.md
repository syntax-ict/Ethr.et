# Attendance photo consent

| At a glance | |
|---|---|
| **Purpose** | Explains how an employee's consent to an attendance selfie is asked for, enforced and recorded. |
| **Open it when** | An employee or an organisation asks about selfie consent, or you need to know what the server enforces. |
| **For** | Platform operator; owner |
| **Organisation types** | Same for every kind. Consent is collected the same way for every organisation. [`ORGANISATION-TYPES.md`](ORGANISATION-TYPES.md) |
| **Source of truth** | The code (`App\Support\PhotoConsent`) for behaviour, and Proclamation No. 1321/2024 as read for this product for the law. |

An attendance selfie is a photo of a face. The Personal Data Protection
Proclamation No. 1321/2024 counts facial images as biometric data (Art. 2(6))
and biometric data as sensitive (Art. 2(5)), and sensitive data is processed
only on a lawful basis. Written consent specific to the purpose, given before
processing, is the basis ETHR collects for every organisation. Added
2026-10-17.

## What the employee sees

- **On the phone:** the first time they tap *Take a selfie*, a notice opens
  before the camera does. It says what is taken (one photo per mobile check-in
  or check-out), why (to confirm it is them, nothing else), who sees it (HR and
  their department manager, every view recorded), where and how long it is
  kept (with the attendance record, on servers in Ethiopia, for the
  organisation's retention period), how to withdraw, and what to do
  instead (kiosk or QR code, where offered). *I agree* records consent and
  opens the camera; *Not now* opens nothing.
- **In the profile:** Profile, Preferences, *Attendance photo* shows whether
  they agreed and when, the same notice, and a button to agree or withdraw.

Withdrawing stops new selfies from then on. Photos already taken are deleted
at the end of the retention period
([`ATTENDANCE-PHOTO-RETENTION.md`](ATTENDANCE-PHOTO-RETENTION.md)); erasing
them sooner is a request to the organisation (Art. 28), which an
administrator handles.

## What the server enforces

| Where | Rule |
|---|---|
| `POST /attendance/mobile/check-in`, `/check-out` | A request carrying a photo from someone who has not agreed is refused, 422 on `photo` |
| `POST /attendance/sync` | An offline punch is kept; its photo is stored only if the employee has agreed, and dropped otherwise. A punch without a required photo is flagged as before |
| `GET /attendance/my-shift` | `policy.photo_consent` tells the phone whether to ask |
| `GET`/`POST`/`DELETE /profile/photo-consent` | Read, give and withdraw, always for the signed-in user only |

An organisation that requires a selfie (`mobile_photo_required`) still gets
one from every employee who agrees. An employee who does not agree cannot
check in on the phone while a selfie is required, and uses a kiosk or QR code.

## The record

Consent is stored on the user as the notice version and the time it was
given (`users.preferences.attendance_photo_consent`, a key the preferences
endpoint neither reads nor writes). Every grant and withdrawal is also an
audit-log entry, `profile.photo_consent_given` or
`profile.photo_consent_withdrawn`, which cannot be edited or deleted and is
the evidence that outlives a change of mind.

`PhotoConsent::VERSION` names the notice. When the notice changes materially
(a new purpose, a new audience, a different place of storage), raise the
version: consent to the older notice stops counting and everyone is asked
again. That happened once, the same day the feature shipped: `2026-10-17.2`
came with the retention policy, which narrowed the audience to HR and the
department manager and limited how long a selfie is kept.

## Checks made with the photo and the phone (2026-10-24)

Cheating protection (`AttendanceRiskScorer`) uses two things alongside the
photo, both inside the purpose the employee agreed to (confirming the punch
is theirs), so no new consent version was issued:

- **A photo fingerprint.** A 64-bit hash of the picture's light and shade
  (`ImageHash`), compared with recent selfies to catch one that repeats an
  earlier photo or copies a colleague's. It describes the picture, not a
  face: it cannot identify anyone or be turned back into the image. It is
  deleted with the photo at the end of the retention period.
- **An install id.** A random value the app keeps on the phone, stored only
  as a keyed hash, to tell that two people punched on one phone. It is not
  the phone's hardware identity and identifies nothing outside ETHR.

Optional on-device face checks (liveness) run entirely on the phone: no
image, landmark or face template leaves it, only whether the check passed.
Matching a selfie to a person's face is **not** done; it would need its own
legal review and consent.

## What ETHR does not decide

The employer is the data controller. Whether it also relies on another lawful
basis, its registration with the Ethiopian Communications Authority (Art. 33)
and its breach notices (Arts. 43 and 44) are its own duties. This feature
gives it the consent record; it does not make that choice for it.

## Organisation types

- Consent is collected, enforced and recorded the same way for every kind of organisation and every
  industry. Nothing in `App\Support\PhotoConsent` or the check-in requests reads either.
- No industry's starting setup switches selfies on. `mobile_photo_required` defaults to false
  (`api/app/Models/AttendanceSetting.php`), `AttendanceBaseline` does not set it, and the only place
  `WorkforceProfileCatalog.php` sets it true is the `field_distributed` workforce profile, which no
  industry in `IndustryCatalog.php` uses by default.
- An organisation can still switch selfies on in its attendance settings, and consent is then
  required whatever its kind. See [`ORGANISATION-TYPES.md`](ORGANISATION-TYPES.md).

## Deploying it

No migration: consent lives in the existing `users.preferences` JSON. The
release needs `clear` and `optimize` as usual.
