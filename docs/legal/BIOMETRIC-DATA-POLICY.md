# Biometric data policy

> **DRAFT — NOT LEGAL ADVICE.** Drafted 2026-11-25 from the code. Counsel has not confirmed it. Register row L25. The consent form in §4 needs a human Amharic translation before use (register row L27).

Biometric data, "facial images, fingerprints, iris scans, or any other similar personal data" (Art. 2(6), class 1), is sensitive personal data (Art. 2(5)(b), class 1). Its processing is prohibited unless an exception in Art. 9(2) applies (class 1). This policy states exactly what ETHR holds, because a policy that over-declares is as wrong as one that under-declares.

## 1. What ETHR holds, and what it does not

| | Held by ETHR? | Where it is | Evidence |
|---|---|---|---|
| **Fingerprint templates** | **No** | On the attendance terminal | `api/app/Services/Device/AdmsProtocol.php` counts template lines and discards them; the enrolment snapshot stores `fingerprint_count` only |
| **Face templates from terminals** | **No** | On the terminal | As above; `face_registered` is a yes/no |
| **Face snapshots a terminal attaches to an event** (some Hikvision models) | **No** | On the terminal | Only the event's text part is kept, and its raw text is nulled once processed and deleted after 7 days |
| **Attendance selfies** taken on the employee's phone | **Yes**, when the organisation enables them and the employee consents | ETHR's server, in Ethiopia | `attendance_records.photo_path`; [`../operations/ATTENDANCE-PHOTO-CONSENT.md`](../operations/ATTENDANCE-PHOTO-CONSENT.md) |
| A 64-bit perceptual hash of each selfie | Yes, deleted with the photo | ETHR's server | `ImageHash` |
| **Liveness result** | Yes, as a word (passed, failed, no face, unavailable) | ETHR's server. The image used for the check never leaves the phone | `src/src/lib/attendance/liveness.ts` |
| Employee profile photo | Yes, if the organisation uploads one | ETHR's server | `employees.photo_path`. Treated as an ordinary photo, not a selfie; counsel to confirm whether it is biometric data under Art. 2(6) (class 3) |
| Passkey (WebAuthn) for phone binding | Only the public key and credential id. The fingerprint or face that unlocks the phone never leaves it | The phone | `PasskeyVerifier` |

ETHR **does not** compare a selfie with anyone's face, identify a person from a picture, or decide anything about a person automatically from biometric data.

## 2. Rules for ETHR

1. **Collect only what the organisation turned on**, and a selfie only from someone who has consented. The server refuses a selfie from anyone without a recorded consent (`PhotoConsent`, notice version `2026-10-17.2`).
2. **Keep selfies for the organisation's retention period only**: 7 days by default, 1 to 90 allowed, then a 48-hour review, then permanent deletion unless HR holds the record for a stated reason ([`../operations/ATTENDANCE-PHOTO-RETENTION.md`](../operations/ATTENDANCE-PHOTO-RETENTION.md)).
3. **Show selfies only to holders of `attendance.viewPhotos`**, within their scope, and log every view (`attendance.photo_viewed`).
4. **Never copy templates off a terminal.** When a person resigns, is terminated or retires, ETHR sends the terminal a command to remove them, where the terminal accepts commands ([`../DEVICE_INTEGRATION.md`](../DEVICE_INTEGRATION.md)).
5. **Keep it in Ethiopia** (Art. 22(1), class 1). Backups are the open gap: register row L16.
6. Treat any incident involving selfies as a breach of sensitive data under [`../operations/DATA-BREACH-RESPONSE.md`](../operations/DATA-BREACH-RESPONSE.md).

## 3. Rules for the customer organisation (controller)

The DPA ([`DATA-PROCESSING-AGREEMENT.md`](DATA-PROCESSING-AGREEMENT.md) Annex 2) carries these as obligations:

1. **Choose your basis.** A public body may rely on its lawful non-commercial objectives (Art. 9(2)(c), class 1). Anyone else needs each person's **prior, written consent, specific to the purpose** (Art. 9(2)(a), class 1).
2. **Offer an alternative** that uses no biometrics: card, PIN, QR or kiosk. A private employer must; a public body should. Consent is valid only if it is free (Art. 8(2), class 1). ETHR's own consent screen already tells the employee they may use a kiosk or QR code instead, if the organisation offers one.
3. **Enrolment on a terminal is yours.** ETHR never sees it. Collect consent before anyone is enrolled, including when Syntax Technology technicians do the enrolment on site.
4. **Honour withdrawal.** An employee withdraws selfie consent in ETHR at `/profile/photo-consent`. To withdraw from a terminal, delete their templates from it.

## 4. Consent form for terminal enrolment (template)

For organisations relying on Art. 9(2)(a). One copy for the employee, one kept by HR. `[Amharic version: to be produced by a human translator and checked by counsel.]`

> **Consent to biometric attendance**
>
> Organisation: `[name]`  Employee: `[name]`  Employee number: `[number]`
>
> `[Organisation]` asks to record my **fingerprint** / **face** (circle) on its attendance terminal at `[site]`, so that I can record my arrival and departure.
>
> I understand that:
> 1. My fingerprint or face template is stored **on the terminal only**. It is not sent to ETHR or anyone else. ETHR receives only the time I clock in or out.
> 2. It is used **only to record my attendance**, and for no other purpose.
> 3. I do not have to agree. If I refuse, I can record my attendance by `[card / PIN / QR / kiosk]` instead, and refusing will not affect my employment, pay or assessment.
> 4. I can withdraw this consent at any time by telling `[HR contact]`. My template will then be deleted from the terminal within `[confirm]` working days.
> 5. My template is deleted from the terminal when I leave the organisation.
>
> ☐ I agree    ☐ I do not agree
>
> Signature: ______________  Date (Ethiopian and Gregorian): ______________
