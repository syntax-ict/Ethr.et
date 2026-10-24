#!/usr/bin/env node
/**
 * Regenerate the public pricing page's build-time plan snapshot.
 *
 *   node scripts/fetch-plans-snapshot.mjs [--api http://localhost:8000]
 *
 * WHY THIS EXISTS. `/pricing` is a prerendered static route that reads the plan
 * catalog from `GET /api/v1/plans` on the client. Without a seed value the
 * emitted HTML would carry no prices, so crawlers and link previews would see
 * an empty pricing table — the exact defect class this branch is fixing.
 * `src/src/lib/marketing/plans-snapshot.json` is that seed; this regenerates it.
 *
 * WHY IT IS NOT PART OF `next build`. CI has no Laravel process. Wiring a fetch
 * into the build would make the frontend build depend on the backend being up,
 * which it has never been in CI, and would turn a backend outage into a failed
 * frontend deploy. The snapshot is committed instead, and refreshed
 * deliberately.
 *
 * WHEN TO RUN IT: after changing plan prices, limits or features — and, now
 * that plan editing has an admin UI, before every release, against production
 * (`docs/deployment/PLESK-GO-LIVE.md`, "Every release after this").
 *
 * It writes only when the catalog actually differs, so a no-op run leaves the
 * working tree clean and does not produce an empty commit. The fetch,
 * projection and write-on-change live in `lib/snapshot-writer.mjs`, shared
 * with the landing page's snapshot.
 */

import path from "node:path";
import { fileURLToPath } from "node:url";
import {
  apiBase,
  fetchJson,
  makeFail,
  project,
  writeIfChanged,
} from "./lib/snapshot-writer.mjs";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const OUT = path.join(ROOT, "src/src/lib/marketing/plans-snapshot.json");

/**
 * The fields `PlanResource` publishes. Anything else is not public.
 *
 * This list must stay in step with that resource. It is a projection rather
 * than a passthrough so a column added to `plans` cannot reach a committed file
 * by accident — but the cost is that a column added *deliberately* is silently
 * dropped here until someone remembers. That already happened once: the
 * catalog gained description, currency, marketing_features and is_popular, and
 * the first regenerated snapshot carried none of them, so the prerendered HTML
 * would have shown bare plan cards while the client fetch filled them in a
 * moment later. The projector refuses an unknown field instead.
 */
const PLAN = {
  fields: [
    "public_id",
    "name",
    "slug",
    "description",
    "description_am",
    "price_cents",
    "currency",
    "billing_interval",
    "max_employees",
    "max_branches",
    "max_devices",
    "features",
    "marketing_features",
    "marketing_features_am",
    "is_popular",
    "sort_order",
  ],
};

const fail = makeFail("fetch-plans-snapshot");
const API = apiBase();
const url = `${API}/api/v1/plans`;

const payload = await fetchJson(url, fail);
if (!payload || !Array.isArray(payload.data)) {
  fail(
    `${url} did not return { data: [...] } — got ${JSON.stringify(payload).slice(0, 120)}`,
  );
}
if (payload.data.length === 0) {
  // An empty catalog would blank the pricing page. Far more likely an unseeded
  // database than a real business decision to sell nothing.
  fail("the API returned zero plans. Refusing to write an empty pricing page.");
}

const plans = payload.data
  .map((plan, i) => project(plan, PLAN, fail, `plans[${i}]`))
  .sort((a, b) => a.sort_order - b.sort_order);

if (
  writeIfChanged(
    OUT,
    url,
    plans,
    "fetch-plans-snapshot",
    `catalog (${plans.length} plans)`,
  )
) {
  console.log(
    `fetch-plans-snapshot: wrote ${plans.length} plans to ${path.relative(ROOT, OUT)}`,
  );
  for (const p of plans) {
    const price =
      p.price_cents === 0
        ? "free"
        : `${(p.price_cents / 100).toLocaleString()} ETB`;
    console.log(
      `  ${p.slug.padEnd(14)} ${price.padStart(12)}  ${p.max_employees} employees`,
    );
  }
}
