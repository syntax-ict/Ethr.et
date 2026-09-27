/**
 * Which deployment this build is for, decided once.
 *
 * ETHR has two live targets and they need different builds:
 *
 * | Target | `output` | Runtime |
 * |---|---|---|
 * | Ethio Telecom Bronze under Plesk — **the production target** | `export` | none; Apache serves files |
 * | Local development and CI | `standalone` | Node runs `server.js` |
 *
 * **Default is `standalone`, and it is now the only Node target left.** It used
 * to be the VPS rollback path; the VPS was decommissioned on 2026-09-26 at the
 * owner's direction, before a verified cutover, so there is no longer anything to
 * roll back *to*.
 *
 * The switch is kept because the two builds are genuinely different — `export`
 * emits no `server.js`, which is what `docker/frontend/Dockerfile` runs — and
 * because local development and CI still build `standalone`. Changing this
 * default now would change what every developer and every gate builds.
 *
 * Opt in with `ETHR_TARGET=shared-hosting`. That also makes the static-export
 * build runnable in CI, which is what turns "verified once by hand" into
 * something a gate can check.
 *
 * Read by `next.config.ts` (via a relative import — this module deliberately has
 * no imports of its own, so it is safe to pull into the config loader) and by
 * `app/(auth)/layout.tsx`. One definition rather than two copies of the same
 * string comparison, for the reason `App\Support\EmployeeRelations::MAP` exists:
 * two readers that disagree fail silently.
 */
export const SHARED_HOSTING_TARGET = "shared-hosting";

/** True when this build targets Bronze shared hosting, i.e. `output: "export"`. */
export const IS_STATIC_EXPORT =
  process.env.ETHR_TARGET === SHARED_HOSTING_TARGET;
