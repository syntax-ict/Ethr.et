# Organisation news pages

| At a glance | |
|---|---|
| **Purpose** | Explains how an organisation publishes news on its public website, and what makes a story public. |
| **Open it when** | An organisation asks why a story is or is not public, or you are deploying a change to the news pages. |
| **For** | Platform operator |
| **Organisation types** | Same for every kind. One shared template; neither the kind nor the industry is published. [`ORGANISATION-TYPES.md`](ORGANISATION-TYPES.md) |
| **Source of truth** | The code: `Announcement::publiclyListed()` and the news controllers. |

*Added 2026-10-16.* Every organisation's public website has a news page at
`/news` and one page per story at `/news/{id}`. One shared template serves every
organisation; what differs is the organisation's own data: its stories, name,
logo, colours and language. There are no per-organisation pages, layouts or
deployments.

## Where the pages live

| Organisation's address | News page | Article |
|---|---|---|
| Its subdomain (`acme.ethr.et`) or verified custom domain | `/news` | `/news/{id}` |
| The shared host (`ethr.et/acme`) | `/news?org=acme` | `/news/{id}?org=acme` |

On its own host, Apache sends both paths to Laravel (`.htaccess` group 1a,
`NewsPageController`), which serves the exported page with the organisation's
head: title, description, canonical address, language alternates, link preview
and a `NewsArticle` record. The host alone decides the organisation
(`App\Support\OrganisationHost`, shared with `/login`). On the shared host the
same files are served as exported, `noindex`, and the page reads the
organisation from `?org=`.

## What is public

A story appears on the website only when all of these hold. One scope,
`Announcement::publiclyListed()`, is the single definition, read by the portal's
news section, the news page, the article, the page head and the sitemap.

- The organisation's news section is on (Settings → Sign-in page → sections).
- It is published: a publication time in the past.
- It has not expired (archived).
- Its author ticked **Show on the organisation's public website**.
- It is addressed to the whole organisation. One aimed at a department or
  branch can never be public; the API refuses the combination.

Anything else, a draft included, is the same 404 a non-existent id gets, so a
public visitor cannot tell a private story from a missing one.

## Publishing (Announcements screen)

Whoever holds `announcement.manage` sees every story in every state and can:

| Action | What it does | Audit log |
|---|---|---|
| Publish now | Sets the publication time to now | `announcement.published` |
| Schedule | Sets a future publication time; the story appears by itself then | `announcement.scheduled` |
| Unpublish / Move to drafts | Clears the publication time | `announcement.unpublished` |
| Archive / Restore | Sets or clears the expiry time | `announcement.archived` |
| Feature it | Puts a public story first on the website | `announcement.updated` |
| Category | News, notice, event or deadline; readers filter by it | `announcement.updated` |
| Preview | Shows the story exactly as the article page will | none |

**People are told once.** Publishing queues the audience notification; a
scheduled story queues it for its time. `notified_at` records that it was sent,
so publishing again, rescheduling or unpublishing and republishing never
notifies twice. Scheduled notifications need the queue to drain, which on
shared hosting is the cron caller (`docs/deployment/shared-hosting/cron-caller.md`).

Story text is plain text. A blank line starts a paragraph, and an address that
starts with `https://` becomes a link that opens in a new tab with
`rel="noopener noreferrer nofollow ugc"`. Markup is stripped by the server and
rendered as text by the page, so nothing in a story can run or style the page.

### One announcement, one presentation

*Added 2026-11-22.* A story is drawn by the same three components wherever it
appears: the announcements page, the dashboard card, the organisation's news
page, an article and the portal's news section.

| What | The one component | Where it lives |
|---|---|---|
| Date, category, featured and priority | `NewsMeta` | `src/src/features/news/components/news-card.tsx` |
| A story as a card | `NewsCard` | the same file |
| A story in full | `ArticleView` | `features/news/components/article-view.tsx` |
| Its state: draft, scheduled, published, archived | `StatusBadge` with `domain="announcement"` | `components/shared/status-badge.tsx`, from `lib/status/registry.ts` |
| Its shortened text | `excerpt`, from `App\Support\PlainExcerpt` | the API, for the in-app list and the public feed alike |

**Do not draw any of these again.** Each had grown a second version: priority
was three tables (`priorityColors` on the page, `priorityConfig` in the widget,
`NewsMeta` on the public pages), so one story read "High" in the app and
"Important" on the organisation's own website, and "low" had no word at all.
The text was clamped by CSS in two places while the public feed shortened it on
the server, so the same story was cut at different points.

The words are `landing.urgent` and `landing.important`; normal and low carry no
badge. `e2e/announcements.spec.ts` publishes one story and reads the same word
back from all three screens, so the next copy cannot pass unnoticed.

### Reading one in the app

*Added 2026-11-22.* `/announcements?read={public_id}` opens the story in a
panel, drawn by the same `ArticleView` the public article page uses. Everyone
gets it, not only a manager: until then the list clamped the body to two lines
and only a manager had the preview, so a long notice could not be read in the
product that sent it.

- **A query, not a route of its own.** `/announcements/{id}` would need an
  entry in the shared-hosting rewrite rules (`.htaccess` group 1), which is a
  deployment surface. `?read=` is the same shareable address with none of
  that, and the back button closes the panel. It is read through
  `useQueryFilters`, which uses `useSyncExternalStore` rather than
  `useSearchParams`, so the page stays prerenderable for the static export
  with no Suspense boundary.
- **The notification leads to the story.** `AnnouncementNotification` stores
  `/announcements?read={public_id}`, and the dashboard card links the same
  way. Both pointed at the list before, and `announcement_id` sat unused in
  the payload.
- **A story the reader may not see says only that it is not available.** The
  API answers a draft, an expired story, one addressed to another department
  and a missing id identically, which is what stops a public id being
  confirmed across that line; the panel repeats that and says no more.
- **Documents download through the API.** In the app `attachmentsPresented()`
  fills no `url` (it needs the organisation's public id), so the panel passes
  `onDownload` to `ArticleView` and the file arrives as a blob. A public
  article keeps its plain links.
- **The author's name is eager-loaded.** `User::name` is a computed accessor
  that reads the employee record, so the list loads `publisher.employee`.
  Read row by row it is a 500 under `preventLazyLoading` and a query per row
  in production; `AnnouncementReadingTest` pins that five times the rows cost
  no more queries.
- **Publishing asks first.** The audience is notified exactly once
  (`notified_at`), so taking the story down afterwards does not unsend it.
  Unpublish, archive and restore stay one click, because each reverses.

### Cover photos

A story may carry one cover photo, chosen in the story form (Cover photo →
Choose image) and sent once the story is saved. It shows on the story's card,
across the top of its article page, and as the image a shared link previews
with (`og:image`). Without one, cards are text and the link preview is the
organisation's own.

| | |
|---|---|
| Accepted | PNG, JPG or WebP, at least 960 px wide, at most 4096 px and 5 MB. SVG is refused |
| Stored | Re-encoded as JPEG: at most 1920 px, plus 1280 and 640 px copies for `srcset`. EXIF and anything appended to the file are dropped |
| Served | `GET /api/v1/news-covers/{organisation}/{story}/{size}/{version}`, cached for a year. A new photo is a new address |
| Removed | Replacing or removing the photo, or deleting the story, deletes the files, so the old address answers 404 |
| Audit log | `announcement.cover_updated`, `announcement.cover_removed` |

The address alone decides the file: no story is looked up, so a draft's photo
can be fetched by whoever has its address. Only the publishing desk shows that
address, and it carries a 64-bit content hash beside two ULIDs. Do not use a
cover for anything that must stay private until publication.

### Documents

A story may carry up to **three PDFs** of at most 5 MB each, such as a
circular or a form, chosen in the story form (Documents → Add a PDF) and sent
once the story is saved. Each is checked by its own bytes (`%PDF`) and stored
under a fresh ULID; the name it was uploaded under is kept, cleaned, for the
download.

| Where | Who | Address |
|---|---|---|
| Article page | Anyone, **only while the story is public** (published, not expired, on the website, for everyone) and the organisation shows news | `GET /api/v1/news-files/{organisation}/{story}/{file}`, cached for five minutes |
| Announcements screen | Whoever may read the story in the app, and managers in any state | `GET /api/v1/announcements/{story}/files/{file}` |

Every download is `attachment` with `nosniff` and a `default-src 'none'`
policy, so a document is saved, never shown inside an ETHR page. Removing a
document or deleting its story deletes the file. Audit log:
`announcement.file_attached`, `announcement.file_removed`.

The public address names the organisation because a download link cannot
send `X-Tenant`; the lookup states that organisation's `tenant_id` on the
same chain and refuses a host that resolved a different one. That is a
tenant-scope bypass, pinned in
`tests/Feature/Security/tenant-scope-bypasses.php` (168/65).

## Search engines

Every organisation in use (active, or a trial not yet ended) is listed unless it
switched listing off (Settings → Sign-in page → listing, on by default since
2026-10-26). When it is listed and has its own host, the news page and
its 50 newest public stories are in `/sitemap-organisations.xml` and their pages
say `index, follow`. Otherwise they say `noindex, nofollow`. Organisations on the
shared host keep their sign-in entry in the sitemap and no news entries, because
their news pages carry no head of their own.

## Deploying it

The release that introduced these pages needs `migrate` (one migration:
`category`, `featured` and `notified_at` on `announcements`), then `clear` and
`optimize`, through the maintenance endpoint as `docs/deployment/PLESK-GO-LIVE.md`
describes. Rolling back is a revert on `main`; the migration's `down()` drops the
three columns, and nothing else depends on them.

Cover photos came in a later release with one more migration (`cover` on
`announcements`, nullable JSON), so that release needs `migrate` as well. Its
`down()` drops the column; the photo files stay under
`storage/app/private/tenants/{organisation}/news/` until deleted by hand.

Documents came in the release after that, with one more migration
(`attachments` on `announcements`, nullable JSON), so it needs `migrate` too.
Its `down()` drops the column; the PDFs stay under
`storage/app/private/tenants/{organisation}/news/{story}-files/`.
