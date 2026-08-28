# Industry Templates & Smart Configuration

ETHR offers 27 selectable industries, each an alias onto one of 8 maintained base
organization templates plus a thin override patch. Adding an industry is a row in
`IndustryCatalog`, not a new hand-authored template. See ONBOARDING_V2.md D2–D3.

## The 8 base templates

Seeded by `OrganizationTemplateSeeder` into `organization_templates`. Each
`template_data` carries: `departments`, `positions`, `grades`, `shifts`,
`leave_types` (codes resolved via `LeaveTypeCatalog` to statutory Ethiopian
defaults), and `settings` (payroll, attendance method, employee-number format).

`government`, `bank`, `hospital`, `manufacturing`, `ngo`, `hotel`, `university`,
`general`.

## The 27 industries — `App\Services\Onboarding\IndustryCatalog`

Grouped, each `→ base`:

- **Public sector → government**: federal_government, regional_government, city_administration, woreda_administration, ministry
- **Education → university**: university, tvet, school
- **Healthcare → hospital**: hospital, health_center
- **Nonprofit → ngo**: ngo
- **Financial → bank**: bank, insurance, microfinance
- **Industrial → manufacturing**: manufacturing, construction, agriculture
- **Hospitality & trade → hotel**: hotel, retail, wholesale
- **Services & tech → general**: logistics, transport, telecom, security_company, bpo, technology_company
- **Other → general**: custom

An override patch may replace a list section (e.g. curated departments) or
deep-merge `settings` (e.g. a distinct employee-number prefix).

## Smart configuration engine — `IndustryProfileResolver`

Produces a scored, editable `ConfigurationPlan`. Each section's provenance
(`ConfigurationSource`) fixes its confidence — a deterministic, auditable
substitute for an LLM score:

| provenance | confidence | meaning |
|---|---|---|
| `explicit` | 1.0 | from the industry override patch |
| `industry_default` | 0.8 | from the base template |
| `heuristic` | 0.6 | derived from tenant signals (size, region) |
| `global_fallback` | 0.4 | last resort |

Heuristics: a region-aware headquarters branch when the template has none; an
attendance method chosen by headcount **only** when the template is silent
(templates always win).

## Provisioning — `OrganizationProvisioner`

Applies a plan (or a raw template) create-only and soft-delete aware: an existing
natural-key match is skipped, a trashed one is restored, so re-application is
idempotent and never overwrites tenant edits.

## Endpoints

| method | path | purpose |
|---|---|---|
| GET | `/api/v1/templates` | the 8 base templates (public) |
| POST | `/api/v1/onboarding/apply-template` | provision a base template |
| GET | `/api/v1/onboarding/industries` | the 27-industry picker |
| POST | `/api/v1/onboarding/configuration/preview` | scored plan (read-only) |
| POST | `/api/v1/onboarding/configuration/apply` | provision an edited plan |
