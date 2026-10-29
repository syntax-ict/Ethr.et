# Get help

How a question gets from someone in an organisation to the person who can answer it, and back. Built 2026-10-29.

## The flow

1. **Anyone signed in to an organisation asks.** The life-buoy button in the header, or *Help requests* in the account menu, opens *Get help*: a topic, what happened, and a switch for technical details. The request is filed under the organisation.
2. **The organisation's support people answer first.** Everyone holding `support.manage` is told by in-app notification and email. By default that means `hr_admin` and `tenant_admin`. They see every request in the organisation on `/support` → *Organisation requests*, reply, and mark it resolved.
3. **They pass it to ETHR when it needs the platform.** *Pass to ETHR support* takes an optional note, which the person who asked never sees. The platform's super admins are told by notification and email, and the email links to the console on `admin.{domain}`. The person who asked is told that the request was passed on.
4. **ETHR answers from the console.** `/admin/support` lists every request an organisation passed on, from every organisation. Each shows the organisation (linked to its page), who asked, the colleague who passed it on and their note, the technical details, and the conversation.
5. **Every reply reaches whoever moves next.** ETHR's answer goes to the person who asked, and also to the colleague who passed it on when that is someone else. A reply from the person who asked goes to whoever holds the request. Someone with `support.manage` can also send their own question straight to ETHR.

| Status | Means | Whose move |
|---|---|---|
| `open` | Waiting for an answer | Whoever holds it: the organisation, or ETHR once `handled_by = ethr` |
| `answered` | Someone answered | The person who asked |
| `resolved` | Done | Nobody. A reply from the person who asked reopens it |

The person who asked, their organisation's support people, and ETHR can each mark a request resolved.

## Who sees what

| | The person who asked | Support people (`support.manage`) | ETHR (console) |
|---|---|---|---|
| Their own requests | Yes | Yes | Only once passed on |
| Colleagues' requests | No, same 404 as a request that does not exist | Yes | Only once passed on |
| Another organisation's requests | No | No | Only once passed on |
| Requester's email and role | No | Yes | Yes |
| Note written when passing on | No | Yes | Yes |
| Who answered from ETHR | Shown as "ETHR support" | Shown as "ETHR support" | The staff member's name |
| Technical details | Yes: they can read back everything sent | Yes | Yes |

A person who holds `support.manage` reads their **own** request as the person who asked. The API says which view applies in `viewer`.

## Technical details

When the switch is on, which is the default, the dialog sends what the screen knew when it opened. The person can expand *See what is included* before sending.

- the page path, **without its query string** (a search box puts names and emails there);
- language, screen size, and whether the browser is online;
- how many attendance punches are waiting to sync offline (left out when IndexedDB is unavailable, rather than reported as zero);
- the last five failed API requests from this page: method, path without query, status and problem type. These are kept **in memory only** (`lib/support/recent-failures.ts`). A reload starts empty, and failures of the help endpoints themselves are left out.

The server adds the user agent from the request header, the person's role, and the release commit from `public/release.json` (`App\Support\ReleaseStamp`). When the switch is off, only the role and the release are kept, with `included: false`.

## Limits and notifications

- New requests: 10 per hour per person (`throttle:support`). Each one emails people.
- Replies: 10 per minute per person (`throttle:support-reply`).
- `SupportRequestNotification` sends both in-app and email, and is **not** filed under a notification preference. It answers something the person asked, and a toggle that silenced it would leave a request waiting with nobody told.

## Where the code is

| | |
|---|---|
| Tables | `support_requests`, `support_request_messages`: migration `2026_10_29_000030` |
| Flow and notifications | `App\Services\Support\SupportDesk` |
| Console reads (every tenant-scope bypass) | `App\Services\Support\PlatformSupportInbox`: 7 bypasses, pinned in `tenant-scope-bypasses.php` |
| Organisation API | `SupportRequestController`: `/api/v1/support-requests` |
| Console API | `AdminSupportController`: `/api/v1/admin/support-requests`, behind `admin.manage` and platform MFA |
| Frontend | `features/support/`, `app/(dashboard)/support`, `app/(dashboard)/admin/support`, header button in `components/layouts/app-header.tsx` |
| Tests | `api/tests/Feature/SupportRequestTest.php`, `src/src/test/support-get-help.test.tsx` |

## Release steps

The release needs **migrate**, then **`db:seed --class=PermissionSeeder`** (or the ProductionSeeder, which calls it) so `support.manage` exists and HR admins hold it, then **clear + optimize** for the new routes. Until the seeder runs, nobody in an organisation holds `support.manage`. Requests are still stored, but nobody is told about them.

## Not built (yet)

- A Telegram contact. Each new channel should feed the same inbox rather than become a second one.
- Request counts in the sidebar or the console's operations strip.
- Tagging requests for monthly reporting. `category` is the first cut, and the console can filter on status only.
