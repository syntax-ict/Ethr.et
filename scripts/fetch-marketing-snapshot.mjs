#!/usr/bin/env node
/**
 * Regenerate the landing page's build-time content snapshot.
 *
 *   node scripts/fetch-marketing-snapshot.mjs [--api https://www.ethr.et]
 *
 * WHY THIS EXISTS. `/en` and `/am` are prerendered static routes that read
 * the landing page's sections from `GET /api/v1/marketing-content` on the
 * client. Without a seed value the emitted HTML would carry no headings, no
 * problems, no capabilities, so a crawler and a link preview would see an
 * empty page. `src/src/lib/marketing/content-snapshot.json` is that seed.
 *
 * WHY IT IS NOT PART OF `next build`. CI has no Laravel process; a build-time
 * fetch would make the frontend build depend on the backend being up. The
 * snapshot is committed and refreshed deliberately.
 *
 * WHEN TO RUN IT: before cutting a release, against production, so the
 * prerendered HTML carries whatever the platform operator last published:
 * `docs/deployment/PLESK-GO-LIVE.md`, "Every release after this".
 *
 * The schema below is the public contract (MarketingSectionResource and
 * MarketingItemResource). A field the API starts sending that is not here
 * fails the run; a field here the API stops sending is written as null.
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
const OUT = path.join(ROOT, "src/src/lib/marketing/content-snapshot.json");

const IMAGE = { fields: ["url", "srcset", "width", "height", "alt", "alt_am"] };
const ITEM = {
  fields: [
    "public_id",
    "slug",
    "icon",
    "title",
    "title_am",
    "body",
    "body_am",
    "detail",
    "detail_am",
    "link_path",
  ],
  nested: { image: IMAGE },
};
const SECTION = {
  fields: ["kind", "heading", "heading_am", "subheading", "subheading_am"],
  nested: { image: IMAGE, items: ITEM },
};

const fail = makeFail("fetch-marketing-snapshot");
const API = apiBase();
const url = `${API}/api/v1/marketing-content`;

const payload = await fetchJson(url, fail);
if (!payload || !payload.data || !Array.isArray(payload.data.sections)) {
  fail(
    `${url} did not return { data: { sections: [...] } } — got ${JSON.stringify(payload).slice(0, 120)}`,
  );
}
if (payload.data.sections.length === 0) {
  // An unseeded database, far more likely than a decision to publish nothing.
  fail(
    "the API returned zero sections. Refusing to write an empty landing page.",
  );
}
if (!payload.data.sections.some((s) => s.kind === "hero")) {
  fail(
    "the API returned no hero section. Refusing to write a landing page with no heading.",
  );
}

const sections = payload.data.sections.map((s, i) =>
  project(s, SECTION, fail, `sections[${i}]`),
);

if (
  writeIfChanged(
    OUT,
    url,
    { sections },
    "fetch-marketing-snapshot",
    `content (${sections.length} sections)`,
  )
) {
  console.log(
    `fetch-marketing-snapshot: wrote ${sections.length} sections to ${path.relative(ROOT, OUT)}`,
  );
  for (const s of sections) {
    console.log(
      `  ${s.kind.padEnd(14)} ${String(s.items.length).padStart(2)} rows  ${s.heading}`,
    );
  }
}
