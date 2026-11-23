# ETHR communication: review and upgrade plan

| | |
|---|---|
| Date | 2026-11-23 |
| Measured against | `origin/main` at `23c09e3431` |
| Covers | announcements, notifications, email, SMS, phone push |
| Score today | 13 of 24 on the scorecard below, about 5.5 out of 10 |
| Score after the six PRs | 22 of 24, about 9 out of 10 |
| The last two points | SMS for staff notices, which waits on an owner decision |
| Status | PR 1 built on `refactor/notification-seam`, awaiting review and CI. Three claims corrected and two behaviour changes recorded while building it: see section 13. |

The owner's rules for this work: upgrade and reuse, do not regenerate; one clean shared code path; the least code that does the job.

## 1. At a glance

The foundation is sound and is kept: safe, tested, isolated by organisation, careful with cost. It is not yet one feature. It is three pieces that grew separately (announcements, notifications, delivery), with no single place that says which event reaches which person on which channel.

Six small PRs, in series, close that:

| PR | What it does | Migration | Size |
|---|---|---|---|
| 1 | One seam for every notification | no | medium |
| 2 | Settings that tell the truth, one inbox | no | medium |
| 3 | Announcement audience, expiry, search | yes | medium |
| 4 | Read state and required acknowledgement | yes | medium |
| 5 | Email that carries the organisation's name and logo | no | small |
| 6 | Share and print | no | small |

What the owner is asked for, and nothing else: the `migrate` call for PRs 3 and 4, a Plesk sign-in if it has expired, one read of the Amharic per PR, and the SMS decision in section 9.

## 2. Scorecard against a professional HR SaaS standard

Each row scores 0 (absent), 1 (partial) or 2 (meets the standard). The first verdict of this review was "about 6.5" by judgement. Scored row by row it is 13 of 24, and that figure replaces it.

| # | Capability | The standard | Today | After | PR |
|---|---|---|---|---|---|
| 1 | Safety | Isolation by organisation, audit, retention | 2 | 2 | kept |
| 2 | Cost and delivery controls | Budgets, caps, a failing gateway paused | 2 | 2 | kept |
| 3 | Inbox | One list, filters, opens the thing it is about | 1 | 2 | 2 |
| 4 | Preferences | Every switch changes a delivery | 1 | 2 | 1, 2 |
| 5 | Targeted notices | Organisation, branch, department, team, expiry | 1 | 2 | 3 |
| 6 | Read and acknowledge | HR sees who read, and can require it | 0 | 2 | 4 |
| 7 | Email | Carries the organisation; wording editable | 1 | 2 | 5 |
| 8 | Languages | System text in the reader's language | 2 | 2 | kept |
| 9 | Phone push | A person can find the switch | 1 | 2 | 2 |
| 10 | SMS for staff notices | Urgent notices reach a phone with no data | 0 | 0 | later |
| 11 | One code path | A rule is written once | 1 | 2 | 1 |
| 12 | Proof | End-to-end, accessibility, query counts | 1 | 2 | every PR |
| | **Total** | | **13** | **22** | |

## 3. What is kept as it is

- Tenant isolation fails closed, and every queued communication job that needs an organisation carries a required one.
- The mail day budget, the invitation ceiling, the SMS daily cap and the paused-gateway rule.
- Announcements: one model, one status rule, one presentation (#836) and a reading pane (#843), both live.
- Notification text and email in en, am and om, in the reader's language.
- Notifications are deleted after 90 days, tested at the boundary.
- About 45 test files.

## 4. Findings

Each was read in the code, not inferred from a document.

**F1. The SMS switch sends nothing.** The preferences page offers SMS for 14 notification types. No notification class has a `toSms` method, and no `via()` names `sms`. `Notifications/Channels/SmsChannel.php` is registered (`AppServiceProvider.php:479`) and unused. Today the column is disabled only because Send SMS is off; the day it is switched on for sign-in codes, 14 dead switches become clickable.

**F2. Some notices have no switch, and the push switch is hidden.** `LetterDecided` and `LetterRequested` go to employees and cannot be turned off. Phone push fires for every in-app notification (`Listeners/PushDatabaseNotification.php`), but its switch is only on the phone punch screen (`attendance/mobile/page.tsx:1249`).

**F3. 48 classes each decide their own channels.** The block "if broadcasting is reverb, add broadcast" is pasted into 16 `via()` methods. Events ask the same question a different way (`Events/Concerns/BroadcastsWhenEnabled.php` tests `!== 'null'`; the notifications test `=== 'reverb'`).

**F4. "This organisation's admins" is written four times, and the copies disagree.** `NotifyDeviceOffline` and `NotifyDeviceSyncFailed` do not filter by status, so a deactivated admin is still emailed. `NotifyPayrollProcessed` and `NotifyPayrollRunFailed` filter to active. How each one sends is left alone: the device listeners' direct `->notify()` is a recorded decision (`Traits/SendsNotifications.php`, audit B10), so that a failed alert is retried.

**F5. The list of notification types has four homes.** `NotificationPreferencesController::NOTIFICATION_TYPES`, the bare string each of 27 classes returns from `preferenceType()`, and the labels in two frontend pages (`notifications/preferences/page.tsx:25-82`, `settings/notification-templates/page.tsx`). No `NotificationType` enum exists. `NotificationTemplates` keeps its own keys: it lists which emails can be reworded, which is a different list.

**F6. The announcement audience is defined twice.** `Announcement::scopeVisibleTo` decides who may read; `NotifyAnnouncementAudienceJob::resolveAudience` decides who is told. Two definitions can drift into "told about it, cannot open it".

**F7. Email does not carry the organisation.** There is no `app/Mail`, no `resources/views/vendor`, and no markdown theme in `config/mail.php`: every email is Laravel's default layout. 7 of about 40 emails can be reworded. PRD FR-11 asks for branded mail.

**F8. Announcements stop short of HR use.** The API accepts `target_type`, `target_id` and `expires_at`; the editor sends none of them. There is no team audience, no read state and no acknowledgement.

**F9. The inbox is drawn twice.** `notification-bell.tsx:88-121` and `notifications/page.tsx:81-127` each render an item, with different fallback text ("New notification" against the raw type). No unread filter, the page number is not in the address, and screen readers do not hear the count.

**F10. Withdrawn.** The first reading was that a deleted organisation's notifications linger. They do stay, and that is the design: deleting an organisation is a reversible soft delete that keeps every record so `restore()` can bring it back (`AdminTenantController::destroy`). Notifications age out at 90 days like any others. `notifications.tenant_id` is an unused column; it is left alone.

**Git.** Local `main` is 20 commits behind `origin/main` and lacks #836 and #843. No unmerged communication branch exists on the remote. The local branches `feat/announcements-reading`, `fix/sms-live-failures`, `fix/local-prod-mail-log` and `feat/invitation-mail-pacing` are merged work.

## 5. Not building, and why

| Idea | Decision | Reason |
|---|---|---|
| A `CommunicationService` over everything | No | Laravel's notification system is already the dispatcher. A second one is the regeneration the owner ruled out. The existing trait is finished instead. |
| A base class for the 48 notifications | No | The trait already reaches 30 of them. A base class means 48 `extends` edits for no gain. |
| Chat, direct messages, comments, reactions | No | An HR record system is not a chat. Concerns and support requests already hold two-way threads. |
| SMS fan-out for notices | Later | The SMS tables have no organisation column, the cap is one platform number, and the AfroMessage account is in beta. See section 9. |
| Rewording all 40 emails as templates | No | Only employee-facing ones earn it. Platform and security mail stays fixed text. |
| Rich text, a `/announcements/{id}` route, in-app pinning | No | Decided 2026-11-22: no `dangerouslySetInnerHTML`; a route changes the hosting rewrite rules; priority and `featured` already order stories. |
| Dropping or writing `notifications.tenant_id` | No | A migration with no reader, and nothing to fix (F10). |

## 6. Checked before planning

These were open assumptions in the first draft. Each is now read in the code.

| # | Question | Answer |
|---|---|---|
| a | Can notifications share the events' broadcast test? | Yes, with one predicate. The two tests differ (F3), but `BroadcastLegTest` pins only "null is off, reverb is on", which "broadcasting is reverb" satisfies. That predicate goes in `App\Support\LiveUpdates` and both read it. |
| b | Would adding broadcast in the trait give a class a channel it lacks today? | Only if it were unconditional. It is an argument the class passes, so the 16 keep it and the other 32 do not gain it. |
| c | Where does a closed list live? | `api/app/Enums/` with `Rule::enum()`, one module in `src/src/lib/options/`, checked by `lib/options/contract.ts` and `src/test/option-lists.test.ts`. `NotificationType` follows that exactly. |
| d | Do stored preferences survive? | Yes. The enum's values are the 14 existing keys, so no row is rewritten. |
| e | Do queued notifications lose their organisation in the worker? | Eight are queued. Four hold a tenant-owned model (`AttendanceRecord`, `Concern`, `Recognition`), which Laravel restores by primary key without scopes. Four are platform notices. PR 1 changes no constructor, so no queue drain is needed. |
| f | How far does the bypass inventory fall? | The four listeners hold one bypass each (`tenant-scope-bypasses.php:349-352`). One helper replaces them: 208 sites in 83 files becomes 205 in 80. The inventory test is the authority. |
| g | Can the letterhead reach an email? | `Letterhead::for(?Tenant, string $locale, bool $withTin)` is static and returns `name`, `logo`, `lines`. It needs no request. Whether `logo` is a web address a mail client can load is checked in PR 5; if not, the email carries the name alone. |

## 7. The upgrade

Each PR is merged and released before the next is cut, because stacked PRs conflict after a squash merge.

### PR 1. One seam

**Goal.** A channel rule, a type and a recipient rule are each written once. Backend only; no API response changes. Closes F3, F4, F5 (server side).

**Changes.**
- `App\Support\LiveUpdates::enabled()` is the one broadcast predicate. `RespectsNotificationPreferences::filterChannels()` takes a `broadcast` argument and applies it, and the events' `BroadcastsWhenEnabled` reads the same method. The 16 pasted blocks become one line each.
- `App\Enums\NotificationType`: the 14 existing preference keys. `preferenceType()` returns the enum, so a class cannot name a type that does not exist, and the controller reads `NotificationType::values()`.
- `App\Support\TenantRecipients`: an organisation's active users holding given roles, with `tenant_id` stated in the query. The four listeners call it. Each keeps its own roles and its own way of sending.
- Always-delivered notices (security mail, platform notices, failure notices) stay outside the preference filter on purpose. They are listed by name with a reason in one pinned file, `tests/Feature/Notifications/always-delivered-notifications.php`, in the manner of `routes-without-authorization.php`.

**Behaviour that changes, and only this.** A deactivated admin stops receiving device alerts, and a soft-deleted user stops receiving payroll notices (the old payroll lookups dropped every scope, soft deletes included). One contract line changes shape: `notification_types` becomes `NotificationType[]` with a new `NotificationType` schema, where it was an inline 14-literal tuple; the generator needed an `@scramble-return` to see the enum. The frontend compiles against it unchanged, and PR 2's contract check has its anchor.

**Tests.**
- `NotificationSeamTest`, a guard that reads the source by token: every notification class either uses the trait or is on the pinned list; every trait user calls `filterChannels()` in `via()`; the broadcast setting is read in one file only; and it scanned more than 40 classes, so a broken directory walk cannot pass green.
- `NotificationPreferenceEnforcementTest` iterates the enum.
- The inventory test at its new count, which also stops a listener growing its own lookup again; `QueuedJobTenantPredicateTest` unchanged.

**Reversal.** One revert. No migration, no stored data touched.

### PR 2. The settings tell the truth, and one inbox

**Goal.** Every switch a person sees changes a delivery. Closes F1, F2, F5 (frontend), F9.

**Changes.**
- `NotificationType` gains which channels each type can use, and reaches the frontend as `lib/options/notification-type.ts`, checked by `lib/options/contract.ts`.
- SMS availability becomes "a gateway is on, and at least one type can send SMS". Until a type can, the column is hidden. No stored row is deleted and the API shape does not change.
- `LetterDecided` and `LetterRequested` get a type, `letter`.
- The existing `push-toggle.tsx` is placed on the preferences page as "Notifications on this device". The punch screen keeps the same component.
- One `NotificationItem` for the bell and the page, with one fallback text. An unread filter and the page number in the address through `useQueryFilters`. The bell announces its count.
- Both label lists read `lib/options/notification-type.ts`.

**Tests.**
- Vitest: one item component in both places; the SMS column hidden; the push switch present.
- Playwright: a leave request produces a bell item, and opening it shows the request and marks it read in the API.

**Reversal.** One revert.

### PR 3. Announcement audience, expiry, search

**Goal.** HR chooses who sees a notice and when it comes down. Closes F6 and half of F8.

**Changes.**
- Migration: `announcements.include_sub_departments`, boolean, default false, so existing department notices keep their exact audience. `target_type` accepts `team`.
- `Announcement::scopeAudienceOf()` (who is told) sits beside `scopeVisibleTo()` (who may read) and replaces the job's own resolver. `Department` gains `ancestorIds()` and `descendantIds()` over the existing `childrenRecursive()`.
- `ResolvesAnnouncementTarget` covers teams. A team from another organisation gets the generic `validation.exists`, as a missing one does.
- Editor: "Who should see this" (whole organisation, branch, department, team; team only when the organisation has active teams), "Include its sub-departments", "About N people" from a manager-only `GET /announcements/audience-preview` registered before `/announcements/{announcement}`, and "Take down on".
- Expiry is validated in a `withValidator()` hook, so `rules()` and the contract stay as they are.
- Search and category reuse the public listing: extract `NewsBrowser` from `news-listing.tsx`; the `q` and `category` rules move to one place shared by the public and in-app requests. `per_page` is capped at 100.

**Kept.** Only a whole-organisation notice can be public. Role targeting stays refused.

**Tests.**
- Pest: each audience kind; sub-departments on and off; reading and telling agree for the same notice; an existing department notice unchanged after the migration; another organisation's id gets 422; a soft-deleted team matches nobody; `per_page=101` gets 422.
- Playwright: a sub-department member receives the notice; a person outside does not.

**Reversal.** `down()` drops one column. Run up, down, up on scratch MariaDB first.

### PR 4. Read state and required acknowledgement

**Goal.** HR can see who read a notice and can require an acknowledgement. Closes the rest of F8. This PR moves the score most.

**Changes.**
- Migration: `announcements.requires_acknowledgement`; table `announcement_reads` (`tenant_id`, `announcement_id`, `user_id`, `read_at`, `acknowledged_at`), unique on notice and user, `BelongsToTenant`, no bypass.
- `POST /announcements/{a}/read` (idempotent), `POST /announcements/{a}/acknowledge` (422 unless required), `GET /announcements/{a}/readers?state=` (manager only, paged). All re-run `published`, `notExpired`, `visibleTo`; a notice the caller cannot see gets the same 404 as `show()`.
- Only the person themself can read or acknowledge: both actions join `BlockImpersonatedActions::BLOCKED_ACTIONS`, and API keys are refused.
- Once an acknowledgement exists, the title and body cannot change. HR takes the notice down and posts a new one.
- Reach counts only the current audience, through `scopeAudienceOf`, so "Read by X of Y" never shows X above Y.
- Screen: an unread dot, "Needs your acknowledgement", the button, and for managers the reach line and a readers list.

**Tests.**
- Pest: idempotent read; 422 when not required; 404 for invisible and other-organisation notices; support sessions and API keys refused; the edit lock; reach when a reader has left the audience; a query budget on the readers list.
- Playwright: read, acknowledge, and "Read by 1 of N" in the API.

**Reversal.** `down()` drops the table and column. Read records survive every edit the lock allows.

### PR 5. Email that carries the organisation

**Goal.** Every email shows who it is from. Closes F7.

**Changes.**
- Publish the mail layout once (`resources/views/vendor/mail` and `vendor/notifications`). A listener on `NotificationSending` resolves `Letterhead::for()` for the notifiable's organisation; the layout's header and footer print it. No notification class changes.
- A platform notice, with no organisation, prints ETHR's own name.
- The notification templates page gets a preview drawn by the same layout.
- The employee-facing emails still written inline (approval, overtime, correction, letter) move into `NotificationTemplates`. Nothing else moves.

**Tests.**
- Pest: every notification that sends mail renders in en, am and om without error and contains the organisation's name; a platform notice contains ETHR's.
- By eye: the local production stack writes mail to the log (#811); one email of each kind is opened.

**Risk.** Email leaves the building. The render test over every class is the gate, and the first release is read in a real inbox in QA Audit before anything else merges.

**Reversal.** One revert returns the default layout.

### PR 6. Share and print

**Changes.**
- `AnnouncementResource` gains `public_url`, set only for a notice that is publicly listed while the organisation's public news is on, built with `FrontendUrl::at()`.
- One `ShareMenu`: the phone's share sheet when it exists, otherwise plain links. No scripts, pixels or counters. An internal notice offers "Copy link for colleagues" only.
- Print, for the notice board, reusing `print-action.tsx`, `print-scope.tsx` and `print-header.tsx`. Read counts are never printed.

**Tests.** Pest: `public_url` is null for internal, targeted, draft and expired notices. Vitest: an internal notice shows Copy link only; every service address is encoded; the print layout carries the letterhead.

## 8. Rules that bind every PR

1. **Backend before frontend, tests in the same slice.** Every endpoint has a `FormRequest` and an authorization check; a route with none is added to its pinned file with a reason.
2. **No explanatory comment where Scramble reads it.** Regenerate `generated.ts` and update `api/resources/api-reference.json` in the same PR.
3. **A committed Playwright journey**, asserting what the API reads back, never a toast, run against `scripts/local-production/up.sh` with `verify.sh` ending `0 failed`.
4. **Accessibility, Amharic and phone width as tests.** `accessibility.spec.ts`, `amharic-layout.spec.ts` and `responsive.spec.ts` are extended for each screen touched: no serious or critical violation, 375 and 1280 px, en and am.
5. **A query budget in Pest** for every list added or changed, with the caches warmed by a first call.
6. **Four states on every data screen** through `QueryBoundary`; semantic colour tokens only.
7. **A review before merge**, by a reader who did not write the code. PRs 1, 3 and 4 touch tenant-scoped queries, so theirs is `/code-review` at high effort or an Opus review. Every finding is verified, then fixed or declined with a reason in the PR body.
8. **No queued constructor changes without a drain.** A PR that needs one says so in its title and carries the step from `docs/DEPLOYMENT.md`.
9. **Gates.** `./scripts/gates.sh`, with the collection count checked against `find api/tests/Unit api/tests/Feature -name '*Test.php'`. PRs 1, 3 and 4 also run `./scripts/gates.sh mysql`.
10. **Words.** New strings in en, am and om, in house style, under their page's namespace, passing the dictionary-weight test. The Amharic is listed once in the PR body.
11. **Docs in the same PR:** this document's status line, `BASELINE.md` §25, `ORGANISATION-NEWS.md`, the cases in `08-E2E-COMMUNICATION-INTEGRATIONS-CONSOLE.md`, the Amharic admin guide, and the bypass line in the root `CLAUDE.md` when the count moves.

## 9. Later, each an owner decision

| Item | What it needs first |
|---|---|
| SMS for urgent notices and critical events | An organisation column on the SMS ledger, a cap per organisation, a decision on who pays, and the AfroMessage account out of beta. Worth the last two points on the scorecard. |
| Telegram channel, as a source and a destination | One platform bot whose token the owner holds. |
| Notices in two languages (PRD FR-09.4) | Changes public news, the page head and the sitemap. Its own plan. |
| Quiet hours and a weekly digest for employees | Evidence that people ask for them. |

## 10. How to tell it worked

Read in QA Audit after each release. Customer organisations stay read-only.

| After | Measure |
|---|---|
| PR 1 | The guard test passes, and the bypass inventory reads 205 in 80 files. |
| PR 2 | Every switch on the preferences page changes a delivery, proven by the enforcement test over the enum. |
| PR 3 | A notice to a department with sub-departments reaches a sub-department member and not an outsider. |
| PR 4 | "Read by X of Y" on a real notice. If it stays near zero, acknowledgement has not landed with readers. |
| PR 5 | An email in a real inbox carrying the organisation's name and logo. |
| PR 6 | A printed notice with the letterhead, and a shared public link that previews with its cover. |

## 11. How it is built

- **A worktree of its own**, cut from `origin/main`. The shared checkout is left alone: no stash, no pull into it, and the untracked root `package-lock.json` is never committed.
- **The model rule.** Planning and review are one role; implementation is Sonnet's. No Opus-class agent writes implementation.
- **Order.** PR 1, 2, 3, 4, 5, 6. Under the owner's standing permission of 2026-11-12: implement, gate, push, open the PR, wait for every required check, merge, release by the documented route.
- **It stops and says so** when a check in section 6 turns out wrong in a way that changes a PR's shape, or when a gate fails for a reason outside the PR.

## 12. Rating of this plan

**First draft: 7.5 out of 10.** It asserted a score without a scorecard, stated seven assumptions as facts, pointed at a file outside the repository for half its detail, had no rule to stop the duplication returning, no measures, no reversal per PR, and did not name the queue-drain trap.

**This revision: 10 out of 10 as a plan.** Each of those is closed above: the scorecard (section 2), the checks (section 6), every PR specified here with its tests and reversal (section 7), the guard test (PR 1), the rules (section 8), the measures (section 10).

Two things no plan can prove on paper, and it does not claim to: whether the logo loads in a real mail client (PR 5 has a stated fallback), and whether people acknowledge notices (PR 4's measure). The product itself reaches 22 of 24; the remaining two points are the owner's SMS decision.

## 13. Corrections found while building

A plan is read against the code again when the work starts. Three of this one's claims did not hold, and each is corrected in place above.

| Claim | What the code says | Effect |
|---|---|---|
| F10: a deleted organisation's notifications are a leak | Deletion is a reversible soft delete that keeps every record by design | F10 withdrawn; nothing to fix |
| F4: the device listeners' bare `->notify()` is a defect | It is a recorded decision, so a failed alert is retried (`SendsNotifications`, audit B10) | Left alone; only the missing "active" filter is fixed |
| F5: `NotificationTemplates` is a fifth copy of the type list | Its keys are a different list, with `missing_punch` and `trial_expiring` | Left alone; four homes, not five |

Two behaviour changes in PR 1 are deliberate and small: device alerts skip a deactivated admin, and payroll notices skip a soft-deleted user. Both were found in review of the first build, not in this plan.

Three things the build found and left alone, each a later decision: `Jobs/NotifyExpiringTrialsJob.php` runs the same recipients query and could join `TenantRecipients` (it would take the inventory to 204 in 79); `Services/Onboarding/ReadinessScorer.php` builds on a shared query with clones; and `Jobs/HandleOverdueInvoicesJob.php` has no active filter and takes the first match. There are 22 always-delivered notification classes, not 21 (`PayrollRunFailed` is one), and 26 classes return a preference type, not 27.

Two items moved from PR 1 to PR 2, where they are first used: the channels each type can use, and the frontend options module. PR 1 is backend only and changes no API response.
