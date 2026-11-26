# ETHR news: review and upgrade plan

*Written 2026-11-23. No code is changed by this document.*

## Context

The owner asked for a review of the news feature, in the code and in Chrome through Playwright, with these rules:

- **Upgrade, do not regenerate.** What works is kept.
- **Fragmented code becomes one reusable, shared approach.**
- **A complete checklist of what a professional SaaS news system has**, with where ETHR stands on each line.

"News" here means one thing seen from two sides: an `Announcement` row. Inside the app it is an announcement. On the organisation's public website it is a news story (`/news`, `/news/{id}`, and the news section of the portal). This plan covers the story itself and its public side. Notifications, email, SMS, audiences and read receipts belong to the communication plan (`docs/audit/ETHR communication upgrade plan.md`) and are referenced here, not repeated.

### What was read

- The backend and frontend on `origin/main` at `23c09e3431`, from a clean worktree. **The shared checkout `F:\ethr.et` is 20 commits behind `origin/main`** and does not contain #836 or #843, so its files are stale for this feature.
- `docs/operations/ORGANISATION-NEWS.md`, the announcements plan (`look-announcement-section-in-idempotent-quail.md`, PRs A1 to D), and the communication plan.
- Tests: `NewsPageTest`, `LandingNewsTest`, `LandingFeedTest`, `NewsCoverTest`, `NewsFileTest`, `AnnouncementPublishingTest`, `AnnouncementReadingTest`, `AnnouncementExcerptTest`, `news-pages.test.tsx`, `announcement-presentation.test.tsx`, `announcement-reading.test.tsx`, `e2e/announcements.spec.ts`.

### What was seen in Chrome (Playwright, production, 2026-11-23)

Production was at `0a6150d0a4` (`release.json`), two commits behind `origin/main`; neither touches news. Only public pages were opened, as any visitor would, with GET requests only.

| Page | Result |
|---|---|
| `qa-audit.ethr.et/news` | 200. No public stories: "No news yet". `noindex, nofollow` |
| `habru-woreda.ethr.et/news` | 200, `index, follow`. Seven stories in Amharic with Ethiopian dates, a lead story, category chips, search |
| Search for a word | Four stories, "4 ዜናዎች", the word kept in the address. Back restored the full list and cleared the box |
| `habru-woreda.ethr.et/news/{id}` | 200. Title, cover, seven paragraphs, three related stories, `NewsArticle` record in the head |
| An id that does not exist | 404, `noindex, nofollow`, same page shell |
| 375 px wide, list and article | No sideways scroll. Body text 16 px on a 27 px line, which suits Amharic |
| `www.ethr.et/sitemap-organisations.xml` | Lists the news page and stories of two organisations |

**Not seen in this pass:** the signed-in screens (the announcements page, the editor, the reading pane, the dashboard card). They need a sign-in that is the owner's to make, and the local checkout is too far behind to run. They were reviewed from code. The reading pane was seen working on production earlier today by the session that shipped #843.

## Verdict

**About 7 out of 10. The rules are sound and are kept. What holds it back is that the rules are written once but the story is drawn and serialised several times, and the public pages stop short of what a search engine and a sharing reader need.**

What is already professional and stays as it is:

- **One model, one public rule.** `Announcement::publiclyListed()` is read by the feed, the news page, the article, the page head, the document download and the sitemap.
- **Fail-closed tenancy.** A draft, an expired story, a targeted story and a missing id all answer the same 404. Seen live.
- **Safe content.** Plain text only, links limited to `https://` with `rel="noopener noreferrer nofollow ugc"`, covers re-encoded with EXIF dropped, PDFs checked by their bytes and always downloaded, never shown inline.
- **A real publishing desk.** Draft, scheduled, published and archived come from two dates. Publishing asks first and notifies once. Every state change is in the audit log.
- **A public site that works.** Search, category filter, pager, filters in the address, four states, a paused state, Amharic and the Ethiopian calendar. Seen live.
- **Tests that pin the rules**, on both sides.

## Findings

### A. Fragmented code

Each row is something written more than once. The right-hand column is its one home after the upgrade.

**Backend**

| # | What | Where the copies are | One home |
|---|---|---|---|
| A1 | **A story is serialised twice.** The public shape cuts the title to 150 characters, strips markup from the body and formats the date; the in-app shape does none of those. The excerpt length `280` is a literal in both | `LandingFeedController::newsSummary()` (`:245`) and `AnnouncementResource::toArray()` (`:35`) | One presenter, `App\Support\Story` |
| A2 | **The body is cleaned on one side only.** The public article strips markup; the in-app list, pane and editor preview send the text as stored. The preview is described as "exactly what the public will read", and for a story containing `<` it is not | `LandingFeedController.php:221`, `AnnouncementResource.php:38` | `Story::body()` for every reader |
| A3 | **Text is shortened three ways** | `PlainExcerpt::of()`, `PortalHead.php:153` (its own strip and collapse), and the raw body in the app | `PlainExcerpt`, with a one-line variant for a meta description |
| A4 | **Priority order is raw SQL in two controllers**, and the two rank differently (one places low after normal, the other folds them) | `AnnouncementController.php:49`, `LandingFeedController.php:237` | `Announcement::scopeReadingOrder()` and `scopeNewsOrder()` over one rank expression |
| A5 | **"May this reader open it" is restated three times**: published, not expired, visible to the user, or a manager | `AnnouncementController::index()` and `show()`, `AnnouncementFileController::download()` | `Announcement::scopeReadableBy(User)` |
| A6 | **"Does this organisation show news" is asked six times in four files** | `LandingFeedController` (2), `PortalPage` (2), `NewsFileController`, `OrganisationSitemapController` | `TenantLanding::showsNews(Tenant)` |
| A7 | **Closed lists are string literals**, against the project's own rule that a closed list is a PHP enum plus one options module | Priority `in:low,normal,high,urgent` in two requests and four `<SelectItem>`s; target `in:all,department,branch` in two requests; categories as `Announcement::CATEGORIES` and again as a literal array in `landing-feed.ts:21` | `AnnouncementPriority`, `AnnouncementCategory`, `AnnouncementTarget` in `api/app/Enums/`, and `src/src/lib/options/announcement.ts` |
| A8 | **Cover sizes are named twice** | The `match` in `NewsCoverController` and `FileStorageService::COVER_VARIANT_WIDTHS` | The constant |
| A9 | **The cache rule covers two of three public endpoints.** The article answers `no-cache, private` with no `Vary`; the feed and the list answer `private, max-age=120` | `LandingFeedController::announcement()` | The existing `CACHE` constant, on all three |
| A10 | **The audience is defined twice**, once for reading and once for sending | `Announcement::scopeVisibleTo`, `NotifyAnnouncementAudienceJob::resolveAudience` | Already planned as `scopeAudienceOf()` in communication PR 3. Listed so the count is complete |

**Frontend**

| # | What | Where the copies are | One home |
|---|---|---|---|
| A11 | **A story row is drawn three ways.** `ORGANISATION-NEWS.md` says `NewsCard` is the one card. It is the one card on the public pages only | `NewsCard` (`news-card.tsx:102`), the in-app list (`announcements/page.tsx:216-316`), the dashboard card (`announcements-widget.tsx:124`) | `NewsCard` with a density and an actions slot |
| A12 | **The text is clamped by three rules on top of the server's excerpt.** The same document says both CSS clamps went | `line-clamp-4` and `line-clamp-5` in `NewsCard`, `line-clamp-2` in the dashboard card, none in the in-app list | One clamp per density, inside the card |
| A13 | **Two list engines.** The public list keeps its own address state and its own pager; the in-app list uses the shared ones. Search and category exist in one only | `news-listing.tsx:27-89` and `:350-382`, against `useQueryFilters` and `PaginationControls` in `announcements/page.tsx` | One `StoryBrowser`, on the shared hook and the shared pager |
| A14 | **The portal page scaffold is written twice**: host, links, feed, sections, paused, missing, the document title, and an identical breadcrumb | `news-listing.tsx:91-162`, `news-article.tsx:34-98` | `usePortalPage()` and `PortalBreadcrumb` |
| A15 | **The paragraph and link renderer is written twice**, character for character | `article-view.tsx:77-99` and `FaqSection` in `portal-view.tsx:654` | One `PlainText` component |
| A16 | **A document row is drawn twice** | `article-view.tsx:112-162`, `announcements/page.tsx:265-298` | One `AttachmentList`, taking a link or a download handler |
| A17 | **Four types for one story**, joined by a hand converter | Generated `LandingAnnouncement` and `Announcement`, hand-written `ArticleContent`, `NewsSummary`, and `articleOf()` | Generated types only, once A1 makes the two API shapes agree |
| A18 | **Two vocabularies for empty, missing and error**, and the public pages build the four states by hand, against Convention 13 | `NewsState`, against `EmptyState`, `ErrorState` and `QueryBoundary` | `QueryBoundary`, with `NewsState` as its public skin |

### B. Defects seen on the live pages

| # | What | Evidence | Weight |
|---|---|---|---|
| B1 | **Two `<meta name="description">` tags after the page loads.** The server writes the organisation's own; React then adds back the export's generic "News and announcements." A crawler that runs scripts sees both | Counted on `habru-woreda.ethr.et/news` and on an article. `PortalHead::inject()` rewrites `robots` in the React payload (`statePayloadRobots`) and does not do the same for the description | Medium. The sign-in page is served the same way and should be checked in the same PR |
| B2 | **The story's text is not in the page source.** Only the head carries it; the body arrives by script. The `NewsArticle` record has a headline and description but no `articleBody`, `inLanguage` or `author` | Raw HTML of an article searched for its title and first words: neither is in the body | Medium for search and for link-preview readers that run no scripts |
| B3 | **The head is English whatever the story is.** `News · {name}` and "News and announcements from…" are literals; `og:locale` is `en_US` on an Amharic story | `PortalHead.php:133-134`, `:246-249`, `:267`. Seen on Habru Woreda, whose page reads in Amharic | Medium |
| B4 | **`article:modified_time` is missing** though the record has `dateModified`. The sitemap has no `<lastmod>`. The list page has no structured data at all | Head and sitemap as served | Low |
| B5 | **One cover answered 500 once, across the handful of loads in this pass.** A cold list page asks PHP for four JSON responses and up to nine images together. A failed cover shows as a broken image; nothing retries or hides it | Console of the first load of `habru-woreda.ethr.et/news`; the same address answered 200 when asked again | Low, but it is the visitor's first impression |
| B6 | **Story titles are not headings and the list is not a list.** A screen-reader user cannot move from story to story by heading | The list page has one `h1` and no `h2` or `h3` for seven stories; cards are bare links in a grid | Medium (accessibility) |
| B7 | **"Featured" has no limit and loses its meaning.** Five of seven stories on a live page carry the star. Only the first becomes the lead | Seen on Habru Woreda. No rule in either request or the editor | Low. A wording and guidance fix, not a schema change |
| B8 | **The public pages have no accessibility, phone-width or Amharic layout test.** `e2e/announcements.spec.ts` checks one word on `/news`; `accessibility.spec.ts`, `responsive.spec.ts` and `amharic-layout.spec.ts` name no news page | `src/e2e/` | Medium. The pages passed by hand today; nothing keeps them passing |
| B9 | **The editor has no unsaved-changes warning**, which the project's form rules ask of every form | `announcement-editor.tsx` | Low |

### C. What the documents say that the code does not

- `ORGANISATION-NEWS.md` → *One announcement, one presentation* says `NewsCard` draws a story as a card everywhere, and that both CSS clamps went. Neither is true yet (A11, A12). The PR that makes it true corrects nothing; a PR that does not must correct the document.

## The one shared approach

**The rule: a story has one definition per question, and each question has one file.** Nothing new is invented; each home below already exists or is the smallest step from what does.

| Question | The one home | Read by |
|---|---|---|
| What is public | `Announcement::publiclyListed()` *(exists)* | Feed, list, article, head, download, sitemap, feed file |
| Does this organisation show news | `TenantLanding::showsNews()` | The same six callers |
| May this signed-in reader open it | `Announcement::scopeReadableBy()` | In-app list, single read, document download |
| In what order | `scopeNewsOrder()`, `scopeReadingOrder()` | Feed, list, related, in-app list |
| What a story looks like as data | `App\Support\Story`: `summary()`, `article()`, `body()` | `LandingFeedController`, `AnnouncementResource`, `PortalHead` |
| How text is shortened | `PlainExcerpt` *(exists)* | Cards, lists, feed, meta description |
| Which values a closed field may hold | Three enums and `lib/options/announcement.ts` | Requests, editor, filters, labels |
| How a story is drawn | `features/news/components/`: `NewsMeta`, `NewsCard`, `ArticleView` *(exist)*, plus `PlainText`, `AttachmentList`, `StoryBrowser` | Public pages, portal section, in-app list, dashboard card, reading pane, editor preview |
| How a portal page is framed | `usePortalPage()`, `PortalBreadcrumb`, `PortalChrome` *(exists)* | News list, article, and any later public page |

**What keeps it true after the merge.** Removing copies is worth little if the next one can be pasted in a month. Two guard tests ship with the refactors:

- **Pest, `StorySingleSourceTest`.** Fails when a priority `CASE` appears outside the model, when `publicSections(...)['announcements']` appears outside `TenantLanding`, or when the public summary has a key the in-app resource lacks. Tokenised, as the project's other inventory tests are, so a comment cannot trip it.
- **Vitest, `story-one-renderer.test.ts`.** Fails when `NewsMeta` is imported outside `features/news/`, or when the link attributes `noopener noreferrer nofollow ugc` appear in more than one source file.

## Checklist: a professional SaaS news system

**Has** means present and seen or pinned by a test. **Partial** means present with a gap named here. **Missing** means absent. The last column says where it is fixed: **N1 to N4** are this plan's PRs; **C3, C4, C6** are the communication plan's PRs 3, 4 and 6 (the announcements plan's B, C and D).

### Writing and publishing

| Item | State | Where |
|---|---|---|
| Create, edit, delete a story | Has | |
| Draft, scheduled, published, archived | Has | |
| Publish at a future time | Has | |
| Take a story down at a set time | Partial: the API stores `expires_at`; the editor offers only Archive now | C3 |
| Preview that matches what is published | Partial (A2) | N1 |
| Confirmation before the step that cannot be undone | Has | |
| Category | Has: four fixed, translated | |
| Priority | Has | |
| Featured or lead story | Partial (B7) | N4 |
| Cover photo with responsive sizes | Has | |
| Documents attached to a story | Has: three PDFs | |
| Warning before losing unsaved text | Missing (B9) | N4 |
| A story in more than one language | Missing | Later, owner decision |
| Formatted text (bold, lists, inline images) | Declined | See *Not building* |
| Version history of the text | Partial: the audit log records who and when, not what changed | Later |
| Restore a deleted story | Declined: Archive is the reversible step, and Delete asks first | |

### Control and governance

| Item | State | Where |
|---|---|---|
| A permission for publishing | Has: `announcement.manage` | |
| Audit trail of every state change | Has | |
| Readers told once, never twice | Has | |
| A second person approves before publication | Missing | Later, owner decision |
| A support session cannot publish in an organisation's name | Not blocked today. `BlockImpersonatedActions` names no announcement action | Owner decision |
| Platform switch to pause public news | Has: `public_news`, answered 503 | |

### Audience and delivery

| Item | State | Where |
|---|---|---|
| Whole organisation, branch, department | Partial: the API accepts them; the editor does not offer them | C3 |
| Team, and a department with its sub-departments | Missing | C3 |
| In-app notification that opens the story | Has | |
| Email, SMS, phone push per reader's choice | Partial | Communication plan PRs 1, 2 and 5 |
| Who has read it; required acknowledgement | Missing | C4 |

### Reading in the app

| Item | State | Where |
|---|---|---|
| Open a story in full, with a shareable address | Has: `?read=` | |
| Download its documents | Has | |
| Pager | Has | |
| Search and category filter | Missing in the app; present on the public page | N2 builds the shared browser; C3 switches the filters on |
| Unread mark | Missing | C4 |
| Dashboard card | Has | N2 moves it onto the shared card |

### The public website

| Item | State | Where |
|---|---|---|
| News list: search, category, pager, filters in the address | Has | |
| Article page with related stories | Has | |
| Empty, missing, error and paused states | Has | N2 moves them onto `QueryBoundary` |
| A draft is indistinguishable from a missing story | Has | |
| Title, description, canonical address, link preview, structured data | Partial (B1 to B4) | N3 |
| Text readable without scripts | Missing (B2) | N3 |
| Sitemap | Partial: no `lastmod` | N3 |
| A feed for readers and aggregators (Atom) | Missing | N3 |
| Share a story | Missing | C6 |
| Print a story with the letterhead | Missing | C6 |
| "Updated on" when a story changed after publication | Missing | N4 |
| View counts per story | Missing | Later, owner decision |
| Human-readable words in the address | Declined | See *Not building* |

### Accessibility, language and phones

| Item | State | Where |
|---|---|---|
| English, Amharic, Afaan Oromoo interface | Has | |
| Ethiopian calendar for the reader | Has | |
| Amharic line height | Has: 1.7 | |
| No sideways scroll at 375 px | Has, by hand | N2 makes it a test |
| Headings and list structure for a screen reader | Missing (B6) | N2 |
| Automated accessibility check of the public pages | Missing (B8) | N2 |
| Head and structured data in the story's language | Missing (B3) | N3 |

### Security and privacy

| Item | State | Where |
|---|---|---|
| Tenant isolation, fail closed | Has | |
| No markup can run | Has | |
| Uploads checked by content, images re-encoded | Has | |
| Downloads forced, `nosniff`, no inline rendering | Has | |
| Public endpoints rate limited | Has: `throttle:api` | |
| Search text treated as literal | Has | |
| No third-party scripts, pixels or trackers | Has, and stays | |
| A draft's cover photo is readable by whoever has its address | Accepted and documented in `ORGANISATION-NEWS.md` | |

### Operations and quality

| Item | State | Where |
|---|---|---|
| Backend and component tests on the rules | Has | |
| A browser journey that reads back what was published | Partial: one journey, one word | Each PR adds its own |
| A guard against the next duplicate | Missing | N1, N2 |
| Images survive a slow or failed load | Missing (B5) | N4 |
| Operator document that matches the code | Partial (section C) | Each PR |

## Not building, and why

- **A new news module, a CMS, or a second model.** The model, the rules and three of the renderers are right. Everything here moves existing code to one place.
- **Formatted text.** Plain text is why nothing in a story can run. Decided in `ORGANISATION-NEWS.md` and unchanged.
- **Words in the address (`/news/2019-budget-award`).** The article address is matched by one rewrite rule on a ULID (`render-htaccess.php:93`). A slug needs a new rule, a uniqueness rule per organisation, and redirects when a title changes. The ULID address is stable and already indexed.
- **Comments and reactions.** They turn a notice into a conversation that someone must moderate.
- **Tags.** Four translated categories are a filter people use. Free tags need curation nobody will do.
- **Tracking parameters and share counters.** They follow readers and need third parties.
- **Automatic posting from social media.** Already decided: a person publishes.

## The upgrade: four small PRs

Each is merged and released before the next is cut. Each starts from `origin/main` in a worktree of its own; the shared checkout is not pulled, stashed or committed in. The untracked root `package-lock.json` is never committed.

### N1. One story contract (backend refactor)

*Closes A1 to A9. The only behaviour changes are the disagreements closed.*

- `App\Support\Story` with `summary()`, `article()` and `body()`, and the excerpt and title lengths as constants. `LandingFeedController` and `AnnouncementResource` both build from it. The in-app resource keeps its extra fields (status, target, author, dates).
- `Announcement::scopeReadableBy()`, `scopeNewsOrder()`, `scopeReadingOrder()`; `TenantLanding::showsNews()`. The three controllers and the sitemap call them.
- Enums `AnnouncementPriority`, `AnnouncementCategory`, `AnnouncementTarget`, validated with `Rule::enum()`; `lib/options/announcement.ts`; the `lib/options/contract.ts` check; labels in `en`, `am` and `om` covered by `option-lists.test.ts`. `NEWS_CATEGORIES` and the four priority `<SelectItem>`s read the options module.
- The public article gets the `CACHE` header and `Vary`. `NewsCoverController` reads its sizes from the storage constant.
- `StorySingleSourceTest`.
- **No migration. No queued constructor changes. The bypass inventory stays at 208 across 83.**

**Implementation brief (written 2026-11-23, after N3a; starts only once #862 is merged, because both touch `Announcement.php` and `LandingFeedController.php` and stacked PRs conflict after a squash merge).** Cut from `origin/main` in a worktree of its own. Already done by N3a and not to be redone: `scopeNewsOrder()` and `NEWS_PER_PAGE` on the model.

1. **Contract first, so the API's shape is pinned before anything moves.** Add a Pest test that records, for one fixture story, the exact JSON of `GET /auth/tenant-landing/news`, `/auth/tenant-landing/announcements/{id}`, `/auth/tenant-landing/feed` and `GET /announcements/{id}`. Every later step must leave the three public responses byte-identical. The in-app one may change only as listed in step 2.
2. **`App\Support\Story`.** `summary(Announcement, string $tenantPublicId): array` returns exactly today's public summary keys. `body(Announcement): string` is `PlainExcerpt::of($body, BODY_MAX)`. The constants are `TITLE_MAX = 150`, `EXCERPT_MAX = 280` and `BODY_MAX = 10000`. `LandingFeedController::newsSummary()` and its body line call it. `AnnouncementResource` takes `excerpt` from it and **`body` from `Story::body()`**. That is the one in-app change (A2): markup is stripped for the reader, as the public article already does. The editor needs the raw text to edit, so the resource keeps it as `body_source`, returned only to `announcement.manage`. Check first: if `select count(*) from announcements where body regexp '<[a-z/]'` is zero on production (ask the owner, or read it from a backup), skip `body_source` and record why.
3. **`Announcement::scopeReadableBy(?User)`** = `published()->notExpired()->visibleTo($user)`, or no constraint for a manager. `AnnouncementController::index()`, `show()` and `AnnouncementFileController::download()` use it. **`scopeReadingOrder()`** takes the in-app `CASE` (urgent, high, normal, low). The two orders stay different on purpose: the public page leads with featured stories, the in-app list does not. The docblock says so, so the next reader does not merge them.
4. **`TenantLanding::showsNews(Tenant): bool`.** The six `publicSections(...)['announcements']` reads in `LandingFeedController`, `PortalPage`, `NewsFileController` and `OrganisationSitemapController` call it.
5. **Enums, in the #817 pattern** (`api/app/Enums/MaritalStatus.php`, `src/src/lib/options/marital-status.ts`, `lib/options/contract.ts`, `src/test/option-lists.test.ts`):
   - `AnnouncementPriority` (`low`, `normal`, `high`, `urgent`), `AnnouncementCategory` (the four in `Announcement::CATEGORIES`, which then reads the enum), and `AnnouncementTarget` (`all`, `department`, `branch`).
   - Validated with `Rule::enum()` in `StoreAnnouncementRequest`, `UpdateAnnouncementRequest` and `LandingNewsRequest`.
   - `src/src/lib/options/announcement.ts` takes the lists and one label function per list, reusing the existing reviewed keys (`common.low`, `common.normal`, `landing.important`, `landing.urgent`, `news.category_*`). No new strings.
   - `NEWS_CATEGORIES` in `landing-feed.ts` and the editor's priority and category `<SelectItem>`s read the module.
   - `contract.ts` gains three lines. `generated.ts` will change: the schema names for the three enums appear. That change is expected; regenerate it and commit it.
6. **The two small ones.** The public article answers with `LandingFeedController::CACHE` and `Vary: Host, X-Tenant`, as the list does. `NewsCoverController` derives its accepted sizes from `FileStorageService::COVER_VARIANT_WIDTHS`.
7. **`StorySingleSourceTest`** (Pest, tokenised like `TenantScopeBypassInventoryTest`). It fails when a `CASE priority` appears outside `Announcement.php`, when `publicSections(` is indexed with `'announcements'` outside `TenantLanding.php`, or when `in:low,normal,high,urgent` or `in:all,department,branch` appears anywhere in `app/`.

**Proof.** The step 1 contract test is green unchanged at the end, apart from the in-app `body`. Run `gates.sh backend` and `gates.sh frontend`, because the options module and `generated.ts` move. Run one Playwright check: the editor still saves a story with each priority, and the public article reads the same as before. N1 touches tenant-scoped reads, so its review is `/code-review` at high effort or an Opus review agent.

### N2. One story on the screen (frontend refactor)

*Closes A11 to A18, B6, B8.*

- `NewsCard` gains a `density` (`card`, `feature`, `row`, `compact`), an `onOpen` target beside `href`, and an `actions` slot. The in-app list and the dashboard card render it. Story titles become headings; lists become lists.
- `StoryBrowser`: the body of `news-listing.tsx`, taking a data source and a card renderer, on `useQueryFilters` and `PaginationControls`. `NewsListing` stays a thin public wrapper that keeps the paused and missing states. The in-app page renders the same browser.
- `usePortalPage()`, `PortalBreadcrumb`, `PlainText`, `AttachmentList`. `ArticleContent` and `articleOf()` go once the generated types agree.
- `/news` and `/news/{id}` are added to `accessibility.spec.ts`, `responsive.spec.ts` and `amharic-layout.spec.ts`.
- `story-one-renderer.test.ts`.
- **This is the `NewsBrowser` extraction that communication PR 3 assumes.** Run N2 before it, and PR 3 only switches search and category on for the in-app source.

### N3. A head a search engine and a sharing reader can use

*Closes B1 to B4. Touches `PortalHead`, `PortalPage`, the sitemap and two `metadata` blocks, so it does not depend on N1 or N2 and can ship first if the owner wants the live defect closed soonest.*

**Started 2026-11-23 on branch `feat/news-head`, and split in two.** The feed is a new public endpoint and gets a review of its own.

**N3a, the head (built 2026-11-23, branch `feat/news-head`; not yet merged or released):**

*Proof before release.* The branch's `PortalHead::inject()` was run over the three pages production served on 2026-11-23 (Habru Woreda's `/login`, `/news` and one article), and each result was hydrated in Chrome by the real production bundle, with the service worker blocked so the test page was the one served. Before: two description tags on all three. After: one on all three, the organisation's own, and the article still rendered in full. That proves the payload shape on the live export is the one the code matches. The check on production itself, after release, is still owed.

- One description after the page loads. The organisation's description is written into the React payload as `robots` already is, in `PortalHead::inject()`, so the sign-in page, the news page and an article are all fixed by one change. Removing the description from the exported `metadata` was the other option and was not taken: it would fix the news pages only and take the description off the apex sign-in page.
- The `NewsArticle` record gains `articleBody` (bounded), the organisation as `author`, and `inLanguage` only when the story's script and the organisation's language agree. The head gains `article:modified_time` and a `BreadcrumbList`.
- The list page gains a `CollectionPage` with an `ItemList` of its first page, in the page's own order, and a `BreadcrumbList`. The order moves onto the model as `scopeNewsOrder()` rather than being written a third time, which brings that one piece of N1 forward.
- The head's fixed words and `og:locale` follow the organisation's language (`default_locale`), using the translations already reviewed for the page itself.
- `<lastmod>` in the sitemap, from `updated_at`.
- **`hreflang` is left as it is.** The plan first said to declare alternates only where the page differs by language. On reading the sitemap code that was wrong: `?lang=` does change the page's interface language, the sitemap and the head are deliberately kept in step, and changing one means changing both and the search-listings runbook. Not worth it for this defect.

**N3b, the feed (not started):**

- An Atom feed of the newest public stories at an API address that names the organisation, as covers and documents already do, with a `<link rel="alternate">` in the head. **No rewrite rule changes.**

**Assumptions checked before any code (2026-11-23):**

- (c) The organisation's language is stored, as `default_locale`. It is the interface default, not the language of each story, which is why `inLanguage` is stated only when the story's script agrees with it.
- (d) Holds. The live pages carry `\"name\":\"description\",\"content\":\"…\"` in the payload in the same shape as the `robots` entry.
- (e) Holds. `/login` on an organisation's host shows the same two description tags after load. The title is single.

### N4. Small things a publisher and a visitor notice

*Closes B5, B7, B9.*

- The editor warns before unsaved text is lost.
- The editor says how many stories are featured and which one leads. The star shows on the lead only.
- An article edited after publication says when it was updated.
- A cover that fails to load is retried once, then its space is closed, so no broken image is shown.

### Where this sits with the communication plan

| Order | PR | Why here |
|---|---|---|
| any time | N3 | Different files from everything else |
| 1 | N1 | The contract the rest reads |
| 2 | N2 | The screen pieces C3 and C6 build on |
| 3 | C3: audience, expiry, in-app search | Uses `StoryBrowser`, the enums and `scopeReadableBy()` |
| 4 | C4: read state and acknowledgement | Uses the shared card and pane |
| 5 | C6: share and print | Its `ShareMenu` lives in `features/news/components/`; the previews it relies on come from N3 |
| 6 | N4 | Polish, after the larger moves settle |

Communication PRs 1, 2 and 5 touch notifications and mail, not news, and can run beside N1 to N3.

## Later, each an owner decision

- **Stories in more than one language** (PRD FR-09.4). The largest content gap for an organisation that publishes in Amharic and English. It changes the model, the head, the sitemap and the feed together.
- **A second person approves before publication.** Relevant to a government bureau or a bank.
- **A support session cannot publish.** Whether an operator may publish in an organisation's name is a policy choice; today nothing stops it.
- **View counts per public story**, counted on the server with no cookie and no third party.
- **Telegram and YouTube as sources of drafts.** Designed in the announcements plan.

## Binding for every PR

1. **Assumptions are checked first, and each result goes in the PR body.** If one fails, that item changes, not the plan.
   - (a) Scramble types `AnnouncementResource` correctly when it is built from `Story::summary()`. Spread-built arrays have been typed as tuples before.
   - (b) Whether any stored body contains markup. If none does, A2 is a one-line change; if some do, cleaning at write is added with a test that an edit does not alter a clean story.
   - (c) Where an organisation's portal language is stored, and whether it is reliable enough to state as `inLanguage`.
   - (d) Whether the React payload can carry the description the way it carries `robots`, on the static export as served by Apache, not on the dev server.
   - (e) Whether `/login` shows the same duplicate description.
   - (f) `useQueryFilters` can carry `q`, `category` and `page` together with `read`.
2. **A committed Playwright journey per PR**, against `scripts/local-production/up.sh` with `verify.sh` ending `0 failed`, asserting what the page and the API read back, never a toast.
   - N1: the same story returns the same title, excerpt and body from the public article and the in-app read.
   - N2: a story reads the same on `/news`, the in-app list and the dashboard card; zero serious or critical accessibility violations on `/news` and `/news/{id}`; 375 and 1280 px; en and am.
   - N3: exactly one description after load; the structured data parses; the feed is valid Atom.
   - N4: leaving the editor with unsaved text asks first.
3. **Step 3 of the model rule on every PR.** A review not anchored on the implementer's view. Every finding is verified and fixed, or declined with a reason in the PR body.
4. **Each PR reverts on its own.** None carries a migration.
5. **House style.** New strings in `en`, `am` and `om`: no long dash, no emoji, no exclamation mark, under their page's namespace, passing the dictionary-weight test. The Amharic is listed once in the PR body for the owner's read.
6. **Never a comment where Scramble reads it.** `Story` returns arrays the contract is generated from.
7. **Documents close the loop in the same PR:** `ORGANISATION-NEWS.md`, BASELINE §25, the QA cases in `08-E2E-COMMUNICATION-INTEGRATIONS-CONSOLE.md`, and the Amharic admin guide where a screen changes.
8. **After release, read it back on production**: `release.json`, then the PR's journey in QA Audit only. Customer organisations stay read-only. QA Audit has no public story today, so N3's check needs one published there first.

## How to tell it worked

- **After N1 and N2:** both guard tests pass, and the fragmentation table above has no row left with two locations.
- **After N3:** one description tag on a live article after load; a link pasted into Telegram previews with the story's own title, text and cover; the Rich Results test accepts the article.
- **After C4:** "Read by X of Y" on a real notice.

## Model policy

This review is step 1. Implementation runs on Sonnet. N1 touches tenant-scoped queries, so its review is `/code-review` at high effort or an Opus review agent; the others take a standard review. Step 5 is each PR's Playwright journey against the local production stack before any deploy.

## Files that carry the work

- `api/app/Models/Announcement.php`, `api/app/Http/Resources/AnnouncementResource.php`, `api/app/Http/Controllers/Api/V1/Auth/LandingFeedController.php`, `api/app/Http/Controllers/Api/V1/Announcement/`
- `api/app/Support/PlainExcerpt.php`, `PortalHead.php`, `PortalPage.php`, `TenantLanding.php`; `api/app/Http/Controllers/NewsCoverController.php`, `NewsFileController.php`, `OrganisationSitemapController.php`
- `api/app/Http/Requests/Announcement/`, `api/app/Enums/`
- `src/src/features/news/`, `src/src/features/announcements/`, `src/src/features/auth/landing-feed.ts`, `src/src/features/auth/components/portal-view.tsx`, `src/src/features/dashboard/components/announcements-widget.tsx`
- `src/src/app/(auth)/news/`, `src/src/app/(dashboard)/announcements/page.tsx`, `src/src/lib/options/`
- `src/e2e/announcements.spec.ts`, `accessibility.spec.ts`, `responsive.spec.ts`, `amharic-layout.spec.ts`
