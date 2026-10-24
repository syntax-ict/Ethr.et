# The landing page: what the console controls, and how it reaches the site

**Prepared:** 2026-10-24. The public landing page (`www.ethr.et/en`, `/am`) reads its content from the database. This is what a platform operator edits, what the server refuses, where the pictures come from, and the one release step the page depends on.

## What is data, and what is not

The page has seven sections, in a fixed order the code knows: hero, problems and solutions, capabilities, how it works, industries, trust, and the call to action. Which sections exist and how each is drawn is code (`App\Support\MarketingContent` on the server, `components/marketing/landing/` in the frontend). Everything inside them is data, edited at **Platform → Marketing site** (`/admin/marketing`) by a super admin:

| Edited | Where it lives |
|---|---|
| A section's heading and subheading, in English and Amharic, and whether it is shown | `marketing_sections` |
| Each row's title, body, detail, icon, link and whether it is shown, in both languages | `marketing_items` |
| The order of rows in a section | `marketing_items.sort_order` |
| A picture for a problems row, a capabilities row, or the hero | the six `image_*` columns on the row, files under `platform/marketing/` |

Button labels, the "Business value" label, navigation and the trial note stay translation keys: changing them is a design decision, not a business one (`PLATFORM_MANAGED_CONTENT_PLAN.md` D1).

Each kind uses only some of a row's fields, and holds at most so many rows (`MarketingContent::spec()`): problems and capabilities use icon, title, body, detail and link and may carry a picture; how it works uses title and body; industries icon and title; trust icon, title and body; the call to action title alone. The console shows only the fields a section uses, and the API refuses the others with a 422.

## What the server refuses

Every text field, in both languages, is checked by `App\Rules\PermittedMarketingClaim` against the list in `App\Support\MarketingClaims`. The list is the outcome of the capability audit of 2026-10-17: features that are not built (performance reviews, training, goals), devices nobody has verified on hardware (any brand, "biometric", "fingerprint"), workflows that do not exist (Excel import, multi-level approval, delegation, maker-checker), legal conclusions that are the employer's to draw ("full compliance", "no data leaves"), figures nobody can substantiate ("500+", "99.9%", "uptime"), and integrations that do not exist (Telebirr). A sentence that matches is refused with `validation.marketing_claim`; the console shows the message beside the field. The seeded copy and the committed snapshot are both tested against the same list.

Links are internal paths only (`/payroll-software`, never `https://`). Icons are one of a fixed list, the names lucide uses.

Every write is audited under `platform.marketing.*` and appears in the platform audit view.

## Pictures

A row or the hero accepts one raster image (PNG, JPEG or WebP, at least 640 by 360, at most 4096 px and 5 MB). The server re-encodes it with GD to a JPEG at most 1920 px wide, plus 1280 and 640 px copies for `srcset`, so only pixels are served, with no metadata. The live host accepts 2 MB per request, so the console shrinks a larger file in the browser before uploading.

The public URL is `/api/v1/marketing-images/{owner}/{variant}/{version}`, where the version is a content hash: a new upload is a new URL and the old one stops answering, so a page can cache the image for a year. The image route does not check whether the row is published, so an operator can preview a draft row's picture; the hash is not guessable.

When a row has no picture, the page draws a scene for it in code, chosen by the row's slug. The seeded illustrations, and the ways of producing more, are in [`MARKETING-VISUALS.md`](MARKETING-VISUALS.md).

## First-run content and re-seeding

`MarketingContentSeeder` (part of `ProductionSeeder`) creates the seven sections and their rows in both languages with `firstOrCreate`: a re-seed adds what is missing and never overwrites an edited heading, row or picture. Pictures listed in `api/database/seeders/assets/marketing/manifest.json` are attached to rows that have none.

## The release step the page depends on

The frontend is a static export. Each page's HTML is built once, so the content a crawler and a first visitor see is whatever `src/src/lib/marketing/content-snapshot.json` held at build time; the live rows replace it in the browser as soon as `GET /api/v1/marketing-content` answers. After an operator edits the page, the next release must carry the new snapshot:

```bash
node scripts/fetch-marketing-snapshot.mjs --api https://www.ethr.et
node scripts/fetch-plans-snapshot.mjs --api https://www.ethr.et
```

Commit whichever file changed before merging to `main`. `MarketingSnapshotFreshnessTest` checks the snapshot's shape and claims, not its values: once the page is edited in production the snapshot rightly differs from the seeder.

## Caching

`GET /api/v1/marketing-content` is cached for five minutes under `platform.marketing_content` and the key is forgotten from both models' `saved` and `deleted` hooks, so an edit in the console is served within a request. The browser refetches a minute after it last did.
