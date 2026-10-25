/**
 * Shared by the build-time snapshot scripts (`fetch-plans-snapshot.mjs`,
 * `fetch-marketing-snapshot.mjs`).
 *
 * The public site is a static export, so a prerendered page carries whatever
 * these snapshots hold and the live API replaces it on the client. Each script
 * fetches one public endpoint, projects a fixed field schema (so a column
 * added to a table cannot reach a committed file by accident, and a field the
 * API publishes that the schema does not know fails loudly rather than being
 * dropped), and writes only when the content differs.
 */

import fs from "node:fs";

/** The API base from `--api <url>`, `API_URL`, or the local default. */
export function apiBase(argv = process.argv, env = process.env) {
  const apiArg = argv.indexOf("--api");
  return (
    apiArg !== -1 ? argv[apiArg + 1] : env.API_URL || "http://localhost:8000"
  ).replace(/\/$/, "");
}

export function makeFail(label) {
  return function fail(message) {
    console.error(`${label}: ${message}`);
    process.exit(1);
  };
}

export async function fetchJson(url, fail) {
  try {
    const res = await fetch(url, { headers: { Accept: "application/json" } });
    if (!res.ok) fail(`${url} returned ${res.status} ${res.statusText}`);
    return await res.json();
  } catch (err) {
    fail(
      `could not reach ${url} — ${err.message}\n` +
        `  Start the API (scripts/local-production/up.sh, then --api http://localhost:8081)\n` +
        `  or pass --api <url>.\n` +
        `  The committed snapshot is left untouched.`,
    );
  }
}

/**
 * Copy exactly the fields a schema names, recursively.
 *
 * A schema is `{ fields: [...], nested: { key: schema } }`. A nested key may
 * hold an object, an array of objects, or null. An unknown key on the record
 * fails: the API is the authority on what is public, and a field it starts
 * sending that the schema does not know would otherwise be quietly omitted,
 * so the prerendered page would disagree with the hydrated one.
 */
export function project(record, schema, fail, path = "record") {
  const known = new Set([
    ...schema.fields,
    ...Object.keys(schema.nested ?? {}),
  ]);
  const unknown = Object.keys(record).filter((k) => !known.has(k));
  if (unknown.length > 0) {
    fail(
      `the API returned fields this script does not copy at ${path}: ${unknown.join(", ")}.\n` +
        `  Add them to the schema (and to the TypeScript type), or they will be\n` +
        `  missing from the prerendered page.`,
    );
  }
  const out = {};
  for (const f of schema.fields) out[f] = record[f] ?? null;
  for (const [key, sub] of Object.entries(schema.nested ?? {})) {
    const value = record[key];
    if (value === null || value === undefined) {
      out[key] = Array.isArray(value) ? [] : null;
    } else if (Array.isArray(value)) {
      out[key] = value.map((row, i) =>
        project(row, sub, fail, `${path}.${key}[${i}]`),
      );
    } else {
      out[key] = project(value, sub, fail, `${path}.${key}`);
    }
  }
  return out;
}

/**
 * Write `{ generated_at, source, data }` to `out` when `data` differs from
 * the committed file's; say so and leave the tree clean when it does not.
 * Returns true when a file was written.
 */
export function writeIfChanged(out, source, data, label, describe) {
  const previous = fs.existsSync(out)
    ? JSON.parse(fs.readFileSync(out, "utf8"))
    : null;
  if (previous && JSON.stringify(previous.data) === JSON.stringify(data)) {
    console.log(`${label}: ${describe} unchanged — not rewriting.`);
    return false;
  }
  fs.writeFileSync(
    out,
    JSON.stringify(
      { generated_at: new Date().toISOString(), source, data },
      null,
      2,
    ) + "\n",
  );
  return true;
}
