# Organisation questions (FAQ)

An organisation's public page (its sign-in portal) can answer the questions
people ask most: payday, working hours, how to apply. Added 2026-10-16.

## Where it is edited

Settings → Sign-in page → **Questions**. Up to **12** questions, each with a
question (at most 160 characters) and an answer (at most 1000) in English,
Amharic or both. They can be reordered and removed; a question left wholly
blank is dropped on save. The live preview beside the form shows the section
as it will render.

The section can be switched off and moved like any other under **Sections**.
It is on by default and draws nothing until a question is written.

## What the public sees

A "Frequently asked questions" section, each question opening to its answer
(native `details`, so it works by keyboard, screen reader and without
script). The viewer's language is shown, falling back to the other one.
Answers are plain text: a blank line starts a paragraph and an `https://`
address becomes a link, as in a news story. Nothing an organisation writes
can become markup.

The footer lists it as **FAQ**; the header keeps to About, News, Careers and
Contact.

## Rules the server applies

| Where | Rule |
|---|---|
| Save (`PUT /settings/landing`) | `faqs` replaces the whole list; a save that omits it keeps the stored list. Each item takes only `question`, `question_am`, `answer`, `answer_am`, and needs a question and an answer in at least one language |
| Serve (`TenantLanding::faqs`) | Only complete pairs leave, at most 12, each text trimmed to its limit, whatever the stored row holds |
| Order | `faq` sits before `contact`. An organisation whose saved order ends with Contact keeps Contact last; otherwise the new section joins the end |

Changes are recorded in the audit log as `settings.landing_updated`, with
the before and after values.

## Deploying it

No migration: the questions live in the existing `tenants.landing` JSON. The
release needs `clear` and `optimize` as usual.
