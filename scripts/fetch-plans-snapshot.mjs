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
 * WHEN TO RUN IT: after changing plan prices, limits or features — and, once
 * plan editing has an admin UI, as part of the deploy step.
 *
 * It writes only when the catalog actually differs, so a no-op run leaves the
 * working tree clean and does not produce an empty commit.
 */

import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const OUT = path.join(ROOT, "src/src/lib/marketing/plans-snapshot.json");

const apiArg = process.argv.indexOf("--api");
const API = (
  apiArg !== -1
    ? process.argv[apiArg + 1]
    : process.env.API_URL || "http://localhost:8000"
).replace(/\/$/, "");

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
 * moment later. The assertion below turns that into a failure instead.
 */
const FIELDS = [
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
];

function fail(message) {
  console.error(`fetch-plans-snapshot: ${message}`);
  process.exit(1);
}

const url = `${API}/api/v1/plans`;
let payload;

try {
  const res = await fetch(url, { headers: { Accept: "application/json" } });
  if (!res.ok) fail(`${url} returned ${res.status} ${res.statusText}`);
  payload = await res.json();
} catch (err) {
  fail(
    `could not reach ${url} — ${err.message}\n` +
      `  Start the API (docker compose up -d) or pass --api <url>.\n` +
      `  The committed snapshot is left untouched.`,
  );
}

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
  .map((plan) => {
    // The API is the authority on what is public. If it starts sending a field
    // this list does not know about, the snapshot would quietly omit it and the
    // prerendered page would disagree with the hydrated one — so say so.
    const unknown = Object.keys(plan).filter((k) => !FIELDS.includes(k));
    if (unknown.length > 0) {
      fail(
        `the API returned fields this script does not copy: ${unknown.join(", ")}.\n` +
          `  Add them to FIELDS (and to the Plan type in features/billing/api.ts),\n` +
          `  or they will be missing from the prerendered pricing page.`,
      );
    }
    return Object.fromEntries(FIELDS.map((f) => [f, plan[f] ?? null]));
  })
  .sort((a, b) => a.sort_order - b.sort_order);

const previous = fs.existsSync(OUT)
  ? JSON.parse(fs.readFileSync(OUT, "utf8"))
  : null;

if (previous && JSON.stringify(previous.data) === JSON.stringify(plans)) {
  console.log(
    `fetch-plans-snapshot: catalog unchanged (${plans.length} plans) — not rewriting.`,
  );
  process.exit(0);
}

fs.writeFileSync(
  OUT,
  JSON.stringify(
    { generated_at: new Date().toISOString(), source: url, data: plans },
    null,
    2,
  ) + "\n",
);

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
