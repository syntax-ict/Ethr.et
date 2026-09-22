# Request to Ethio Telecom — bulk SMS (A2P) service and credentials

**Status: draft for the owner to send. Not sent.**
**Placeholders in `«guillemets»` must be filled before sending.**

> **This is NOT the hosting ticket, and must not be merged into it.**
> [`deployment/ETHIO-TELECOM-SUPPORT-REQUEST.md`](../deployment/ETHIO-TELECOM-SUPPORT-REQUEST.md)
> goes to **hosting support** and asks four questions about the Plesk subscription
> (Scheduled Tasks, higher plans, a database `TRIGGER` grant, SSH). Its first ask is the
> one blocker for the whole deployment.
>
> This request goes to a **different desk — the SMS / VAS (A2P, bulk messaging) business
> unit** — and is commercial rather than technical support. Attaching it to the hosting
> ticket would route it to people who cannot answer it, and would dilute the cron ask that
> everything else is waiting on. Send them separately.

---

## Cover details

| | |
|---|---|
| From | «Company legal name» |
| TIN | «Taxpayer Identification Number» |
| Business licence | «Number» |
| Contact | «Name, title, phone, email» |
| Date | «Date» |
| Existing account | Hosting subscription `ethret` on `lin6.ethiotelecom.et`, domain `ethr.et` |
| Subject | Application for A2P / bulk SMS service, sender ID registration and API credentials |

**To:** Ethio Telecom — «SMS / VAS / Enterprise Business division»

---

## Letter

Dear Sir/Madam,

We operate **ETHR**, a human-resources platform used by Ethiopian employers. The product
sends transactional SMS to employees, and we would like to apply for A2P (application-to-
person) bulk SMS service.

Our integration is **already built and unit-tested against a mocked gateway**; what we lack
is a live account. We set out below exactly what we need, and exactly what we send, so that
you can tell us which service and tariff apply.

### 1. What we need from you

| # | Item | Why |
|---|---|---|
| 1 | **Service confirmation** — do you offer A2P / bulk SMS to a business customer of our size? | Everything else follows from this |
| 2 | **API type** — we are built for **HTTP**; do you offer an HTTP/REST endpoint, or is the service **SMPP** only? | Decides whether our existing driver works as-is or needs replacing — see §3 |
| 3 | **Endpoint URL** for the HTTP API, and its documentation | We have no published specification |
| 4 | **Credentials** — username and password (or API key/token, if that is the scheme) | See §4 for exactly where each is used |
| 5 | **Sender ID registration** — we request the alphanumeric sender ID **`ETHR`**; please confirm availability, the registration process, any documentation required, and the lead time | Currently our default; we can change it if `ETHR` is unavailable |
| 6 | **Tariff** — price per message, minimum commitment, billing cycle, and whether pricing differs by message type or volume band | Needed before we can commit |
| 7 | **Delivery reports (DLR)** — are they available, by callback/webhook or by polling? | See §5 — we do not consume them today, and want to know whether we should build for them |
| 8 | **Rate limits** — messages per second/minute and any daily cap | So we can throttle rather than be throttled |
| 9 | **Test / sandbox credentials**, if available, before production | Lets us verify the live handshake without sending to real subscribers |
| 10 | **Coverage** — Ethio Telecom subscribers only, or also other Ethiopian networks? | Our employees may be on any network |

### 2. What we send, and how much

**Message types.** Two, both strictly transactional — **no marketing, no promotional
content, no unsolicited messages:**

| Type | Trigger | Content | Status in the product |
|---|---|---|---|
| **One-time passcode (OTP)** | An employee signs in by phone number | A 6-digit numeric code, valid 5 minutes | **Live.** This is the only SMS the product sends today |
| **Workforce notifications** | Payslip issued, leave request approved/rejected, approval reminders | Short status text, no attachments | **Built but not yet emitting.** The delivery channel exists; see §5 |

**Volume.** We cannot give a firm figure and would rather say so than invent one. What we
can state:

- the product enforces a hard cap of **5 SMS per user per day**, in the application itself,
  because SMS is the only channel with a per-message cost;
- OTP codes are **6 digits, valid 5 minutes**, with at most **3 outstanding** per user;
- each message is **a single segment** — GSM-7, well under 160 characters;
- current live tenants: «number»; employees across them: «number».

We would prefer to start on a **small or pay-as-you-go commitment** and scale, rather than
commit to a volume we cannot yet forecast.

**Recipients.** Ethiopian mobile numbers only. We normalise every number to
`251` + 9 digits before sending, and reject anything whose first subscriber digit is not
`7` or `9`.

### 3. Our integration, so you can tell us whether it fits

Our driver performs a **form-encoded HTTP POST** to a single endpoint with these fields:

```
username   the account username you issue
password   the account password you issue
from       the registered sender ID  (default: ETHR)
to         recipient MSISDN, normalised to 251XXXXXXXXX
text       the message body
```

It treats **any 2xx response as accepted** and anything else as rejected.

**Please tell us where this differs from your actual API** — field names, authentication
scheme, response format, or whether you require SMPP rather than HTTP. If the shape differs
we will adapt; we are asking so that we adapt once, against your specification, rather than
guessing.

### 4. Where each credential is used

So you can see that credentials are held server-side only and never reach a browser or a
mobile device:

| Credential | Environment variable | Consumed by |
|---|---|---|
| Endpoint URL | `ETHIOTELECOM_SMS_ENDPOINT` | `api/app/Services/Sms/EthioTelecomSmsSender.php` |
| Username | `ETHIOTELECOM_SMS_USERNAME` | same |
| Password | `ETHIOTELECOM_SMS_PASSWORD` | same |
| Sender ID | `ETHIOTELECOM_SMS_SENDER_ID` | same |
| Request timeout | `ETHIOTELECOM_SMS_TIMEOUT` | same |

The driver **reports itself unavailable** until endpoint, username and password are all
configured, so the product degrades to a clear "SMS sign-in isn't available" screen rather
than failing obscurely. It is enabled only by an explicit `SMS_DRIVER=ethiotelecom` switch.

We would be grateful if credentials were sent by a channel other than plain email —
«preferred secure channel».

### 5. Two things we are deliberately telling you we have not built

- **Delivery reports.** There is no DLR handling in our code today: we treat the HTTP
  response as the outcome and go no further. If you provide delivery reports, we would like
  to consume them — please tell us the mechanism and we will build for it.
- **Notification SMS.** The channel that routes workforce notifications to SMS exists, but
  **no notification implements the SMS body yet**, so in practice OTP is the only live
  sender. Expect our initial volume to be OTP-only, rising once notifications are wired.

### 6. Commercial

Please send the **service agreement, tariff sheet, and any KYC or documentation
requirements** (business licence, TIN, letter of authority). We can supply
«company documents» on request.

We already hold a hosting subscription with Ethio Telecom (`ethret`, `ethr.et`) and would
be glad to have both services billed to the same account if that is possible.

We look forward to your response.

Yours faithfully,

«Name»
«Title»
«Company legal name»
TIN «Taxpayer Identification Number»
«Phone» · «Email»

---

## Where these details come from

| Claim | Source |
|---|---|
| HTTP form POST; `username`/`password`/`from`/`to`/`text` | `api/app/Services/Sms/EthioTelecomSmsSender.php:53-62` |
| Driver unavailable until endpoint + username + password set | `api/app/Services/Sms/EthioTelecomSmsSender.php:30-32` |
| Any 2xx accepted, else rejected | `api/app/Services/Sms/EthioTelecomSmsSender.php:64-74` |
| Config keys and `ETHR` sender-ID default | `api/config/sms.php:30-36` |
| Opt-in via `SMS_DRIVER` | `api/config/sms.php:16` |
| 5 SMS/day per user cap | `api/config/sms.php:28` |
| OTP: 6 digits, 5-minute TTL, 3 outstanding | `api/app/Services/Auth/OtpService.php:23,25,28` |
| MSISDN `251` + 9 digits, leading 7 or 9 | `api/app/Services/Sms/SmsNumber.php:30,41-43` |
| SMS notification channel exists | `api/app/Notifications/Channels/SmsChannel.php` |
| **No** notification implements `toSms()` | measured: zero matches for `function toSms` under `api/app/Notifications/` |
| **No** delivery-report handling | measured: zero matches for DLR/delivery-report/SMS-callback under `api/app/` |
| Live gateway unverified, blocked on credentials | `docs/ENTERPRISE_ROADMAP.md` item 7.2 |

## Open placeholders — cannot be determined from this repository

- Company legal name, TIN, business licence number, contact person, date
- The correct Ethio Telecom division and its address
- **Expected monthly volume** — no production telemetry exists in this repository; the
  5/user/day cap is a ceiling, not a forecast
- Current live tenant and employee counts
- Preferred secure channel for receiving credentials
- Whether Ethio Telecom's A2P API is HTTP or SMPP — **this is the question that decides
  whether the existing driver is usable at all**
