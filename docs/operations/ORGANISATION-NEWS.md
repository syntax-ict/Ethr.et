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
| Preview | Shows the story as the article page will, markup removed from the body as for a reader | none |

**People are told once.** Publishing queues the audience notification; a
scheduled story queues it for its time. `notified_at` records that it was sent,
so publishing again, rescheduling or unpublishing and republishing never
notifies twice. Scheduled notifications need the queue to drain, which on
shared hosting is the cron caller (`docs/deployment/shared-hosting/cron-caller.md`).

Story text is plain text. A blank line starts a paragraph, and an address that
starts with `https://` becomes a link that opens in a new tab with
`rel="noopener noreferrer nofollow ugc"`. Markup is stripped by the server and
rendered as text by the page, so nothing in a story can run or style the page.

**One story contract (2026-11-24).** A story is turned into data in one place, `App\Support\Story`
(`summary()`, `article()`, `body()`), and the public feed, the news list, the public article and the
signed-in list and reading pane all read it. Every reader gets the same excerpt (280 characters) and a
body with markup removed. A public card cuts the title at 150 characters; the signed-in list keeps the
stored title, because the editor saves what it is given. The editor also fills its text box from
`body_source`, the body as written, which the API sends only to someone who manages announcements, so
opening a story and saving it changes nothing. The signed-in list is ordered urgent, important, normal,
low and then newest, and the public order is the same with featured stories first
(`Announcement::scopeReadingOrder()` and `scopeNewsOrder()`, over one rank). The three closed lists,
priority, category and audience, are enums (`App\Enums\AnnouncementPriority`, `AnnouncementCategory`,
`AnnouncementTarget`), and a value outside them is refused with a 422. Markup is removed from a
story's text and nothing else: a `<` that opens no tag, as in "under <2000 birr", stays as written,
on the public pages as well as in the app. The editor's Preview applies the same rule to the body
(`withoutMarkup()` in `features/news/news-format.ts`, for the preview only, never for what is saved),
so a manager who types real tags previews the text readers get. The public article is cached in
the browser for two minutes, like the feed and the list, so an unpublished story can stay readable for
that long to a visitor who had already opened it.

### One announcement, one presentation

*Added 2026-11-22.* A story is drawn by the same components wherever it
appears: the announcements page, the dashboard card, the organisation's news
page, an article and the portal's news section.

| What | The one component | Where it lives |
|---|---|---|
| Date, category, featured and priority | `NewsMeta` | `src/src/features/news/components/news-card.tsx` |
| A story as a card, in four densities (`card`, `feature`, `row`, `compact`) | `NewsCard` | the same file |
| A story in full | `ArticleView` | `features/news/components/article-view.tsx` |
| Its text as paragraphs and live links (a story's body, an FAQ answer) | `PlainText` | `features/news/components/plain-text.tsx` |
| Its documents, as a link or a download handler | `AttachmentList` | `features/news/components/attachment-list.tsx` |
| A paged list of stories, with search and category where the source has them | `StoryBrowser`, with `useStoryFilters` | `features/news/components/story-browser.tsx` |
| The public pages' four states | `NewsBoundary` (over `QueryBoundary`), with `NewsState` as its skin | `features/news/components/news-state.tsx` |
| A portal page's frame, links, title and breadcrumb | `usePortalPage()`, `PortalBreadcrumb` | `features/auth/components/portal-page.tsx` |
| Its state: draft, scheduled, published, archived | `StatusBadge` with `domain="announcement"` | `components/shared/status-badge.tsx`, from `lib/status/registry.ts` |
| Its shortened text | `excerpt`, from `App\Support\PlainExcerpt` | the API, for the in-app list and the public feed alike |

**Do not draw any of these again.** Each had grown a second version: priority
was three tables (`priorityColors` on the page, `priorityConfig` in the widget,
`NewsMeta` on the public pages), so one story read "High" in the app and
"Important" on the organisation's own website, and "low" had no word at all.
The text was clamped by CSS in two places while the public feed shortened it on
the server, so the same story was cut at different points. The clamp now lives
once, inside `NewsCard`, one per density (card 4 lines, feature 5, compact 2, row
none), over the server's excerpt.

A story's title is a heading and a list of stories is a list. The page's h1 is
the only h1: the news page's cards are h2, an article's related stories and the
portal's section cards h3, the announcements page's rows h2, and the reading
panel and the editor's preview, which sit on a page with its own h1, draw the
story's title as an h3. `NewsMeta` may be imported only inside `features/news/`,
and the link attributes `noopener noreferrer nofollow ugc` appear in one file
(`plain-text.tsx`); `src/src/test/story-one-renderer.test.ts` fails otherwise.

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
  `useStoryFilters` (over `useQueryFilters`, carrying `q`, `category`, `page`
  and `read` together), which uses `useSyncExternalStore` rather than
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
  `onDownload` to `ArticleView` and the file arrives as a blob (a handler wins
  over an address in `AttachmentList`, so the app never depends on `url` being empty). A public
  article keeps its plain links.
- **No name rather than an email address.** `User::name` falls back to the
  login email for an account with no employee record and no display name, so
  the author line is left empty for them instead of printing an address to the
  whole audience.
- **It says which state a manager is reading.** A draft, a scheduled story and
  an archived one carry their badge in the panel, because a manager may open
  any of them and an unpublished story otherwise reads exactly like a live one.
  **Featured** is not shown to a reader at all: it means "first on the public
  website", which is the publishing desk's business, and the dashboard card
  withholds it for the same reason.
- **Only a missing story says it is missing.** A dropped connection or a
  server error says the load failed and offers Try again. Telling a reader a
  notice may have been withdrawn when nothing of the sort happened is a wrong
  answer, not a missing one.
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

What the heads carry (2026-11-23). The news page's head lists the first page of
public stories, nine, as a `CollectionPage` with an `ItemList`, in the order the
page itself shows them (`Announcement::scopeNewsOrder()`, one definition for the
page, the feed and this head, ranking low priority after normal), and a `BreadcrumbList` from the organisation's
home to News. An organisation with no stories has the breadcrumb and no list.
An article's head carries `article:modified_time`, the story's text as
`articleBody`, the organisation as `author`, a breadcrumb of three steps, and
`inLanguage` only when the story's script agrees with the organisation's
language (Ethiopic text in an Amharic organisation, Latin text in an English or
Afaan Oromoo one); otherwise the field is left out, because a wrong language is
worse than none. The page title, the description and `og:locale` follow the
organisation's own language, with the other two as alternates. The sitemap dates
each article by `updated_at` and the news page by its newest article; the
sign-in entry has no date. The `hreflang` alternates are unchanged by decision.

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
