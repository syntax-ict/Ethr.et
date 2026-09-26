/**
 * Which deployment this build is for, decided once.
 *
 * ETHR has two live targets and they need different builds:
 *
 * | Target | `output` | Runtime |
 * |---|---|---|
 * | Ethio Telecom Bronze under Plesk — **the production target** | `export` | none; Apache serves files |
 * | The VPS / Docker stack — **the rollback path** | `standalone` | Node runs `server.js` |
 *
 * **Default is `standalone`, deliberately.** `docs/deployment/VPS-DECOMMISSION.md`
 * is `NOT TRIGGERED` and `docs/VPS_DEPLOYMENT.md` is the rollback path until a
 * cutover is verified, so flipping `output` unconditionally would delete the
 * rollback before there is anything to roll back *to* — `next build` under
 * `export` emits no `server.js`, which is exactly what
 * `docker/frontend/Dockerfile` runs. The migration plan's own sequencing rule is
 * that nothing irreversible happens before the cutover is verified.
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
