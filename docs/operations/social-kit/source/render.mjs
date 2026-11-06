// Renders every image in ../assets from content.mjs.
//
//   node render.mjs            all images
//   node render.mjs posts      only names containing "posts"
//
// Needs Playwright's Chromium (pre-installed in the Claude Code sandbox; else
// `npx playwright install chromium`) and curl. The first run downloads Inter,
// JetBrains Mono and Noto Sans Ethiopic from Google Fonts (all SIL Open Font
// Licence) into source/.fonts, which is gitignored, and inlines them into each
// page: a browser behind a proxy may not reach the font host itself. A missing
// Ethiopic font draws Amharic as empty boxes, so the script refuses to write an
// image whose fonts did not load.

import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { execFileSync } from "node:child_process";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { createRequire } from "node:module";

import {
  BANNER,
  CAROUSEL,
  CTA,
  LABELS,
  MONTHS,
  PILLARS,
  SITE,
  SCREEN_POSTS,
  WORD_PAIRS,
} from "./content.mjs";

const require = createRequire(import.meta.url);
const { chromium } = require("playwright");

const HERE = dirname(fileURLToPath(import.meta.url));
const OUT = join(HERE, "..", "assets");
const filter = process.argv[2] ?? "";
const keepHtml = process.argv.includes("--html");

// ---------------------------------------------------------------- palette

const C = {
  navy: "#0a2e4a",
  deep: "#071f33",
  primary: "#0f4c75",
  light: "#3282b8",
  gold: "#e8a838",
  ink: "#f4f7fa",
  muted: "#bfd3e3",
  panel: "#0f3a5c",
  line: "#24557c",
  ok: "#34d399",
  warn: "#fbbf24",
  paper: "#f8fafc",
  text: "#0f172a",
  sub: "#334155",
  border: "#e2e8f0",
};

// The tibeb band: a woven border of gold diamonds between two blue threads.
// The design system allows this one cultural element, used with restraint.
const TIBEB = `url("data:image/svg+xml,${encodeURIComponent(
  `<svg xmlns='http://www.w3.org/2000/svg' width='44' height='22'><rect width='44' height='3' fill='${C.light}'/><rect y='19' width='44' height='3' fill='${C.light}'/><path d='M22 5 L28 11 L22 17 L16 11Z' fill='${C.gold}'/><path d='M0 8 L3 11 L0 14Z M44 8 L41 11 L44 14Z' fill='${C.light}'/><rect x='9' y='10' width='2' height='2' fill='${C.gold}'/><rect x='33' y='10' width='2' height='2' fill='${C.gold}'/></svg>`,
)}")`;

const CSS = `
*{box-sizing:border-box;margin:0;padding:0}
body{position:relative;overflow:hidden;font-family:Inter,'Noto Sans Ethiopic',sans-serif;-webkit-font-smoothing:antialiased;font-feature-settings:"tnum" 0}
body.dark{background:radial-gradient(circle at 85% 12%,#134a72 0%,${C.navy} 50%,${C.deep} 100%);color:${C.ink}}
body.light{background:${C.paper};color:${C.text}}
.am,:lang(am) .h,:lang(am) .p,:lang(am) .over{font-family:'Noto Sans Ethiopic',Inter,sans-serif}
.tibeb{position:absolute;left:0;right:0;height:22px;background:${TIBEB} repeat-x}
.frame{position:absolute;inset:0;display:flex;flex-direction:column}
.brand{display:flex;align-items:center;gap:18px}
.mark{display:flex;align-items:center;justify-content:center;background:${C.primary};border:2px solid ${C.light};color:${C.ink};font-weight:800;line-height:1}
.word{font-weight:700;letter-spacing:2px}
.light .word{color:${C.navy}}
.over{font-weight:600;letter-spacing:3px;text-transform:uppercase;color:${C.gold}}
:lang(am) .over{letter-spacing:1px}
.light .over{color:${C.primary}}
.h{font-weight:800;letter-spacing:-1.5px;line-height:1.06}
:lang(am) .h{font-weight:700;letter-spacing:0;line-height:1.4}
.p{color:${C.muted};line-height:1.45}
:lang(am) .p{line-height:1.7}
.light .p{color:${C.sub}}
.chip{display:inline-flex;align-items:center;white-space:nowrap;border-radius:999px;font-weight:600;border:2px solid #3d6688;color:#e2e8f0}
.chip.gold{background:${C.gold};border-color:${C.gold};color:${C.text};font-weight:700}
.light .chip{border-color:#cbd5e1;color:${C.text}}
.light .chip.gold{border-color:${C.gold}}
.url{font-weight:600;color:${C.muted}}
.light .url{color:${C.primary}}
.card{background:${C.panel};border:2px solid ${C.line};border-radius:28px}
.light .card{background:#fff;border-color:${C.border};box-shadow:0 8px 24px rgba(15,23,42,.08)}
.mono{font-family:'JetBrains Mono',ui-monospace,monospace}
.pill{display:inline-flex;align-items:center;gap:10px;padding:8px 20px;border-radius:999px;font-size:24px;font-weight:600;white-space:nowrap}
.pill.warn{background:#3a2a07;color:${C.warn}}
.pill.ok{background:#0f3d2b;color:#6ee7b7}
.light .pill.ok{background:#d1fae5;color:#065f46}
.light .pill.warn{background:#fef3c7;color:#92400e}
.dot{width:12px;height:12px;border-radius:50%;background:currentColor;display:inline-block}
.foot{display:flex;justify-content:space-between;align-items:center;gap:24px}
`;

// ---------------------------------------------------------------- fonts

function loadFonts() {
  const dir = join(HERE, ".fonts");
  const cache = join(dir, "fonts.css");
  if (existsSync(cache)) return readFileSync(cache, "utf8");
  const url =
    "https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600&family=Noto+Sans+Ethiopic:wght@400;500;600;700&display=block";
  // A current browser user agent, so the CSS names woff2 files.
  const ua =
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36";
  const curl = (args) => execFileSync("curl", ["-sSfL", "-A", ua, ...args]);
  let css = curl([url]).toString();
  const urls = [...new Set(css.match(/https:\/\/fonts\.gstatic\.com\/[^)]+/g) ?? [])];
  if (urls.length === 0) throw new Error("Google Fonts returned no font files");
  for (const u of urls) {
    const data = curl([u]).toString("base64");
    css = css.split(u).join(`data:font/woff2;base64,${data}`);
  }
  mkdirSync(dir, { recursive: true });
  writeFileSync(cache, css);
  return css;
}

// ---------------------------------------------------------------- shared parts

const brand = (size = 64) =>
  `<div class="brand"><div class="mark" style="width:${size}px;height:${size}px;border-radius:${Math.round(size / 4)}px;font-size:${Math.round(size * 0.6)}px">E</div><div class="word" style="font-size:${Math.round(size * 0.62)}px">ETHR</div></div>`;

const check = (color) =>
  `<svg width="28" height="28" viewBox="0 0 24 24"><path d="M5 12.5l4.2 4.2L19 7" fill="none" stroke="${color}" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>`;

const clock = (color) =>
  `<svg width="28" height="28" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" fill="none" stroke="${color}" stroke-width="2.5"/><path d="M12 7v5l3 2" fill="none" stroke="${color}" stroke-width="2.5" stroke-linecap="round"/></svg>`;

const ICON = {
  pin: `<path d="M12 21s7-6.2 7-11.5A7 7 0 0 0 5 9.5C5 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>`,
  lock: `<rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/>`,
  layers: `<path d="M12 3l9 5-9 5-9-5 9-5z"/><path d="M3 13l9 5 9-5"/>`,
  list: `<path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1"/><circle cx="4.5" cy="12" r="1"/><circle cx="4.5" cy="18" r="1"/>`,
};
const icon = (name, color, size = 40) =>
  `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="${color}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${ICON[name]}</svg>`;

// A hyphenated word ("6-month", "EMP-0012") never breaks at its hyphen.
const nb = (text) =>
  text.replace(/(\S+-\S+)/g, '<span style="white-space:nowrap">$1</span>');

// ---------------------------------------------------------------- drawings
// Each takes (lang, theme) and returns a block about 960px wide. None shows a
// real amount or a real person: codes are demo codes, bars stand in for money.

const VIS = {
  sync(lang) {
    const L = LABELS[lang];
    const rows = [
      ["EMP-0012", "08:02"],
      ["EMP-0047", "08:05"],
      ["EMP-0103", "08:11"],
    ];
    const col = (pill, cls, mark) => `
      <div class="card" style="flex:1;padding:32px;display:flex;flex-direction:column;gap:22px">
        <div style="display:flex;justify-content:space-between;align-items:center"><span class="am" style="font-size:26px;font-weight:600;color:${C.muted}">${L.checkIn}</span><span class="pill ${cls}"><span class="dot"></span><span class="am">${pill}</span></span></div>
        ${rows
          .map(
            ([code, t]) =>
              `<div style="display:flex;align-items:center;gap:16px;padding:14px 18px;border-radius:16px;background:rgba(10,46,74,.6)">${mark}<span class="mono" style="font-size:26px;flex:1">${code}</span><span class="mono" style="font-size:26px;color:${C.muted}">${t}</span></div>`,
          )
          .join("")}
      </div>`;
    return `<div style="display:flex;align-items:center;gap:24px;width:100%">
      ${col(L.offline, "warn", clock(C.warn))}
      <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="${C.gold}" stroke-width="2.5" stroke-linecap="round"><path d="M4 12h15M13 6l6 6-6 6"/></svg>
      ${col(L.synced, "ok", check(C.ok))}
    </div>`;
  },

  payslip(lang) {
    const L = LABELS[lang];
    const rows = [
      [L.gross, 100, C.primary],
      [L.tax, 34, C.gold],
      [L.pension, 14, C.light],
      [L.net, 58, "#047857"],
    ];
    return `<div style="display:flex;gap:24px;width:100%;align-items:stretch">
      <div class="card" style="flex:1.6;padding:36px;display:flex;flex-direction:column;gap:24px">
        <div style="display:flex;justify-content:space-between;align-items:center"><span class="am" style="font-size:30px;font-weight:700">${L.payslip}</span><span class="pill ok"><span class="am">${L.approved}</span></span></div>
        ${rows
          .map(
            ([label, w, color]) =>
              `<div style="display:flex;flex-direction:column;gap:10px"><span class="am" style="font-size:24px;color:${C.sub};font-weight:500">${label}</span><div style="height:22px;border-radius:999px;background:#eef2f6"><div style="height:22px;width:${w}%;border-radius:999px;background:${color}"></div></div></div>`,
          )
          .join("")}
      </div>
      <div style="flex:1;display:flex;flex-direction:column;gap:24px">
        <div class="card" style="flex:1;padding:32px;display:flex;flex-direction:column;justify-content:center;gap:10px;background:${C.navy};border-color:${C.navy};color:${C.ink}"><span class="am" style="font-size:22px;letter-spacing:1px;color:${C.gold};font-weight:600">${L.tax}</span><span class="am" style="font-size:30px;font-weight:700;line-height:1.35">${L.proclamation}</span></div>
        <div class="card" style="flex:1;padding:32px;display:flex;flex-direction:column;justify-content:center;gap:8px"><span style="font-size:46px;font-weight:800;color:${C.primary};letter-spacing:-1px;white-space:nowrap">7% + 11%</span><span class="am" style="font-size:24px;color:${C.sub};line-height:1.5">${L.pensionSplit}</span></div>
      </div>
    </div>`;
  },

  calendar() {
    const cells = MONTHS.slice(0, 12)
      .map(
        (m, i) =>
          `<div class="card" style="padding:18px 20px;border-radius:20px;display:flex;flex-direction:column;gap:4px"><span class="mono" style="font-size:20px;color:${C.muted}">${String(i + 1).padStart(2, "0")}</span><span class="am" style="font-size:28px;font-weight:600">${m}</span></div>`,
      )
      .join("");
    return `<div style="width:100%;display:flex;flex-direction:column;gap:16px">
      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px">${cells}</div>
      <div style="display:flex;align-items:center;gap:24px;padding:22px 28px;border-radius:20px;background:${C.gold};color:${C.text}"><span class="mono" style="font-size:24px;font-weight:600">13</span><span class="am" style="font-size:36px;font-weight:700">${MONTHS[12]}</span><span style="margin-left:auto;display:flex;gap:8px">${Array.from(
        { length: 6 },
        (_, i) =>
          `<span style="width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:700;background:${i < 5 ? C.text : "transparent"};color:${i < 5 ? C.gold : C.text};border:2px ${i < 5 ? "solid" : "dashed"} ${C.text}">${i + 1}</span>`,
      ).join("")}</span></div>
    </div>`;
  },

  language() {
    return `<div class="card" style="width:100%;padding:36px 40px;display:flex;flex-direction:column;gap:6px">
      <div style="display:flex;justify-content:flex-end;margin-bottom:14px"><div style="display:flex;background:${C.paper};border:2px solid ${C.border};border-radius:999px;padding:6px"><span style="font-size:24px;padding:6px 22px;border-radius:999px;font-weight:600">EN</span><span class="am" style="font-size:24px;padding:6px 22px;border-radius:999px;background:${C.gold};font-weight:700">አማ</span></div></div>
      ${WORD_PAIRS.map(
        ([en, am], i) =>
          `<div style="display:flex;align-items:center;padding:16px 0;${i ? `border-top:2px solid ${C.border}` : ""}"><span style="flex:1;font-size:32px;font-weight:600;color:${C.sub}">${en}</span><span class="am" style="flex:1;font-size:36px;font-weight:700;color:${C.navy}">${am}</span></div>`,
      ).join("")}
    </div>`;
  },

  trust(lang) {
    const names = ["pin", "lock", "layers", "list"];
    return `<div style="width:100%;display:grid;grid-template-columns:1fr 1fr;gap:20px">
      ${LABELS[lang].trust
        .map(
          ([title, tag], i) =>
            `<div class="card" style="padding:30px;display:flex;flex-direction:column;gap:16px;min-height:200px">${icon(names[i], C.gold, 44)}<span class="am" style="font-size:30px;font-weight:700;line-height:1.3">${title}</span>${tag ? `<span class="am mono" style="font-size:22px;color:${C.muted}">${tag}</span>` : ""}</div>`,
        )
        .join("")}
    </div>`;
  },

  leave(lang) {
    const L = LABELS[lang];
    const rows = [
      ["AT", "ኅዳር 12", L.approved, "ok"],
      ["MK", "ኅዳር 18", L.pending, "warn"],
      ["SB", "ታኅሣሥ 3", L.approved, "ok"],
    ];
    return `<div style="display:flex;gap:24px;width:100%;align-items:stretch">
      <div class="card" style="flex:1;padding:32px;display:flex;flex-direction:column;justify-content:center;gap:20px">
        <span class="am" style="font-size:28px;font-weight:700">${L.leaveBalance}</span>
        <div style="height:26px;border-radius:999px;background:#eef2f6"><div style="height:26px;width:62%;border-radius:999px;background:${C.primary}"></div></div>
        <div style="display:flex;gap:8px">${Array.from({ length: 10 }, (_, i) => `<span style="flex:1;height:14px;border-radius:4px;background:${i < 6 ? C.light : C.border}"></span>`).join("")}</div>
      </div>
      <div class="card" style="flex:1.4;padding:28px;display:flex;flex-direction:column;gap:14px">
        ${rows
          .map(
            ([ini, d, s, cls]) =>
              `<div style="display:flex;align-items:center;gap:16px;padding:12px 6px"><span style="width:56px;height:56px;border-radius:50%;background:${C.navy};color:${C.ink};display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:700">${ini}</span><span class="am" style="flex:1;font-size:28px;font-weight:600">${d}</span><span class="pill ${cls}"><span class="am">${s}</span></span></div>`,
          )
          .join("")}
      </div>
    </div>`;
  },

  kiosk(lang) {
    const L = LABELS[lang];
    const keys = ["1", "2", "3", "4", "5", "6", "7", "8", "9", "", "0", ""];
    return `<div style="display:flex;gap:28px;width:100%;align-items:stretch">
      <div class="card" style="flex:1.1;padding:32px;display:flex;flex-direction:column;gap:20px">
        <span class="am" style="font-size:26px;font-weight:600;color:${C.muted}">${L.kiosk}</span>
        <div class="mono" style="font-size:96px;font-weight:600;letter-spacing:12px;padding:20px 28px;border-radius:20px;background:${C.deep};border:2px solid ${C.line}">12<span style="color:${C.gold}">|</span></div>
        <span class="am" style="font-size:24px;line-height:1.5;color:${C.muted}">${nb(L.kioskHint)}</span>
      </div>
      <div style="flex:1;display:grid;grid-template-columns:repeat(3,1fr);gap:14px">
        ${keys
          .map((k) =>
            k
              ? `<div class="card mono" style="border-radius:18px;display:flex;align-items:center;justify-content:center;font-size:40px;font-weight:600;${k === "1" || k === "2" ? `background:${C.gold};color:${C.text};border-color:${C.gold}` : ""}">${k}</div>`
              : `<div></div>`,
          )
          .join("")}
      </div>
    </div>`;
  },

  trial(lang) {
    return `<div style="width:100%;display:flex;flex-direction:column;gap:20px">
      ${LABELS[lang].cta
        .map(
          (t, i) =>
            `<div class="card" style="display:flex;align-items:center;gap:24px;padding:30px 34px;${i === 0 ? `background:${C.gold};border-color:${C.gold};color:${C.text}` : ""}">${check(i === 0 ? C.text : C.ok)}<span class="am" style="font-size:40px;font-weight:700">${t}</span></div>`,
        )
        .join("")}
    </div>`;
  },
};

// ---------------------------------------------------------------- layouts

const FONTS = loadFonts();

const doc = ({ w, h, theme, lang, body }) => `<!doctype html><html lang="${lang}"><head><meta charset="utf-8">
<style>${FONTS}${CSS}</style></head><body class="${theme}" style="width:${w}px;height:${h}px">${body}</body></html>`;

const footer = (lang, size = 26, path = "") =>
  `<div class="foot"><span class="url" style="font-size:${size}px">${SITE}${path ? `/${lang}${path === "/" ? "" : path}` : ""}</span><span class="chip gold am" style="font-size:${size}px;padding:${Math.round(size * 0.5)}px ${size}px">${CTA[lang].trial}</span></div>`;

// 1080 x 1350: the 4:5 feed post for Instagram, Facebook and LinkedIn.
function post(p, lang) {
  const t = p[lang];
  const hSize = lang === "am" ? 66 : t.h.length > 34 ? 76 : 88;
  return doc({
    w: 1080,
    h: 1350,
    theme: p.theme,
    lang,
    body: `<div class="tibeb" style="top:0"></div>
    <div class="frame" style="padding:96px 84px 76px">
      ${brand(60)}
      <div style="margin-top:64px;display:flex;flex-direction:column;gap:22px">
        <span class="over" style="font-size:24px">${t.over}</span>
        <h1 class="h" style="font-size:${hSize}px">${nb(t.h)}</h1>
        <p class="p" style="font-size:${lang === "am" ? 28 : 30}px;max-width:900px">${t.p}</p>
      </div>
      <div style="flex:1;display:flex;align-items:center;padding:40px 0">${VIS[p.visual](lang, p.theme)}</div>
      ${footer(lang)}
    </div>`,
  });
}

// 1080 x 1920: Stories, Status and Reels covers. Content stays between 250px
// and 1600px, clear of the platforms' own top bar and reply field.
function story(p, lang) {
  const t = p[lang];
  return doc({
    w: 1080,
    h: 1920,
    theme: p.theme,
    lang,
    body: `<div class="tibeb" style="top:0"></div><div class="tibeb" style="bottom:0"></div>
    <div class="frame" style="padding:250px 84px 320px">
      ${brand(64)}
      <div style="margin-top:72px;display:flex;flex-direction:column;gap:26px">
        <span class="over" style="font-size:26px">${t.over}</span>
        <h1 class="h" style="font-size:${lang === "am" ? 76 : 100}px">${nb(t.h)}</h1>
        <p class="p" style="font-size:34px">${t.p}</p>
      </div>
      <div style="flex:1;display:flex;align-items:center;padding:48px 0">${VIS[p.visual](lang, p.theme)}</div>
      <div style="display:flex;flex-direction:column;align-items:center;gap:20px"><span class="chip gold am" style="font-size:36px;padding:22px 48px">${CTA[lang].trial}</span><span class="url" style="font-size:30px">${SITE}</span></div>
    </div>`,
  });
}

// 1200 x 675: link posts on X and LinkedIn, and a Telegram channel image.
function link(p, lang) {
  const t = p[lang];
  return doc({
    w: 1200,
    h: 675,
    theme: p.theme,
    lang,
    body: `<div class="tibeb" style="bottom:0"></div>
    <div class="frame" style="padding:64px 72px 72px">
      ${brand(52)}
      <div style="flex:1;display:flex;flex-direction:column;justify-content:center;gap:20px;max-width:1000px">
        <span class="over" style="font-size:22px">${t.over}</span>
        <h1 class="h" style="font-size:${lang === "am" ? 54 : 68}px">${nb(t.h)}</h1>
        <p class="p" style="font-size:26px">${t.p}</p>
      </div>
      ${footer(lang, 22, p.path)}
    </div>`,
  });
}

// 1080 x 1350 carousel slides: a cover, the six problems, and the close.
function carouselCover(lang) {
  const t = CAROUSEL[lang].cover;
  return doc({
    w: 1080,
    h: 1350,
    theme: "dark",
    lang,
    body: `<div class="tibeb" style="top:0"></div>
    <div class="frame" style="padding:96px 84px 76px">
      ${brand(60)}
      <div style="flex:1;display:flex;flex-direction:column;justify-content:center;gap:30px">
        <span class="over" style="font-size:26px">${t.over}</span>
        <h1 class="h" style="font-size:${lang === "am" ? 72 : 92}px">${t.h}</h1>
        <p class="p" style="font-size:34px">${t.p}</p>
      </div>
      <div class="foot"><span class="url" style="font-size:26px">1 / 8</span><svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="${C.gold}" stroke-width="2.5" stroke-linecap="round"><path d="M4 12h15M13 6l6 6-6 6"/></svg></div>
    </div>`,
  });
}

function carouselSlide(lang, i) {
  const [title, today, withEthr] = CAROUSEL[lang].slides[i];
  const L = LABELS[lang];
  const small = lang === "am" ? 33 : 37;
  return doc({
    w: 1080,
    h: 1350,
    theme: "light",
    lang,
    body: `<div class="tibeb" style="top:0"></div>
    <div class="frame" style="padding:96px 84px 76px;gap:36px">
      <div style="display:flex;justify-content:space-between;align-items:center">${brand(52)}<span class="mono" style="font-size:24px;color:${C.sub}">0${i + 1} / 06</span></div>
      <h1 class="h" style="font-size:${lang === "am" ? 70 : 84}px;margin-top:28px">${title}</h1>
      <div class="card" style="padding:40px 44px;display:flex;flex-direction:column;gap:18px;background:#f1f5f9;box-shadow:none"><span class="over am" style="font-size:24px;color:${C.sub}">${L.today}</span><p class="p" style="font-size:${small}px">${today}</p></div>
      <div class="card" style="padding:40px 44px;display:flex;flex-direction:column;gap:18px;border:3px solid ${C.gold}"><span class="over am" style="font-size:24px;color:#92400e">${L.withEthr}</span><p class="p" style="font-size:${small}px;color:${C.text}">${withEthr}</p></div>
      <div style="flex:1"></div>
      <div class="foot"><span class="url" style="font-size:26px">${i + 2} / 8</span><svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="${C.primary}" stroke-width="2.5" stroke-linecap="round"><path d="M4 12h15M13 6l6 6-6 6"/></svg></div>
    </div>`,
  });
}

// Profile banners. Each platform covers part of its banner with the avatar
// or crops it on phones; the text sits inside every platform's safe area.
function banner({ w, h, pad, align = "center", size = 1, mark = true }) {
  return doc({
    w,
    h,
    theme: "dark",
    lang: "en",
    body: `<div class="tibeb" style="bottom:0"></div>
    <div class="frame" style="padding:${pad};align-items:${align};justify-content:center;text-align:${align === "center" ? "center" : "right"};gap:${Math.round(22 * size)}px">
      ${mark ? brand(Math.round(56 * size)) : ""}
      <h1 class="h" style="font-size:${Math.round(56 * size)}px;max-width:${Math.round(1100 * size)}px">${BANNER.h}</h1>
      <p class="am" style="font-size:${Math.round(28 * size)}px;color:${C.gold};font-weight:600;line-height:1.6">${BANNER.am}</p>
      <p class="p" style="font-size:${Math.round(24 * size)}px">${BANNER.line}</p>
    </div>`,
  });
}

function avatar(theme) {
  const dark = theme === "dark";
  return doc({
    w: 800,
    h: 800,
    theme,
    lang: "en",
    body: `<div class="frame" style="align-items:center;justify-content:center;${dark ? "" : `background:${C.paper}`}">
      <div class="mark" style="width:440px;height:440px;border-radius:110px;font-size:270px;border-width:8px;${dark ? "" : `box-shadow:0 20px 50px rgba(15,23,42,.18)`}">E</div>
    </div>`,
  });
}

// ---------------------------------------------------------------- real screens
// Screenshots from source/screens/capture.mjs, framed. A missing screenshot
// fails the run rather than drawing an empty frame.

const SHOTS = join(OUT, "screenshots");
const shot = (name) => {
  const file = join(SHOTS, `${name}.png`);
  if (!existsSync(file)) {
    throw new Error(`${file} is missing; run source/screens/capture.mjs first`);
  }
  return `data:image/png;base64,${readFileSync(file).toString("base64")}`;
};

// A browser window: the screenshot under a plain title bar. The address shows
// how a tenant is reached in production, ethr.et/{slug}.
// `zoom` enlarges the screen inside the window; the top left stays in view.
const browserFrame = (name, { width = 912, height = 600, zoom = 1, style = "" } = {}) =>
  `<div style="width:${width}px;border-radius:18px;overflow:hidden;background:#fff;box-shadow:0 30px 70px rgba(4,20,35,.45);border:1px solid rgba(255,255,255,.12);${style}">
    <div style="height:44px;background:#e9eef3;display:flex;align-items:center;gap:10px;padding:0 18px">
      <span style="width:13px;height:13px;border-radius:50%;background:#f87171"></span><span style="width:13px;height:13px;border-radius:50%;background:#fbbf24"></span><span style="width:13px;height:13px;border-radius:50%;background:#34d399"></span>
      <span style="margin-left:18px;flex:1;max-width:420px;height:26px;border-radius:999px;background:#fff;color:#64748b;font-size:15px;display:flex;align-items:center;padding:0 14px">ethr.et/demo</span>
    </div>
    <div style="height:${height}px;background:url(${shot(name)}) top left/${zoom * 100}% auto no-repeat"></div>
  </div>`;

// A phone: the screenshot inside a dark bezel with rounded corners.
const phoneFrame = (name, { width = 330, style = "" } = {}) => {
  const height = Math.round((width * 844) / 390);
  return `<div style="width:${width + 24}px;padding:12px;border-radius:${Math.round(width / 6)}px;background:#0b1f31;box-shadow:0 30px 70px rgba(4,20,35,.5);border:2px solid #1d405c;${style}">
    <div style="width:${width}px;height:${height}px;border-radius:${Math.round(width / 7.5)}px;overflow:hidden;background:url(${shot(name)}) top center/100% auto no-repeat"></div>
  </div>`;
};

const SCREEN_LAYOUT = {
  // One desktop screen.
  browser: (shots) => browserFrame(shots[0]),
  // English behind, Amharic in front.
  browsers: (shots) =>
    `<div style="position:relative;width:960px;height:640px">${browserFrame(shots[0], { width: 760, height: 470, zoom: 1.8, style: "position:absolute;left:0;top:0" })}${browserFrame(shots[1], { width: 760, height: 470, zoom: 1.8, style: "position:absolute;right:0;bottom:0" })}</div>`,
  // One phone, with the line beside it.
  phone: (shots) => phoneFrame(shots[0], { width: 350 }),
  // Two phones side by side.
  phones: (shots) =>
    `<div style="display:flex;gap:40px;justify-content:center">${shots.map((n) => phoneFrame(n, { width: 330 })).join("")}</div>`,
};

function screenPost(p, lang) {
  const t = p[lang];
  const shots = p.shots.map((n) => n.replace("{lang}", lang));
  return doc({
    w: 1080,
    h: 1350,
    theme: "dark",
    lang,
    body: `<div class="tibeb" style="top:0"></div>
    <div class="frame" style="padding:90px 60px 72px">
      <div style="padding:0 24px">${brand(56)}</div>
      <div style="margin-top:44px;padding:0 24px;display:flex;flex-direction:column;gap:18px">
        <span class="over" style="font-size:24px">${t.over}</span>
        <h1 class="h" style="font-size:${lang === "am" ? 58 : 70}px">${nb(t.h)}</h1>
      </div>
      <div style="flex:1;display:flex;align-items:center;justify-content:center;padding:36px 0">${SCREEN_LAYOUT[p.layout](shots)}</div>
      <div style="padding:0 24px">${footer(lang)}</div>
    </div>`,
  });
}

// ---------------------------------------------------------------- the job list

const jobs = [];
const add = (name, html, w, h) => jobs.push({ name, html, w, h });

// Built lazily: a screen post needs its screenshots, the rest of the kit does
// not. With no screenshots captured at all they are skipped with a notice; a
// single missing screenshot among captured ones still fails the run.
const addLazy = (name, build, w, h) => jobs.push({ name, build, w, h });
if (!existsSync(SHOTS)) {
  console.warn("No assets/screenshots/ yet: skipping screen posts (run source/screens/capture.mjs)");
}
for (const p of existsSync(SHOTS) ? SCREEN_POSTS : []) {
  for (const lang of ["en", "am"]) {
    addLazy(`screens/${p.id}-${lang}.png`, () => screenPost(p, lang), 1080, 1350);
  }
}

for (const p of PILLARS) {
  for (const lang of ["en", "am"]) {
    add(`posts/${p.id}-${lang}.png`, post(p, lang), 1080, 1350);
    add(`links/${p.id}-${lang}.png`, link(p, lang), 1200, 675);
  }
}
for (const id of ["trial", "offline", "payroll", "calendar"]) {
  const p = PILLARS.find((x) => x.id === id);
  for (const lang of ["en", "am"]) {
    add(`stories/${id}-${lang}.png`, story(p, lang), 1080, 1920);
  }
}
for (const lang of ["en", "am"]) {
  add(`carousel-${lang}/01-cover.png`, carouselCover(lang), 1080, 1350);
  CAROUSEL[lang].slides.forEach((_, i) =>
    add(
      `carousel-${lang}/0${i + 2}-problem.png`,
      carouselSlide(lang, i),
      1080,
      1350,
    ),
  );
  add(
    `carousel-${lang}/08-trial.png`,
    post(
      PILLARS.find((x) => x.id === "trial"),
      lang,
    ),
    1080,
    1350,
  );
}
add("profile/avatar-dark.png", avatar("dark"), 800, 800);
add("profile/avatar-light.png", avatar("light"), 800, 800);
// LinkedIn: the logo overlaps the lower left; keep text right of x=420.
add(
  "profile/banner-linkedin-1584x396.png",
  banner({ w: 1584, h: 396, pad: "36px 80px 48px 440px", align: "flex-end", size: 0.78, mark: false }),
  1584,
  396,
);
// Facebook: phones crop the sides; keep text inside the central 1200px.
add(
  "profile/banner-facebook-1640x624.png",
  banner({ w: 1640, h: 624, pad: "60px 220px 80px", size: 0.95 }),
  1640,
  624,
);
// X: the avatar overlaps the lower left; keep text right of x=360.
add(
  "profile/banner-x-1500x500.png",
  banner({ w: 1500, h: 500, pad: "48px 80px 60px 400px", align: "flex-end", size: 0.85, mark: false }),
  1500,
  500,
);
// YouTube: only the central 1546 x 423 shows on every device.
add(
  "profile/banner-youtube-2560x1440.png",
  banner({ w: 2560, h: 1440, pad: "508px 507px 508px", size: 1.4, mark: false }),
  2560,
  1440,
);

// ---------------------------------------------------------------- render

const browser = await chromium.launch();
const page = await browser.newPage();
let written = 0;
for (const job of jobs) {
  if (filter && !job.name.includes(filter)) continue;
  await page.setViewportSize({ width: job.w, height: job.h });
  await page.setContent(job.html ?? job.build(), { waitUntil: "load" });
  // check() is true for a face that is merely absent, so load the faces
  // explicitly and count what came back.
  const fonts = await page.evaluate(async () => ({
    ethiopic: (await document.fonts.load("600 32px 'Noto Sans Ethiopic'", "መገኘት")).length > 0,
    inter: (await document.fonts.load("700 32px Inter", "ETHR")).length > 0,
  }));
  if (!fonts.ethiopic || !fonts.inter) {
    throw new Error(`${job.name}: fonts did not load ${JSON.stringify(fonts)}`);
  }
  // A drawing taller than its slot pushes the footer off the canvas.
  const overflow = await page.evaluate(
    () => document.body.scrollHeight > window.innerHeight + 1 || [...document.querySelectorAll(".frame")].some((f) => f.scrollHeight > f.clientHeight + 1),
  );
  if (overflow) throw new Error(`${job.name}: content overflows the canvas`);
  const file = join(OUT, job.name);
  mkdirSync(dirname(file), { recursive: true });
  await page.screenshot({ path: file, type: "png" });
  if (keepHtml) writeFileSync(file.replace(/\.png$/, ".html"), job.html ?? job.build());
  written++;
}
await browser.close();
console.log(`${written} images written to ${OUT}`);

// Palette-reduce what was written (needs python3 with Pillow). Skipped with a
// warning rather than failing: the full-colour images are still correct.
const done = jobs
  .filter((j) => !filter || j.name.includes(filter))
  .map((j) => join(OUT, j.name));
try {
  execFileSync("python3", ["-I", join(HERE, "optimise.py"), ...done], {
    stdio: "inherit",
  });
} catch {
  console.warn("optimise.py did not run; images are full colour");
}
