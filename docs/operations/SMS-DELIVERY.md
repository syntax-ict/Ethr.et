# SMS delivery

How ETHR sends text messages (one-time sign-in codes and SMS notifications), where that is
configured, and what to do on the day ETHR subscribes to an SMS provider.

**State on 2026-10-14: ETHR has no SMS subscription.** SMS is off, the sign-in page offers
codes by email only, and nothing in this document has been exercised against a real gateway.
Every behaviour below is verified against faked HTTP responses (`api/tests/Feature/Sms/`).
Whether a given provider accepts what ETHR sends can only be proven on subscription day, with
the console's test button.

## Where it is configured

**Platform console → SMS delivery** (`/admin/sms`), super admin with MFA only. No deploy and no
code change is needed to turn SMS on.

Precedence, highest first:

1. **The console.** Once a primary gateway is saved there, the console alone decides. SMS is
   available only while the switch is on, the primary gateway has passed a test send, and
   today's count is under the daily cap.
2. **`.env`, as a fallback.** While no gateway is saved in the console, `SMS_DRIVER=ethiotelecom`
   with `ETHIOTELECOM_SMS_ENDPOINT`, `_USERNAME`, `_PASSWORD` and `_SENDER_ID` describes one
   gateway: a form POST of `username`, `password`, `from`, `to` (as `2519…`) and `text`, sent as
   soon as the four values are set. This is the shape the old `EthioTelecomSmsSender` guessed
   at. That class is gone and the same request is now configuration, so an existing deployment
   behaves as before. The page shows a notice while `.env` is in effect.
3. **Nothing.** SMS reports itself unavailable. Under `SMS_DRIVER=log` (development), a
   message is written to the log instead.

`SMS_DAILY_CAP` (default 500) is only the starting value. The console's daily limit replaces
it once saved.

## The gateway form

There are no provider presets, deliberately. Ethio Telecom publishes no HTTP SMS API (its
partner page asks for SMPP or SS7), and a preset labelled with an operator's name would suggest
a tested integration that does not exist. Every field comes from the provider's own
documentation:

| Field | Choices |
|---|---|
| Request | POST with a JSON body, POST with a form body, or GET with a query string |
| Authentication | none, API key in a named header, Bearer token, username and password fields, HTTP basic |
| Field names | recipient, message, sender ID (and the username/password fields, for that mode) |
| Number format | `2519…`, `+2519…` or `09…` (Safaricom numbers are written the same way with `7`) |
| Counts as sent when | HTTP 2xx **and**, if set, a JSON value at a path or a substring of the body |

Set the success rule whenever the provider documents one. Many gateways answer `200` to a
message they rejected (no balance, unregistered sender ID), and status alone would count that as
sent.

The credential is write-only. It is stored encrypted, never returned by the API (responses
carry `secret_set` only) and never written to the audit log. To keep the stored credential,
leave the field empty when saving.

## Turning it on

1. Save the primary gateway.
2. Send a test SMS to a mobile number you hold. The page shows the gateway's HTTP status and
   its response, with secrets redacted.
3. A successful test marks the gateway **Verified**. Only then can **Send SMS** be switched on.

Editing how a gateway connects (endpoint, authentication, field names, number format, success
rule) clears its verification and switches SMS off, so it can never run on a configuration
nobody has proven. Renaming it does not. Tests are limited to 10 per hour, because each one is
a real, billed message.

## Two networks

Ethiopian mobiles are Ethio Telecom (`+2519…`) and Safaricom Ethiopia (`+2517…`). Landlines
cannot receive a text and are refused with a clear message, both on the sign-in page and by the
API.

One gateway can carry both networks; most aggregators route to both. If one network needs a
different gateway, choose **Second gateway for** Safaricom or Ethio Telecom numbers and configure
the second gateway. Numbers on that network go to it first and fall back to the primary if it
fails. A second gateway is used only once it has passed its own test.

## Cost protection

- **Daily limit (whole platform).** Messages accepted today (Addis Ababa day) are counted in the
  database table `sms_sends`, never in the cache, so a cache flush cannot reset it. When the
  limit is reached, SMS reports itself unavailable and the sign-in page offers email codes alone
  until midnight. The ledger records which gateway and why, never the number or the text.
- **Per person.** Notifications are still capped at `SMS_DAILY_LIMIT_PER_USER` (default 5) per
  person per day.
- **Invitations' share (2026-10-28).** Invitations by text may use only
  `sms_settings.invitation_share` percent of the daily limit (30 by default), counted in
  `sms_sends` under the purpose `invitation`. One organisation inviting its whole staff cannot
  use up the sign-in codes every other organisation needs that day. An invitation over the
  share waits for tomorrow; it is not dropped.

## Invitations by text (2026-10-28)

**Off by default, and off until SMS is live** (owner decision). The console switch
**Send invitations by text** sets `sms_settings.invitations_enabled`, and it has an effect only
while SMS itself is available. While it is off:

- a person with an email is invited by email, as before;
- a person with only a phone is **activated in person** with a code HR issues, exactly like a
  person with no contact at all. Until this date a phone alone blocked the in-person code, so
  such a person had no way in.

Once it is on, a person with only a phone is sent a text that names the organisation and the
sign-in page and carries **no link and no token**. They sign in with their phone number and a
code, and that first sign-in activates the account (`OtpController::verify`). A person with an
email is still activated by their link, never by a code.

**Not done yet:** a person activated this way has no password. They keep signing in by code,
which is fine, but setting a password afterwards needs a "set a password" step for signed-in
accounts with no usable one. Build it before switching this on.

## Out of scope: SMPP and SS7

Both need a permanent connection to the operator. The production host (Plesk shared hosting,
no worker process) cannot keep one open. If Ethio Telecom or Safaricom offers SMPP only, that is
a hosting decision for the owner, not a setting on this page. An aggregator that exposes HTTP
over the same networks avoids it.

## On subscription day

1. Get the provider's HTTP API documentation, the credential and an approved sender ID.
2. Fill in the console form from that documentation, including the success rule.
3. Send a test to your own phone, then a second test to a number on the other network.
4. Switch on **Send SMS**, and set the daily limit to what the plan allows.
5. On an organisation's sign-in page, *Sign in with a one-time code* now offers **Text
   message** beside **Email**.
6. Only when sign-in codes by text work: switch on **Send invitations by text**, and check
   the invitations' share of the limit. See *Invitations by text* above for what must exist
   first.
