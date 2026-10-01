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
| `test` | You pressed *Send test* | `message`, `timestamp` | `WebhookDispatcher.php:67-73` |

The settings page offers exactly the first nine (`src/src/features/webhooks/api.ts:31-41`).
**The API does not check the list:** `events.*` accepts any string
(`StoreWebhookRequest.php:22`), so a webhook subscribed to a misspelt event saves, shows as
active, and never fires. Recorded as audit N11.

A failure to queue a webhook never fails the business action that triggered it — it is logged
and swallowed (`api/app/Traits/DispatchesWebhooks.php:14-28`).

## What arrives

`POST` to your URL, JSON body (`WebhookDispatcher.php:28-33`):

```json
{
  "event": "leave.approved",
  "timestamp": "2026-10-01T09:30:00+00:00",
  "tenant_id": 42,
  "data": { "public_id": "01J…", "employee_name": "…" }
}
```

- `timestamp` is when the delivery was **queued**, in UTC — not when it was sent. A retry sends
  the same body, with the same timestamp.
- `tenant_id` is ETHR's **internal numeric id** for your organisation. Every other API surface
  exposes only ULID `public_id`s (convention 4 in `docs/CLAUDE.md`); this field and the
  `X-ETHR-Delivery` header below are the exceptions. Do not build on either value staying numeric.
  *Recorded as a finding by this document, not changed.*

Headers (`DispatchWebhookJob.php:106-113`):

| Header | Value |
|---|---|
| `Content-Type` | `application/json` |
| `X-ETHR-Signature` | `sha256=<hex HMAC-SHA256 of the body, keyed with your secret>` |
| `X-ETHR-Event` | The event name |
| `X-ETHR-Delivery` | The delivery's id. **The same on every retry of one delivery** — use it to de-duplicate |
| `User-Agent` | `ETHR-Webhooks/1.0` |

Any `2xx` counts as delivered. ETHR waits **10 seconds** for your response
(`DispatchWebhookJob.php:106`) and stores the first 2,000 characters of whatever you return
(`:117`).

## Verifying the signature

The secret is 32 random characters (`WebhookController.php:45`), shown **once**, in the response
that creates the webhook (`:59`). It is in no other response. There is no rotate endpoint: to
change it, delete the webhook and create a new one. On ETHR's side it is stored as plain text —
`Webhook.php:28-35` gives `secret` no `encrypted` cast, unlike `User.mfa_secret` (`User.php:85`) and
`Device.connection_config`. *Recorded as a finding, not changed.*

The signature is `"sha256=" . hash_hmac("sha256", <body>, <secret>)`
(`DispatchWebhookJob.php:101-102`). Compute it over the **raw request body exactly as received**
— never over a parsed-and-re-encoded copy — and compare in constant time:

```php
$raw = file_get_contents('php://input');
$expected = 'sha256='.hash_hmac('sha256', $raw, $secret);
if (! hash_equals($expected, $_SERVER['HTTP_X_ETHR_SIGNATURE'] ?? '')) {
    http_response_code(401);
    exit;
}
```

```js
// Node — rawBody must be the Buffer as received (e.g. express.raw({ type: "application/json" })).
const crypto = require("node:crypto");
const expected = Buffer.from("sha256=" + crypto.createHmac("sha256", secret).update(rawBody).digest("hex"));
const given = Buffer.from(req.get("X-ETHR-Signature") || "");
const ok = given.length === expected.length && crypto.timingSafeEqual(given, expected);
```

**Not verified — read this if verification fails.** The signed bytes come from PHP's
`json_encode($payload)` with default flags (`DispatchWebhookJob.php:101`); the body on the wire is
serialised separately by Laravel's HTTP client from the same array (`:114`). Both use default
`json_encode` flags as far as this pass could tell, so the bytes should match — but no test sends
a request and checks the header against the body that left (`tests/Feature/ApiPlatformTest.php:206`
tests `Webhook::sign()`, which the job does not call). If your check fails only on payloads that
contain `/` or non-ASCII text (Amharic names), this is the first suspect; report it.

**Replay:** the timestamp is inside the signed body, so it cannot be altered without breaking the
signature. Rejecting deliveries much older than your tolerance, and de-duplicating on
`X-ETHR-Delivery`, is up to you — ETHR does neither for you.

## Delivery, retries and auto-disable

Deliveries are queued on `default` (`WebhookDispatcher.php:62`). **Production has no worker
process:** the queue is drained when the GitHub Actions caller hits `POST /api/v1/cron/queue`,
every 5 minutes (`.github/workflows/cron.yml:31`). So a webhook can arrive up to about five
minutes after the event, and every delay below is a minimum.

What happens to one delivery (`DispatchWebhookJob.php`):

| Outcome | What ETHR does | Retried? | Source |
|---|---|---|---|
| `2xx` | Marks delivered, resets the webhook's `failure_count` to 0 | — | `:119-131` |
| Any other status (`4xx`, `5xx`, `3xx` that is not followed) | Records the status and body, `failure_count + 1` | **No.** The job ends normally | `:132-135` |
| Connection error, DNS failure, or no answer in 10 s | Records the error, `failure_count + 1`, re-throws | **Yes** | `:136-148` |
| Webhook switched off while the delivery waited | Records *"Webhook disabled before delivery"* | No | `:82-86` |
| URL now resolves to an internal address | Records *"Refused: …internal address"*; `failure_count` unchanged | No | `:94-99` |

**Only network-level failures are retried.** An endpoint answering `500` gets one attempt.

Retries: up to **5 attempts in total** (`:23`). `backoff()` lists 1 min, 5 min, 30 min, 2 h and
24 h (`:28-31`), but with 5 tries only the first four gaps are reachable — Laravel takes the
delay for retry *n* from entry *n − 1*, and the fifth failure is final. So the last attempt is
roughly **2 h 36 min** after the first, plus up to 5 minutes' drain latency per step. *Reasoned
from Laravel's worker semantics (the delay after attempt n is `backoff()[n − 1]`); no test
exercises it. Root `CLAUDE.md` and `DEPLOYMENT.md:544-546` say a delivery that has failed four
times waits 24 hours; by this reading it waits 2 hours, and the 24 h entry is never used. Their
advice — wait out a quiet window before a deploy that changes the job's payload — errs on the
safe side either way.*

After the last attempt `failed()` writes *"Permanently failed: …"* into the delivery
(`:188-219`).

**Auto-disable at 10.** `failure_count` counts failed *attempts* across all of a webhook's
deliveries since its last success, and the webhook is switched off (`is_active = false`) when it
reaches 10 (`:158-176`). Two retrying deliveries to a dead endpoint can get there on their own.

**Turning it back on does not reset the count.** `PUT … {"is_active": true}` changes only
`is_active` (`UpdateWebhookRequest.php:45-48`), so `failure_count` stays at 10 or more and the
next single failed attempt switches it off again. Only a successful delivery resets it — so fix
the endpoint first, re-enable, then use **Send test** to confirm.

Delivery records are kept **30 days**, then hard-deleted (`CleanupExpiredDataJob.php:37-39`).

## Your URL must be public

`http` or `https` only, and the host must not be internal. The check runs **when the URL is
saved** — on create and, since 2026-10-01, on update (`StoreWebhookRequest.php:20`,
`UpdateWebhookRequest.php:45`, `api/app/Rules/ExternalUrl.php`) — **and again before every
send** (`DispatchWebhookJob.php:94-99`), so a hostname whose DNS later points inside the network
is refused too.

Refused (`api/app/Support/OutboundHost.php:26-71`): `localhost`, `0.0.0.0`, `::`, `::1`; any
name ending `.localhost`, `.local`, `.internal`, `.localdomain`; the cloud metadata names; bare
integer or hex IPv4 literals (`2130706433`, `0x7f000001`); private and reserved IPv4 and IPv6
ranges, including IPv4-mapped IPv6; and any hostname that resolves (A or AAAA) to one of those.

Two residual gaps, stated rather than hidden:

- **DNS rebinding.** The name is checked, then connected to separately; a name whose DNS
  changes between the two is not caught (`OutboundHost.php:20-22` says so).
- **Redirects — not verified.** The job does not disable redirects (`:106-114`), and Laravel's
  HTTP client follows them by default, so a public URL that answers `302` to an internal address
  would be followed without the target being checked. Read from the library defaults; no test
  here exercises it. Recorded as a finding of this document.

## Where to look when deliveries stop

1. **Settings → Webhooks**: is the webhook *inactive*? That is auto-disable — see above.
2. **Deliveries** for that webhook: `response_status` empty with a body starting *"Refused"* or
   *"Webhook disabled"* is ETHR declining to send; a status code is your endpoint's answer.
3. Nothing at all for recent events: the queue is not being drained —
   [`operations/ON-CALL.md`](operations/ON-CALL.md) and
   [`operations/QUEUE-MONITORING.md`](operations/QUEUE-MONITORING.md).
