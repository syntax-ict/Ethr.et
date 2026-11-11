#!/usr/bin/env node
/**
 * Turn a production run's JSON report into rows of the execution log
 * (`docs/production testing/10-TEMPLATES-AND-REFERENCE.md`, section 2).
 *
 *   node e2e-production/execution-log.mjs [test-results/production/results.json]
 *
 * Each test's title starts with its case ID, which is what makes this a
 * mapping and not a guess. A skipped test is "Not run" with its reason, never
 * "Pass": a missing session is not a passed check.
 */
import fs from "fs";

const file = process.argv[2] ?? "test-results/production/results.json";
const report = JSON.parse(fs.readFileSync(file, "utf8"));

const RESULT = {
  passed: "Pass",
  failed: "Fail",
  timedOut: "Fail",
  interrupted: "Blocked",
  skipped: "Not run",
};

const eat = (iso) =>
  new Date(iso).toLocaleString("sv-SE", {
    timeZone: "Africa/Addis_Ababa",
    dateStyle: "short",
    timeStyle: "short",
  });

const rows = [];

function walk(suite) {
  for (const spec of suite.specs ?? []) {
    for (const t of spec.tests ?? []) {
      const last = t.results?.at(-1);
      const id = spec.title.split(" ")[0];
      const persona = (spec.title.match(/\b(TA|HR|FIN|SUP|EMP|PA)\b/) ?? [
        "",
      ])[0];
      const status = last?.status ?? "skipped";
      const notes = [
        spec.title.slice(id.length + 1),
        ...new Set(
          (t.annotations ?? [])
            .concat(last?.annotations ?? [])
            .map((a) => `${a.type}: ${a.description ?? ""}`),
        ),
        status === "failed" || status === "timedOut"
          ? (last?.error?.message ?? "").split("\n")[0]
          : "",
      ]
        .filter(Boolean)
        .join("; ")
        .replaceAll("|", "\\|");
      rows.push(
        `| ${id} | ${RESULT[status] ?? status} | Playwright | ${last?.startTime ? eat(last.startTime) : ""} | ${persona} | en | ${notes} |`,
      );
    }
  }
  for (const child of suite.suites ?? []) walk(child);
}

for (const suite of report.suites ?? []) walk(suite);

console.log(
  "| Case ID | Result | Tester | Time (EAT) | Persona | Language | Notes / defect link |",
);
console.log("|---|---|---|---|---|---|---|");
for (const row of rows) console.log(row);
