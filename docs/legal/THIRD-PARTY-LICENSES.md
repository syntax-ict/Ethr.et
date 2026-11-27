# Third-party licences

> **DRAFT — NOT LEGAL ADVICE.** Counted from `api/composer.lock` and `src/package-lock.json` on 2026-11-25. Counsel has not reviewed it. Register row L9. **Recount when a lockfile changes**: the counts below are dated and will drift.

ETHR is proprietary ([`../../LICENSE`](../../LICENSE)), but most of the code that runs it is open source. Owning ETHR means owning *ETHR's* code and keeping the conditions of everyone else's. This page lists the licences, and the few that carry conditions worth a lawyer's attention.

## Why distribution matters

Most open-source conditions are triggered by **distributing** the software, not by running it. That splits ETHR into two cases:

- **The hosted service.** The backend runs on ETHR's server and is not handed to anyone. The frontend's JavaScript bundle *is* sent to every browser, which counts as distributing what is in it.
- **An on-premises installation**, if a government buyer ever asks for one. Handing over the backend distributes everything in it, and the LGPL conditions below then apply in full. **Price and paper an on-premises deal with this in mind.**

## Backend: `api/composer.lock`

| | Count | Licences |
|---|---|---|
| Production packages | 110 | MIT 97, BSD-3-Clause 5, Apache-2.0 3, LGPL 3, dual BSD/GPL 2 |
| Development-only packages | 60 | MIT 34, BSD-3-Clause 26. Not deployed |

| Package | Licence | What it means for ETHR |
|---|---|---|
| `dompdf/dompdf` | LGPL-2.1 | Renders payslips and reports as PDF. Used unmodified as a library: running it as a service has no obligation. On distribution (on-premises): ship its licence and source, let the recipient replace it, and do not prohibit the reverse-engineering needed to do that |
| `dompdf/php-font-lib` | LGPL-2.1-or-later | As above |
| `dompdf/php-svg-lib` | LGPL-3.0-or-later | As above, under LGPL-3.0 |
| `nette/schema`, `nette/utils` | BSD-3-Clause **or** GPL-2.0 **or** GPL-3.0 | ETHR takes them under BSD-3-Clause, the permissive option. Nothing to do beyond keeping the notice |

## Frontend: `src/package-lock.json`

1,251 entries, nearly all MIT, ISC, Apache-2.0 or BSD. These deserve a look:

| Package | Licence | Shipped to browsers? | What it means for ETHR |
|---|---|---|---|
| `react-leaflet`, `@react-leaflet/core` | **Hippocratic-2.1** | Yes: the map picker for attendance sites | An "ethical source" licence. It forbids use that violates human rights standards it lists, including some labour and surveillance standards, and terminates the licence if they are breached. **Counsel should read it before ETHR is sold for workforce monitoring to public bodies**, because a licence that can terminate on a disputed standard is a commercial risk. Replacing the map wrapper is possible if counsel advises it |
| `jszip` | MIT **or** GPL-3.0-or-later | Yes | Taken under MIT |
| `@sentry/cli` and its platform builds | FSL-1.1-MIT | No: a build tool | Functional Source Licence: free to use except to compete with Sentry; becomes MIT after two years. No issue for ETHR |
| `@img/sharp-*` (libvips) | LGPL-3.0-or-later | No: build-time image processing | Used to build, not shipped in the bundle |
| `caniuse-lite` | CC-BY-4.0 | No: build tooling | Attribution only |
| `buffers` | No licence stated | Pulled in by a dependency | "No licence" means no permission, strictly. Low risk as a transitive dependency; note it |
| `axe-core`, `lightningcss` (dev) | MPL-2.0 | No | Development and test only |

## What ETHR does about it

1. **A notice.** Publish the licence texts for what the browser bundle contains, and keep `api/resources/fonts/DEJAVU-LICENSE.txt` (the PDF font). A generated `THIRD-PARTY-NOTICES` file is the usual way; it is follow-up work, not done yet.
2. **No modification of LGPL libraries in place.** If `dompdf` must be patched, keep the patch separate and publishable.
3. **Check licences when adding a dependency.** There is no CI check today. A licence gate that refuses AGPL, GPL-only and unlicensed packages in production dependencies would make this page a control rather than a snapshot.
4. **ETHR's own metadata says proprietary.** `api/composer.json` declares `"license": "proprietary"` and `src/package.json` declares `"license": "UNLICENSED"`, matching `LICENSE`. (Until 2026-11-25 `composer.json` still said `MIT`, left over from the Laravel skeleton.)
