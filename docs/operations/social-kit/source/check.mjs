// Checks every word in the social kit before it is posted.
//
//   node check.mjs
//
// 1. Claims. The patterns are read from api/app/Support/MarketingClaims.php,
//    the list the marketing console refuses, so the kit and the site cannot
//    drift apart. A passage between `<!-- claims-check: off -->` and
//    `<!-- claims-check: on -->` is skipped: brand.md has to name what may
//    not be said.
// 2. House style: no em dash, no exclamation mark, no emoji.
// 3. Every X post in copy-bank.md fits in 280 characters.
//
// Exits 1 listing each problem, 0 when the kit is clean.

import { readFileSync, readdirSync } from "node:fs";
import { dirname, join, relative } from "node:path";
import { fileURLToPath } from "node:url";

const HERE = dirname(fileURLToPath(import.meta.url));
const KIT = join(HERE, "..");
const REPO = join(KIT, "..", "..", "..");
const CLAIMS = join(REPO, "api", "app", "Support", "MarketingClaims.php");

// '/pattern/flags' => 'reason',
const php = readFileSync(CLAIMS, "utf8");
const rules = [...php.matchAll(/'(\/(?:[^'\\]|\\.)+\/[a-z]*)'\s*=>\s*'((?:[^'\\]|\\.)*)'/g)].map(
  ([, literal, reason]) => {
    const end = literal.lastIndexOf("/");
    return {
      re: new RegExp(literal.slice(1, end), literal.slice(end + 1)),
      reason: reason.replace(/\\'/g, "'"),
    };
  },
);
// The PHP list held 24 patterns when this was written. Far fewer means the
// parse broke, and a check that matches nothing reports a clean kit.
if (rules.length < 20) {
  console.error(`Read only ${rules.length} patterns from ${CLAIMS}; the parse is broken.`);
  process.exit(1);
}

const STYLE = [
  { re: /—/, reason: "em dash (house style)" },
  { re: /!(?!\[)(?!=)/, reason: "exclamation mark (house style)" },
  { re: /\p{Extended_Pictographic}/u, reason: "emoji (house style)" },
];

const files = [
  ...readdirSync(KIT)
    .filter((f) => f.endsWith(".md"))
    .map((f) => join(KIT, f)),
  join(HERE, "content.mjs"),
];

const problems = [];
for (const file of files) {
  let skipping = false;
  readFileSync(file, "utf8")
    .split("\n")
    .forEach((line, i) => {
      if (line.includes("claims-check: off")) skipping = true;
      if (line.includes("claims-check: on")) {
        skipping = false;
        return;
      }
      if (skipping) return;
      // Markdown image syntax and code fences are not copy.
      const text = line.replace(/!\[[^\]]*\]\([^)]*\)/g, "");
      for (const { re, reason } of [...rules, ...STYLE]) {
        const m = text.match(re);
        if (m) problems.push(`${relative(REPO, file)}:${i + 1}: "${m[0]}": ${reason}`);
      }
    });
}

// X posts: the quoted block under each **X** heading.
const bank = readFileSync(join(KIT, "copy-bank.md"), "utf8").split("\n");
let xPosts = 0;
bank.forEach((line, i) => {
  if (line.trim() !== "**X**") return;
  const body = [];
  for (let j = i + 2; j < bank.length && bank[j].startsWith(">"); j++) {
    body.push(bank[j].replace(/^>\s?/, ""));
  }
  xPosts++;
  const length = [...body.join("\n").trim()].length;
  if (length > 280) problems.push(`docs/operations/social-kit/copy-bank.md:${i + 1}: X post is ${length} characters, over 280`);
});

if (problems.length) {
  console.error(problems.join("\n"));
  console.error(`\n${problems.length} problem(s).`);
  process.exit(1);
}
console.log(
  `Clean: ${files.length} files against ${rules.length} refused claims and the house style; ${xPosts} X posts within 280 characters.`,
);
