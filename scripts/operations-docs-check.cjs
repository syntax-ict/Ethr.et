#!/usr/bin/env node
/**
 * Operations runbook "At a glance" checker.
 *
 * Every top-level document in docs/operations/ (every .md file except
 * README.md; sub-folders are out of scope) must open with a standard block:
 * an H1 on line 1, then, within the first 20 lines, this table.
 *
 *   | At a glance | |
 *   |---|---|
 *   | **Purpose** | ... |
 *   | **Open it when** | ... |
 *   | **For** | ... |
 *   | **Organisation types** | ... |
 *   | **Source of truth** | ... |
 *
 * The five rows must be present, in that order, each with a non-empty value.
 * The Organisation types value must begin with one of "Same for every kind",
 * "Varies" or "Not about an organisation". The first two must link to
 * ORGANISATION-TYPES.md (except in that file itself), so a reader who needs the
 * detail is one click from it.
 *
 * Folder rules: README.md must link to every other top-level document, so a
 * new runbook cannot be left out of the index; ORGANISATION-TYPES.md must
 * exist; and fewer than 20 documents examined is a failure, so a wrong
 * directory or an empty glob cannot pass by checking nothing.
 *
 * This exists because the operations folder never said whether a runbook
 * differs by organisation type. A reader had to open each one and guess. The
 * block makes every document answer that question in the same place, and this
 * script makes the answer mandatory: a standard nobody enforces rots, and the
 * next document written without the block would quietly break the promise.
 *
 *   node scripts/operations-docs-check.cjs
 *   node scripts/operations-docs-check.cjs --dir <path>   (fixtures, tests)
 *
 * Exit codes: 0 all documents conform, 1 at least one failure. Failures print
 * one per line as `docs/operations/FILE.md: what is wrong`, then a count.
 *
 * ## Why .cjs and not .js
 *
 * Same reason as docs-link-check.cjs. This file uses require(), the repository
 * root has no package.json, and Node resolves module type by walking UP from
 * the script. A package.json with "type": "module" anywhere above the
 * repository makes Node parse a .js file as ESM and die on the first require()
 * with "require is not defined in ES module scope". The .cjs extension pins
 * CommonJS regardless of what sits above the repository. Node built-ins only,
 * so the CI docs job needs no install step.
 */

const fs = require("fs");
const path = require("path");

const ROOT = path.resolve(__dirname, "..");
const MIN_DOCUMENTS = 20;
const HEAD_LINES = 20;
const TYPES_DOC = "ORGANISATION-TYPES.md";
const LABELS = [
  "Purpose",
  "Open it when",
  "For",
  "Organisation types",
  "Source of truth",
];
const TYPE_PHRASES = ["Same for every kind", "Varies", "Not about an organisation"];
const NEEDS_LINK = ["Same for every kind", "Varies"];

function parseArgs(argv) {
  const i = argv.indexOf("--dir");
  if (i === -1) return path.join(ROOT, "docs", "operations");
  if (!argv[i + 1]) {
    console.error("operations-docs-check: --dir needs a path");
    process.exit(1);
  }
  return path.resolve(argv[i + 1]);
}

/** Cells of a table row, outer pipes dropped. The value is everything after the label cell. */
function rowCells(line) {
  const t = line.trim();
  if (!t.startsWith("|")) return null;
  const inner = t.replace(/^\|/, "").replace(/\|$/, "");
  const at = inner.indexOf("|");
  if (at === -1) return [inner.trim(), ""];
  return [inner.slice(0, at).trim(), inner.slice(at + 1).trim()];
}

function linksTo(text, name) {
  const re = /\[[^\]]*\]\(\s*(?:\.\/)?([^)\s#]+)(?:#[^)]*)?\s*\)/g;
  for (const m of text.matchAll(re)) if (m[1] === name) return true;
  return false;
}

/** @returns {string[]} what is wrong with one document, empty when it conforms */
function checkDocument(name, body) {
  const problems = [];
  const lines = body.split(/\r?\n/);

  if (!/^#\s+\S/.test(lines[0] || "")) problems.push("line 1 is not an H1");

  const head = lines.slice(0, HEAD_LINES);
  const start = head.findIndex((l) => {
    const c = rowCells(l);
    return c && c[0] === "At a glance" && c[1] === "";
  });
  if (start === -1) {
    problems.push(`no "| At a glance | |" header row in the first ${HEAD_LINES} lines`);
    return problems;
  }

  if (!/^\|\s*:?-{3,}:?\s*\|\s*:?-{3,}:?\s*\|$/.test((lines[start + 1] || "").trim())) {
    problems.push("the At a glance header is not followed by a |---|---| separator row");
  }

  const values = {};
  LABELS.forEach((label, n) => {
    const cells = rowCells(lines[start + 2 + n] || "");
    const want = `**${label}**`;
    if (!cells || cells[0] !== want) {
      problems.push(`row ${n + 1} of the block must be ${want}, in that order`);
    } else if (cells[1] === "") {
      problems.push(`${want} has an empty value`);
    } else {
      values[label] = cells[1];
    }
  });

  const types = values["Organisation types"];
  if (types !== undefined) {
    const phrase = TYPE_PHRASES.find((p) => types.startsWith(p));
    if (!phrase) {
      problems.push(
        `**Organisation types** must begin with one of: ${TYPE_PHRASES.join(", ")}`,
      );
    } else if (NEEDS_LINK.includes(phrase) && name !== TYPES_DOC && !linksTo(types, TYPES_DOC)) {
      problems.push(`**Organisation types** (${phrase}) must link to ${TYPES_DOC}`);
    }
  }
  return problems;
}

const dir = parseArgs(process.argv.slice(2));
const failures = [];
const fail = (file, msg) => failures.push(`docs/operations/${file}: ${msg}`);

if (!fs.existsSync(dir) || !fs.statSync(dir).isDirectory()) {
  console.error(`operations-docs-check: ${dir} is not a directory`);
  process.exit(1);
}

const documents = fs
  .readdirSync(dir, { withFileTypes: true })
  .filter((e) => e.isFile() && e.name.endsWith(".md") && e.name !== "README.md")
  .map((e) => e.name)
  .sort();

for (const name of documents) {
  const body = fs.readFileSync(path.join(dir, name), "utf8");
  for (const p of checkDocument(name, body)) fail(name, p);
}

if (!documents.includes(TYPES_DOC)) fail(TYPES_DOC, "file does not exist");

const readme = path.join(dir, "README.md");
if (!fs.existsSync(readme)) {
  fail("README.md", "file does not exist");
} else {
  const index = fs.readFileSync(readme, "utf8");
  for (const name of documents) {
    if (!linksTo(index, name)) fail("README.md", `does not link to ${name}`);
  }
}

if (documents.length < MIN_DOCUMENTS) {
  fail(
    "(folder)",
    `only ${documents.length} documents examined, expected at least ${MIN_DOCUMENTS}; wrong directory?`,
  );
}

if (failures.length > 0) {
  for (const f of failures) console.error(f);
  console.error(`\n\x1b[31m${failures.length} operations documentation failure(s)\x1b[0m`);
  process.exit(1);
}

console.log(
  `operations-docs-check: ${documents.length} documents carry the At a glance block, README indexes all`,
);
