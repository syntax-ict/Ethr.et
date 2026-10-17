# Organisation services

An organisation's public page (its sign-in portal) can say what it offers:
its products, services or programmes. Added 2026-10-16, beside the questions
section ([`ORGANISATION-FAQ.md`](ORGANISATION-FAQ.md)), and edited the same way.

## Where it is edited

Settings → Sign-in page → **Services**. Up to **8** services, each with a name
(at most 80 characters) in English, Amharic or both, and an optional
description (at most 400). They can be reordered and removed; a service left
wholly blank is dropped on save. The live preview shows the section as it will
render.

The section can be switched off and moved under **Sections**. It is on by
default and draws nothing until a service is written.

## What the public sees

An "Our services" section of cards, one per service: its name and, when it
has one, its description, in the viewer's language with the other as
fallback. Plain text only. The header lists it as **Services**, after About.

## Rules the server applies

| Where | Rule |
|---|---|
| Save (`PUT /settings/landing`) | `services` replaces the whole list; a save that omits it keeps the stored list. Each item takes only `title`, `title_am`, `description`, `description_am`, and needs a name in at least one language |
| Serve (`TenantLanding::services`) | Only named services leave, at most 8, each text trimmed to its limit, whatever the stored row holds |

## Where a new section lands

`services` sits after `about` in the default order. An organisation that saved
its own order before the section existed gets it placed right after the
nearest section that comes before it in the default order (`about`), and the
same rule places questions after holidays. This replaced the narrower "Contact
stays last" rule that shipped with the questions section, and gives the same
result for it.

## Deploying it

No migration: the services live in the existing `tenants.landing` JSON. The
release needs `clear` and `optimize` as usual.
