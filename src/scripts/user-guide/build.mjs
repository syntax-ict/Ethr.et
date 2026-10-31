#!/usr/bin/env node
/**
 * Builds the illustrated Amharic user guides as PDF.
 *
 *   node scripts/user-guide/build.mjs            all guides
 *   node scripts/user-guide/build.mjs employee   one guide
 *
 * Source: docs/user-guides/am/<guide>.md, in a small Markdown dialect
 * (docs/user-guides/am/README.md describes it). Screenshots come from
 * capture.mjs and live beside the source in shots/, each PNG with a JSON of
 * the numbered markers drawn over it; the source names them by their real
 * relative path, so GitHub shows them and the docs link check sees them.
 * Output: docs/user-guides/am/pdf/.
 *
 * Chromium prints the PDF, because it shapes Ethiopic correctly and keeps
 * internal links. The contents page gets its page numbers from a first
 * print: the script reads where each heading's link landed and prints again.
 */
import { readFile, writeFile, mkdir, readdir } from "node:fs/promises";
import { existsSync } from "node:fs";
import path from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";
import zlib from "node:zlib";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { QRCodeSVG } from "qrcode.react";
import { chromium } from "@playwright/test";

const here = path.dirname(fileURLToPath(import.meta.url));
const repo = path.resolve(here, "../../..");
const guideDir = path.join(repo, "docs/user-guides/am");
const shotDir = path.join(guideDir, "shots");
const outDir = path.join(guideDir, "pdf");
const fontDir = path.join(repo, "api/resources/fonts");

const GUIDES = {
  employee: { file: "employee.md", pdf: "ethr-employee-guide-am.pdf" },
  manager: { file: "manager.md", pdf: "ethr-manager-guide-am.pdf" },
  admin: { file: "admin.md", pdf: "ethr-admin-guide-am.pdf" },
};

// ---------------------------------------------------------------- markdown

const escape = (s) =>
  s
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");

function inline(text) {
  const codes = [];
  let s = escape(text).replace(/`([^`]+)`/g, (_, c) => {
    codes.push(c);
    return `\u0000${codes.length - 1}\u0000`;
  });
  s = s
    .replace(/\*\*([^*]+)\*\*/g, "<strong>$1</strong>")
    .replace(/\*([^*]+)\*/g, "<em>$1</em>")
    .replace(/\[\[key:([^\]]+)\]\]/g, "<kbd>$1</kbd>")
    .replace(/\(\((\d+)\)\)/g, '<span class="pin">$1</span>')
    .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2">$1</a>');
  return s.replace(
    /\u0000(\d+)\u0000/g,
    (_, i) => `<code>${codes[Number(i)]}</code>`,
  );
}

function slug(text, used) {
  let base =
    "s-" +
    [...text]
      .map((c) => c.codePointAt(0).toString(36))
      .join("")
      .slice(0, 24);
  let id = base;
  for (let n = 2; used.has(id); n++) id = `${base}-${n}`;
  used.add(id);
  return id;
}

function frontMatter(src) {
  const meta = {};
  const m = src.match(/^---\r?\n([\s\S]*?)\r?\n---\r?\n/);
  if (!m) return { meta, body: src };
  for (const line of m[1].split(/\r?\n/)) {
    const i = line.indexOf(":");
    if (i > 0) meta[line.slice(0, i).trim()] = line.slice(i + 1).trim();
  }
  return { meta, body: src.slice(m[0].length) };
}

const CALLOUTS = {
  tip: { label: "ጠቃሚ ምክር", icon: "tip" },
  note: { label: "ማስታወሻ", icon: "note" },
  warning: { label: "ጥንቃቄ", icon: "warning" },
  why: { label: "ለምን", icon: "note" },
};

const ICONS = {
  tip: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 18h6M10 22h4M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.3 1 2.1V17h6v-.2c0-.8.4-1.6 1-2.1A7 7 0 0 0 12 2z"/></svg>',
  note: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>',
  warning:
    '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0zM12 9v4M12 17h.01"/></svg>',
};

async function loadShot(id) {
  const png = path.join(shotDir, `${id}.png`);
  const json = path.join(shotDir, `${id}.json`);
  if (!existsSync(png)) return null;
  const meta = existsSync(json)
    ? JSON.parse(await readFile(json, "utf8"))
    : { markers: [] };
  return { url: pathToFileURL(png).href, ...meta };
}

function qr(url, caption) {
  const svg = renderToStaticMarkup(
    createElement(QRCodeSVG, {
      value: url,
      size: 112,
      level: "M",
      marginSize: 0,
      fgColor: "#0A2E4A",
    }),
  );
  return `<div class="qr">${svg}<div><div class="qr-caption">${inline(caption)}</div><div class="qr-url">${escape(url)}</div></div></div>`;
}

/** Turns the guide source into chapter HTML plus the contents entries. */
async function render(body) {
  const lines = body.split(/\r?\n/);
  const out = [];
  const toc = [];
  const missing = [];
  const ids = new Set();
  let chapter = 0;
  let section = 0;
  let figure = 0;
  let i = 0;

  const isBlockStart = (l) =>
    /^(#{1,3} |[-*] |\d+\. |> |\||!\[|\[\[qr:|---\s*$|:::)/.test(l) ||
    l.trim() === "";

  while (i < lines.length) {
    const line = lines[i];

    if (line.trim() === "" || /^---\s*$/.test(line)) {
      i++;
      continue;
    }

    let m;
    if ((m = line.match(/^(#{1,3}) (.+)$/))) {
      const level = m[1].length;
      const text = m[2].trim();
      const id = slug(text, ids);
      if (level === 1) {
        chapter++;
        section = 0;
        figure = 0;
        if (chapter > 1) out.push("</section>");
        out.push(
          `<section class="chapter"><div class="chapter-head"><div class="chapter-no">ምዕራፍ ${chapter}</div>`,
        );
        out.push(`<h1 id="${id}">${inline(text)}</h1></div>`);
        toc.push({ level, text, id, number: `${chapter}` });
      } else if (level === 2) {
        section++;
        const number = `${chapter}.${section}`;
        out.push(
          `<h2 id="${id}"><span class="num">${number}</span>${inline(text)}</h2>`,
        );
        toc.push({ level, text, id, number });
      } else {
        out.push(`<h3 id="${id}">${inline(text)}</h3>`);
      }
      i++;
      continue;
    }

    if (
      (m = line.match(/^!\[([^\]]*)\]\(shots\/([\w-]+)\.png\)(\{(\w+)\})?\s*$/))
    ) {
      const [, caption, id, , variant] = m;
      const shot = await loadShot(id);
      figure++;
      const label = `ምስል ${chapter}.${figure}`;
      if (!shot) {
        missing.push(id);
        out.push(
          `<figure class="shot missing"><div class="frame">ምስል አልተገኘም፦ ${escape(id)}</div><figcaption><b>${label}፦</b> ${inline(caption)}</figcaption></figure>`,
        );
      } else {
        const kind =
          variant ?? (shot.device === "mobile" ? "mobile" : "desktop");
        const markers = (shot.markers ?? [])
          .map((mk) => {
            const left = ((mk.x + mk.w / 2) / shot.width) * 100;
            const top = ((mk.y + mk.h / 2) / shot.height) * 100;
            const ring = `left:${(mk.x / shot.width) * 100}%;top:${(mk.y / shot.height) * 100}%;width:${(mk.w / shot.width) * 100}%;height:${(mk.h / shot.height) * 100}%`;
            // The number sits just left of the ring, centred on it, so it never
            // covers the label it points at; inside the ring at the left edge.
            // Icons packed in a row take theirs below instead.
            const centre = mk.pin === "center";
            const below = mk.pin === "below";
            const above = mk.pin === "above";
            const atLeft = (mk.x / shot.width) * 100 < 4;
            const pinLeft =
              centre || below || above ? left : (mk.x / shot.width) * 100;
            const pinTop = centre
              ? top
              : below
                ? ((mk.y + mk.h) / shot.height) * 100
                : above
                  ? (mk.y / shot.height) * 100
                  : ((mk.y + mk.h / 2) / shot.height) * 100;
            const cls = centre
              ? "marker"
              : below
                ? "marker below"
                : above
                  ? "marker above"
                  : atLeft
                    ? "marker inside"
                    : "marker outside";
            return (
              (mk.ring === false
                ? ""
                : `<span class="ring" style="${ring}"></span>`) +
              `<span class="${cls}" style="left:${pinLeft}%;top:${pinTop}%">${mk.n}</span>`
            );
          })
          .join("");
        out.push(
          `<figure class="shot ${kind}"><div class="frame"><img src="${shot.url}" alt="">${markers}</div><figcaption><b>${label}፦</b> ${inline(caption)}</figcaption></figure>`,
        );
      }
      i++;
      continue;
    }

    if ((m = line.match(/^\[\[qr:([^|\]]+)\|([^\]]+)\]\]\s*$/))) {
      out.push(qr(m[1].trim(), m[2].trim()));
      i++;
      continue;
    }

    if ((m = line.match(/^:::\s*(\w+)\s*(.*)$/))) {
      const kind = m[1];
      const inner = [];
      i++;
      // Blocks nest: a card inside two columns closes before the columns do.
      for (let depth = 1; i < lines.length; i++) {
        if (/^:::\s*\w/.test(lines[i])) depth++;
        else if (/^:::\s*$/.test(lines[i]) && --depth === 0) break;
        inner.push(lines[i]);
      }
      i++;
      const html = (await render(inner.join("\n"))).html;
      if (kind === "card")
        out.push(
          `<div class="card"><div class="card-title">${inline(m[2])}</div>${html}</div>`,
        );
      else if (kind === "cols") out.push(`<div class="cols">${html}</div>`);
      else out.push(`<div class="${escape(kind)}">${html}</div>`);
      continue;
    }

    if (line.startsWith(">")) {
      const block = [];
      while (i < lines.length && lines[i].startsWith(">"))
        block.push(lines[i++].replace(/^>\s?/, ""));
      const head = block[0].match(/^\[!(\w+)\]\s*(.*)$/);
      const kind = head && CALLOUTS[head[1]] ? head[1] : "note";
      const title = head
        ? head[2] || CALLOUTS[kind].label
        : CALLOUTS.note.label;
      const text = (head ? block.slice(1) : block).join("\n");
      const html = (await render(text)).html;
      out.push(
        `<aside class="callout ${kind}"><div class="callout-icon">${ICONS[CALLOUTS[kind].icon]}</div><div><div class="callout-title">${inline(title)}</div>${html}</div></aside>`,
      );
      continue;
    }

    if (line.startsWith("|")) {
      const rows = [];
      while (i < lines.length && lines[i].startsWith("|"))
        rows.push(lines[i++]);
      const cells = (r) =>
        r
          .replace(/^\||\|\s*$/g, "")
          .split("|")
          .map((c) => c.trim());
      const head = cells(rows[0]);
      const bodyRows = rows.slice(2).map(cells);
      const hasHead = head.some((c) => c !== "");
      out.push(
        `<table>${hasHead ? `<thead><tr>${head.map((c) => `<th>${inline(c)}</th>`).join("")}</tr></thead>` : ""}<tbody>${bodyRows
          .map(
            (r) => `<tr>${r.map((c) => `<td>${inline(c)}</td>`).join("")}</tr>`,
          )
          .join("")}</tbody></table>`,
      );
      continue;
    }

    if (/^([-*]|\d+\.) /.test(line)) {
      const ordered = /^\d+\. /.test(line);
      const items = [];
      while (
        i < lines.length &&
        (/^([-*]|\d+\.) /.test(lines[i]) || /^ {2,}\S/.test(lines[i]))
      ) {
        if (/^ {2,}([-*]) /.test(lines[i])) {
          const last = items[items.length - 1];
          last.sub.push(lines[i].replace(/^ +[-*] /, ""));
        } else if (/^ {2,}\S/.test(lines[i])) {
          items[items.length - 1].text += " " + lines[i].trim();
        } else {
          items.push({ text: lines[i].replace(/^([-*]|\d+\.) /, ""), sub: [] });
        }
        i++;
      }
      const tag = ordered ? "ol" : "ul";
      out.push(
        `<${tag}${ordered ? ' class="steps"' : ""}>${items
          .map(
            (it) =>
              `<li>${inline(it.text)}${it.sub.length ? `<ul>${it.sub.map((s) => `<li>${inline(s)}</li>`).join("")}</ul>` : ""}</li>`,
          )
          .join("")}</${tag}>`,
      );
      continue;
    }

    const para = [];
    while (i < lines.length && !isBlockStart(lines[i])) para.push(lines[i++]);
    if (para.length === 0) para.push(lines[i++]);
    out.push(`<p>${inline(para.join(" "))}</p>`);
  }

  if (chapter > 0) out.push("</section>");
  return { html: out.join("\n"), toc, missing };
}

// ---------------------------------------------------------------- page

const TIBEB = `<svg class="tibeb" viewBox="0 0 48 16" preserveAspectRatio="none" aria-hidden="true"><defs><pattern id="tb" width="16" height="16" patternUnits="userSpaceOnUse"><path d="M8 1 15 8 8 15 1 8z" fill="none" stroke="#E8A838" stroke-width="1.4"/><path d="M8 5 11 8 8 11 5 8z" fill="#E8A838"/><path d="M0 0h2v2H0zM14 14h2v2h-2zM14 0h2v2h-2zM0 14h2v2H0z" fill="#E8A838"/></pattern></defs><rect width="48" height="16" fill="url(#tb)"/></svg>`;

function fontFace() {
  const url = (f) => pathToFileURL(path.join(fontDir, f)).href;
  return `
@font-face { font-family: "Noto Sans Ethiopic"; src: url("${url("NotoSansEthiopic-Regular.ttf")}"); font-weight: 400; }
@font-face { font-family: "Noto Sans Ethiopic"; src: url("${url("NotoSansEthiopic-Bold.ttf")}"); font-weight: 700; }`;
}

function contents(toc, pages) {
  return toc
    .filter((t) => t.level <= 2)
    .map(
      (t) =>
        `<a class="toc-row l${t.level}" href="#${t.id}"><span class="toc-num">${t.number}</span><span class="toc-text">${inline(t.text)}</span><span class="toc-dots"></span><span class="toc-page">${pages?.[t.id] ?? "00"}</span></a>`,
    )
    .join("\n");
}

function page({ meta, html, toc, pages, css }) {
  return `<!doctype html><html lang="am"><head><meta charset="utf-8"><title>${escape(meta.title)}</title>
<style>${fontFace()}\n${css}
@page { @bottom-left { content: "${escape(meta.footer ?? meta.title)}"; } }
</style></head><body>
<section class="cover">
  <div class="cover-top">
    <div class="logo"><span>E</span></div>
    <div class="brand">ETHR<small>የሰው ሀብት አስተዳደር</small></div>
  </div>
  <div class="cover-main">
    <div class="cover-kicker">${inline(meta.kicker ?? "የተጠቃሚ መመሪያ")}</div>
    <h1 class="cover-title">${inline(meta.title)}</h1>
    <p class="cover-sub">${inline(meta.subtitle ?? "")}</p>
    <p class="cover-en">${escape(meta.english ?? "")}</p>
  </div>
  <div class="cover-band">${TIBEB}</div>
  <div class="cover-foot">
    <div><div class="cover-label">ለማን</div><div>${inline(meta.audience ?? "")}</div></div>
    <div><div class="cover-label">እትም</div><div>${inline(meta.edition ?? "")}</div></div>
    ${qr("https://ethr.et/login", "ETHRን ለመክፈት ይቃኙ")}
  </div>
</section>
<section class="toc">
  <h1 class="toc-title">ማውጫ</h1>
  ${contents(toc, pages)}
</section>
<main>
${html}
</main>
<section class="back">
  <div class="logo small"><span>E</span></div>
  <p>${inline(meta.back ?? "ETHR · ethr.et")}</p>
</section>
</body></html>`;
}

// ---------------------------------------------------------------- pdf links

/**
 * Maps each internal link target to the page it lands on, by reading the
 * PDF Chromium wrote: its page tree gives page order, and every internal
 * link annotation or named destination points at a page object.
 */
function linkTargets(buffer) {
  const text = buffer.toString("latin1");
  const objects = new Map();
  for (const m of text.matchAll(/(\d+) 0 obj\s*([\s\S]*?)endobj/g))
    objects.set(Number(m[1]), m[2]);

  // Skia may pack objects into object streams; unpack them too.
  for (const body of [...objects.values()]) {
    if (!/\/Type\s*\/ObjStm/.test(body)) continue;
    const n = Number(body.match(/\/N\s+(\d+)/)[1]);
    const first = Number(body.match(/\/First\s+(\d+)/)[1]);
    const raw = body.match(/stream\r?\n([\s\S]*)\r?\nendstream/);
    if (!raw) continue;
    let data;
    try {
      data = zlib.inflateSync(Buffer.from(raw[1], "latin1")).toString("latin1");
    } catch {
      continue;
    }
    const header = data.slice(0, first).trim().split(/\s+/).map(Number);
    for (let k = 0; k < n; k++) {
      const num = header[k * 2];
      const start = first + header[k * 2 + 1];
      const end = k + 1 < n ? first + header[k * 2 + 3] : data.length;
      objects.set(num, data.slice(start, end));
    }
  }

  const pageOrder = [];
  const walk = (num) => {
    const body = objects.get(num) ?? "";
    if (/\/Type\s*\/Pages/.test(body)) {
      const kids = body.match(/\/Kids\s*\[([^\]]*)\]/)[1];
      for (const k of kids.matchAll(/(\d+) 0 R/g)) walk(Number(k[1]));
    } else pageOrder.push(num);
  };
  const catalog = [...objects.entries()].find(([, b]) =>
    /\/Type\s*\/Catalog/.test(b),
  );
  walk(Number(catalog[1].match(/\/Pages\s+(\d+) 0 R/)[1]));
  const pageOf = (ref) => pageOrder.indexOf(Number(ref)) + 1;

  const result = {};
  // Named destinations: (name) [page 0 R /XYZ ...] inside a Dests name tree.
  for (const body of objects.values()) {
    for (const m of body.matchAll(/\(([^)]+)\)\s*\[\s*(\d+) 0 R\s*\/XYZ/g))
      result[m[1]] = pageOf(m[2]);
    for (const m of body.matchAll(/\/([\w-]+)\s*\[\s*(\d+) 0 R\s*\/XYZ/g))
      result[m[1]] ??= pageOf(m[2]);
  }
  return result;
}

// ---------------------------------------------------------------- main

async function build(browser, key) {
  const guide = GUIDES[key];
  const src = await readFile(path.join(guideDir, guide.file), "utf8");
  const { meta, body } = frontMatter(src);
  const css = await readFile(path.join(here, "guide.css"), "utf8");
  const { html, toc, missing } = await render(body);

  const tmp = path.join(outDir, `.${key}.html`);
  const target = path.join(outDir, guide.pdf);
  const ctx = await browser.newContext();
  const pg = await ctx.newPage();

  const print = async (pages) => {
    await writeFile(tmp, page({ meta, html, toc, pages, css }));
    await pg.goto(pathToFileURL(tmp).href, { waitUntil: "load" });
    await pg.evaluate(() => document.fonts.ready);
    return pg.pdf({
      format: "A4",
      printBackground: true,
      preferCSSPageSize: true,
      outline: true,
      tagged: true,
    });
  };

  const first = await print(null);
  const pages = linkTargets(first);
  const found = toc.filter((t) => pages[t.id]).length;
  const final = await print(pages);
  await writeFile(target, final);
  await ctx.close();

  const count = (final.toString("latin1").match(/\/Type\s*\/Page[^s]/g) ?? [])
    .length;
  console.log(
    `${key}: ${path.relative(repo, target)} · ${count} pages · contents ${found}/${toc.length} located` +
      (missing.length ? ` · MISSING SHOTS: ${missing.join(", ")}` : ""),
  );
  return missing.length;
}

const wanted = process.argv.slice(2).filter((a) => !a.startsWith("-"));
const keys = wanted.length
  ? wanted
  : Object.keys(GUIDES).filter((k) =>
      existsSync(path.join(guideDir, GUIDES[k].file)),
    );
await mkdir(outDir, { recursive: true });
// The bundled browser when it is installed, otherwise the Chrome on this machine.
const browser = await chromium
  .launch()
  .catch(() => chromium.launch({ channel: "chrome" }));
let missing = 0;
try {
  for (const key of keys) missing += await build(browser, key);
} finally {
  await browser.close();
}
for (const f of await readdir(outDir))
  if (
    f.startsWith(".") &&
    f.endsWith(".html") &&
    !process.argv.includes("--keep-html")
  ) {
    await import("node:fs/promises").then((fs) => fs.rm(path.join(outDir, f)));
  }
if (missing && process.argv.includes("--strict")) process.exit(1);
