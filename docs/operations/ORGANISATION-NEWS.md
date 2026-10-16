# Organisation news pages

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

## Search engines

Listing is the organisation's choice (Settings → Sign-in page → listing, off by
default). When it is on and the organisation has its own host, the news page and
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
