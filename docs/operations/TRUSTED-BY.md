# "Trusted by" on the public site

Added 2026-10-18. The landing page at ethr.et lists organisations that use ETHR,
under **Trusted by**: each one's name, its uploaded logo, and a link to its
public page (its entry address, `ethr.et/{slug}` or its own host). Nothing else
about an organisation is published.

## Who appears

Naming a customer is a public claim, held to the rule the testimonial already
follows (`SiteContentResource` publishes a quote only with a recorded consent
date). An organisation appears only when **all three** hold
(`Tenant::scopeShowcased`):

| Condition | Who decides | Where |
|---|---|---|
| It agreed to be named | The organisation's administrator (`settings.manage`) | Settings → Branding → *ETHR website*. Off by default; the time and who agreed are stored (`showcase_consented_at`, `showcase_consented_by`) and audited (`settings.showcase_consented` / `_withdrawn`) |
| It is an active customer | The subscription | `status = active`. Trials, suspended and cancelled organisations are never named |
| The platform has not hidden it | A platform admin (`admin.manage`) | Console → the tenant → *On the ETHR website*. A veto for test organisations or anything else that should not be named; the organisation's own consent is untouched. Audited (`admin.tenant.showcase_hidden` / `_unhidden`) |

With nobody to name, the section is not drawn at all.

## How it is served

`GET /api/v1/trusted-by`, public and unauthenticated like `/site-content`,
throttled by `public-catalogue`, at most 48 organisations in the order they
agreed. Cached for ten minutes under `public.trusted-by`; every consent,
veto and status change clears it.

## Deploying it

Needs `migrate` (three nullable columns on `tenants`), then `clear` and
`optimize`. No seed. Until an active organisation agrees, the public page is
unchanged.
