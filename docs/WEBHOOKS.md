# Webhooks — for tenant developers

**Written 2026-10-01 from the code, not from other documents** (audit item D14). Every claim
cites the file and line it was read from. Where something was reasoned rather than read or
tested, it says **not verified**.

ETHR can POST a signed JSON message to a URL you own when something happens in your
organisation — an employee is created, a leave request is approved, a payroll run is approved.

---

## Who can set one up

| Requirement | Where |
|---|---|
| The `webhook.manage` permission — granted to **tenant admins** only | `api/database/seeders/PermissionSeeder.php:292`; checked on every action, `WebhookController.php:22,42,68,84,94,107` |
| The **`webhooks` plan feature** to create or edit one — on the seeded plans, **Enterprise** only | `api/routes/api.php:631-634`; `api/database/seeders/PlanSeeder.php:75` |
| Listing, deleting, test-sending and reading deliveries stay open on any plan, so a downgraded tenant can still switch off an endpoint that is receiving its data | `api/routes/api.php:630,635-643` |

In the app: **Settings → Webhooks** (`src/src/app/(dashboard)/settings/webhooks/page.tsx`).

## The API

All under `/api/v1/webhooks`, authenticated (`api/routes/api.php:629-644`):

| Method and path | What it does |
|---|---|
| `GET /webhooks` | List: `public_id`, `url`, `events`, `is_active`, `failure_count`, `last_triggered_at`, `created_at`. **No secret** (`WebhookController.php:20-38`) |
| `POST /webhooks` | Create. Body: `url` (required, ≤500 chars), `events` (array, at least one). Returns the **secret, once** (`:40-64`) |
| `PUT /webhooks/{public_id}` | Change `url`, `events` or `is_active` (`UpdateWebhookRequest.php:45-48`) |
| `DELETE /webhooks/{public_id}` | Delete (`:82-90`) |
| `POST /webhooks/{public_id}/test` | Queue a `test` event to this endpoint. **10 per hour per tenant** (`api/routes/api.php:641-642`, `AppServiceProvider.php:243-245`) |
| `GET /webhooks/{public_id}/deliveries` | The 50 most recent deliveries: `event`, `response_status`, `attempt`, `delivered_at`, `created_at` (`:105-122`) |

## Events

These are the only events the application sends. Each is a `$this->webhook(...)` call, found by
searching `api/app` for every call site:

| Event | Sent when | `data` | Source |
|---|---|---|---|
| `employee.created` | An employee is created | `public_id`, `name` | `EmployeeController.php:153` |
| `employee.updated` | An employee is updated | `public_id`, `name` | `EmployeeController.php:216` |
| `leave.requested` | A leave request is submitted | `public_id` (the request), `employee_name`, `leave_type` (the type's code), `days` | `LeaveRequestController.php:145-150` |
| `leave.approved` | A leave request is approved | `public_id`, `employee_name` | `LeaveRequestController.php:298-301` |
| `leave.rejected` | A leave request is rejected | `public_id`, `employee_name`, `reason` | `LeaveRequestController.php:348-352` |
| `payroll.processed` | A payroll run finishes calculating (from the queued job) | `public_id` (the run), `period`, `employee_count` | `ProcessPayrollJob.php:124-128` |
| `payroll.approved` | A payroll run is approved | `public_id`, `period` | `PayrollController.php:135-138` |
| `payroll.voided` | A payroll run is voided | `public_id`, `period` | `PayrollController.php:162-165` |
| `payroll.reprocessed` | A voided run is reprocessed into a new one | `public_id` (the new run), `period`, `reprocessed_from` (the old run's `public_id`) | `PayrollController.php:196-200` |
| `attendance.checked_in` | A check-in becomes an attendance record of its own: a new one, or one kept beside an earlier record of the day and flagged for review (a second session, another device hours later). A punch merged into or deduplicated against an existing record (the same person seen twice minutes apart) sends nothing | the record's `public_id`, `employee_public_id`, `employee_name`, `date`, `check_in`, `check_out` (null), `status`, `source` | `AttendanceEngine.php` |
| `attendance.checked_out` | A check-out closes an open record | the same fields, `check_out` set | `AttendanceEngine.php` |
| `attendance.corrected` | An attendance correction is approved and the record rewritten | the same fields, after the correction, plus `correction_public_id` | `CorrectionDecisionService.php` |
| `test` | You pressed *Send test* | `message`, `timestamp` | `WebhookDispatcher.php:67-73` |

The settings page offers exactly the first twelve (`src/src/features/webhooks/api.ts`).

**Attendance events carry no location, photo, IP address or integrity score** — the record's
other columns stay inside ETHR (`App\Support\AttendanceWebhookPayload`). Every punch from a
kiosk, device, phone, offline sync or manual entry goes through `AttendanceEngine`, so each
source sends them; times are ISO 8601 in UTC. *(Added 2026-10-14; before that there were nine
events and none for attendance.)*
**The API checks the list** *(since 2026-10-02, audit N11)*: `events.*` must be one of
`App\Support\WebhookEvents::ALL`, and each at most once, on create and on update — anything
else is a 422. Before that it accepted any string, so a webhook subscribed to a misspelt event
saved, showed as active, and never fired. `WebhookEventValidationTest` fails if that list and
the `$this->webhook(...)` call sites disagree. A webhook saved earlier with an unknown event
keeps it until its events are next edited.

A failure to queue a webhook never fails the business action that triggered it — it is logged
and swallowed (`api/app/Traits/DispatchesWebhooks.php:14-28`).

## What arrives

> **Changed 2026-10-02 (audit N18).** `tenant_id` in the body and the `X-ETHR-Delivery` header
> were ETHR's internal numeric ids; both are now ULID public ids. A non-`2xx` answer is now
> retried, redirects are no longer followed, and the secret is stored encrypted. If you built on
> the numeric values, key on the new ones — they are stable.

`POST` to your URL, JSON body (`WebhookDispatcher::queue()`):

```json
{
  "event": "leave.approved",
  "timestamp": "2026-10-01T09:30:00+00:00",
  "tenant_id": "01J…",
  "data": { "public_id": "01J…", "employee_name": "…" }
}
```

- `timestamp` is when the delivery was **queued**, in UTC — not when it was sent. A retry sends
  the same body, with the same timestamp.
- `tenant_id` is your organisation's `public_id`, the same ULID every other API surface uses
  (convention 4 in `docs/CLAUDE.md`).

Headers (`DispatchWebhookJob::handle()`):

| Header | Value |
|---|---|
| `Content-Type` | `application/json` |
| `X-ETHR-Signature` | `sha256=<hex HMAC-SHA256 of the body, keyed with your secret>` |
| `X-ETHR-Event` | The event name |
| `X-ETHR-Timestamp` | When this attempt was sent, Unix seconds; signed in `X-ETHR-Signature-V2` (see below) |
| `X-ETHR-Signature-V2` | The timestamped signature to verify (see below) |
| `X-ETHR-Delivery` | The delivery's `public_id`. **The same on every retry of one delivery** — use it to de-duplicate |
| `User-Agent` | `ETHR-Webhooks/1.0` |

Any `2xx` counts as delivered. ETHR waits **10 seconds** for your response and stores the first
2,000 characters of whatever you return.

## Verifying the signature

The secret is 32 random characters (`WebhookController.php:45`), shown **once**, in the response
that creates the webhook (`:59`). It is in no other response. There is no rotate endpoint: to
change it, delete the webhook and create a new one. On ETHR's side it is stored under the `encrypted` cast, like `Device.connection_config`
(since 2026-10-02; before that it was plain text).

Every delivery carries two signatures. **Verify `X-ETHR-Signature-V2`**; the first is kept, unchanged, for receivers built before it.

| Header | Value |
|---|---|
| `X-ETHR-Timestamp` | When **this attempt** was sent, in Unix seconds. A retry carries a new one |
| `X-ETHR-Signature-V2` | `"sha256=" . hash_hmac("sha256", <X-ETHR-Timestamp> . "." . <body>, <secret>)` |
| `X-ETHR-Signature` | `"sha256=" . hash_hmac("sha256", <body>, <secret>)`: the body alone, as before 2026-10-14 |

Compute it over the **raw request body exactly as received**, never over a parsed-and-re-encoded
copy. Compare in constant time, then refuse a timestamp outside your tolerance (five minutes
is usual):

```php
$raw = file_get_contents('php://input');
$sentAt = $_SERVER['HTTP_X_ETHR_TIMESTAMP'] ?? '';
$expected = 'sha256='.hash_hmac('sha256', $sentAt.'.'.$raw, $secret);
if (! hash_equals($expected, $_SERVER['HTTP_X_ETHR_SIGNATURE_V2'] ?? '')
    || abs(time() - (int) $sentAt) > 300) {
    http_response_code(401);
    exit;
}
```

```js
// Node — rawBody must be the Buffer as received (e.g. express.raw({ type: "application/json" })).
const crypto = require("node:crypto");
const sentAt = req.get("X-ETHR-Timestamp") || "";
const expected = Buffer.from("sha256=" + crypto.createHmac("sha256", secret)
  .update(sentAt + ".").update(rawBody).digest("hex"));
const given = Buffer.from(req.get("X-ETHR-Signature-V2") || "");
const ok = given.length === expected.length && crypto.timingSafeEqual(given, expected)
  && Math.abs(Date.now() / 1000 - Number(sentAt)) <= 300;
```

The signed bytes are the bytes sent: the job encodes the payload once and posts that string
(`withBody()`), so there is no second serialisation to disagree with the signature.
`WebhookDeliveryReliabilityTest` checks both headers against the body that actually left.

**Replay** *(closed 2026-10-14)*: the body's `timestamp` is when the delivery was **queued**,
and a retry carries it unchanged for up to a day, so a receiver could not refuse an old
delivery without refusing legitimate retries. `X-ETHR-Timestamp` is when each attempt is
**sent**, and it is signed together with the body, so it cannot be moved without breaking the
signature. A captured delivery therefore stops verifying once it is older than your tolerance.
Still de-duplicate on `X-ETHR-Delivery` — the same delivery can legitimately arrive twice if
your answer was lost on the way back.

## Delivery, retries and auto-disable

Deliveries are queued on `default` (`WebhookDispatcher.php:62`). **Production has no worker
process:** the queue is drained when the GitHub Actions caller hits `POST /api/v1/cron/queue`,
every 5 minutes (`.github/workflows/cron.yml:31`). So a webhook can arrive up to about five
minutes after the event, and every delay below is a minimum.

What happens to one delivery (`DispatchWebhookJob.php`):

| Outcome | What ETHR does | Retried? |
|---|---|---|
| `2xx` | Marks delivered, resets the webhook's `failure_count` to 0 | — |
| `408`, `425`, `429` or any `5xx` | Records the status and body | **Yes** |
| Connection error, DNS failure, or no answer in 10 s | Records the error | **Yes** |
| Any other `4xx`, or a `3xx` | Records the status and body; counts one failed delivery | No — the same request gets the same answer |
| Webhook switched off while the delivery waited | Records *"Webhook disabled before delivery"* | No |
| URL now resolves to an internal address | Records *"Refused: …internal address"*; not counted | No |

**Redirects are not followed.** The URL's host is checked against internal ranges before the
request; a `3xx` could point anywhere, so it is recorded as a failure instead. Give ETHR the final
URL.

Retries: up to **6 attempts in total**, waiting 1 min, 5 min, 30 min, 2 h and 24 h between them
(`$tries` is one more than the `backoff()` entries, so the 24 h step is reached — until
2026-10-02 it was not, and the last attempt came about two hours in). Add up to 5 minutes' drain
latency per step. After the last attempt `failed()` writes *"Permanently failed: …"* into the
delivery and counts it.

**Auto-disable at 10.** `failure_count` counts failed **deliveries** — one given up on, not one
attempt — since the webhook's last success, and the webhook is switched off (`is_active = false`)
when it reaches 10. (It counted attempts until 2026-10-02, so two retrying deliveries could
disable a webhook on their own.)

**Turning it back on resets the count.** `PUT … {"is_active": true}` on a disabled webhook sets
`failure_count` to 0. Fix the endpoint first, re-enable, then use **Send test** to confirm.

Delivery records are kept **30 days**, then hard-deleted (`CleanupExpiredDataJob.php:37-39`).

## Your URL must be public

`http` or `https` only, and the host must not be internal. The check runs **when the URL is
saved** — on create and, since 2026-10-01, on update (`StoreWebhookRequest.php:20`,
`UpdateWebhookRequest.php:45`, `api/app/Rules/ExternalUrl.php`) — **and again before every
send** (`DispatchWebhookJob.php:94-99`), so a hostname whose DNS later points inside the network
is refused too.

Refused (`api/app/Support/OutboundHost.php`): `localhost`, `0.0.0.0`, `::`, `::1`; any
name ending `.localhost`, `.local`, `.internal`, `.localdomain`; the cloud metadata names; **any
all-numeric host that is not a plain dotted quad** — `2130706433`, `0x7f000001`, and the short
and octal forms `127.1` and `0177.0.0.1`, which curl connects to 127.0.0.1; every address that is
not globally routable (private, loopback, link-local and reserved, plus carrier-grade NAT
`100.64.0.0/10`, `192.0.0.0/24` and `198.18.0.0/15`, which PHP's own flags let through), including the IPv4 address carried by an
IPv4-mapped (`::ffff:…`) or NAT64 (`64:ff9b::…`) IPv6 one; and any hostname that resolves (A or
AAAA) to one of those.

*(2026-10-14, end-to-end test D8: `127.1` and `0177.0.0.1` were accepted. PHP's resolver
returned nothing for them on the test machine, so they passed as external, while curl expanded
both to loopback and the delivery reached a receiver on 127.0.0.1. `100.64.0.1` was accepted
too: `NO_PRIV_RANGE | NO_RES_RANGE` does not cover it.)*

**Redirects are not followed** (`withoutRedirecting()`), verified end to end on 2026-10-14: a
receiver answering `302` got one request, the delivery recorded `302`, and nothing was retried.

**The connection goes to the addresses that were checked.** The job looks the name up once
(`OutboundHost::publicAddresses()`), refuses it if any answer is internal, and hands curl those
addresses through `CURLOPT_RESOLVE`, so the client does not look the name up a second time. TLS
is still verified against the name. A name that resolves to nothing is retried like a network
error, never sent unpinned, and a name with characters outside ASCII is refused, because curl
converts it before matching the pinned entry *(2026-10-31; until then the name was checked and
then resolved again by the client, a gap this section stated)*.

### Testing against a receiver on your own machine

The rule above refuses `http://127.0.0.1:…`, so a local receiver cannot be registered as it
stands. On a developer machine set

```
WEBHOOK_ALLOW_PRIVATE_HOSTS=true
```

in `api/.env` (or the dev server's environment) and restart the API and the queue worker. It is
honoured **only when `APP_ENV` is `local` or `testing`** — in production it is ignored, so a stray
line cannot open SSRF — and only for webhook URLs: the SMS gateway endpoint, which uses the same
rule, stays refused (`App\Support\WebhookTargets`, `config/webhooks.php`).

### API keys cannot manage webhooks

Creating, listing, changing or testing a webhook needs a signed-in admin. An API key gets
`403 api-key-session-only` there, whatever its abilities: a key that could create a webhook
could route every event to a receiver of its choosing and keep it after the key is revoked.

## Where to look when deliveries stop

1. **Settings → Webhooks**: is the webhook *inactive*? That is auto-disable — see above.
2. **Deliveries** for that webhook: `response_status` empty with a body starting *"Refused"* or
   *"Webhook disabled"* is ETHR declining to send; a status code is your endpoint's answer.
3. Nothing at all for recent events: the queue is not being drained —
   [`operations/ON-CALL.md`](operations/ON-CALL.md) and
   [`operations/QUEUE-MONITORING.md`](operations/QUEUE-MONITORING.md).
