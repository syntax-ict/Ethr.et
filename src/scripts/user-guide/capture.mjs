#!/usr/bin/env node
/**
 * Takes the screenshots for the Amharic user guides.
 *
 *   node scripts/user-guide/capture.mjs                  every shot
 *   node scripts/user-guide/capture.mjs emp-dashboard    the shots named
 *   node scripts/user-guide/capture.mjs --guide=manager  one guide's shots
 *   node scripts/user-guide/capture.mjs --survey=employee /dashboard /leave
 *                                                        raw pages, to plan shots
 *
 * Needs the guide stack running (docs/user-guides/am/README.md): the web app on
 * GUIDE_WEB (default http://demo.localhost:3140) against a database that
 * scripts/user-guide/prepare-data.php has dressed. Each shot is saved as
 * docs/user-guides/am/shots/<id>.png with <id>.json holding the image size
 * and the box of every numbered marker, which build.mjs draws over it.
 *
 * Some shots submit forms (a leave request, a correction) so that the
 * manager's screens have something to approve. Run it on a freshly dressed
 * database, in the order the shots are listed.
 */
import { mkdir, writeFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { chromium } from "@playwright/test";
import { SHOTS, ACCOUNTS } from "./shots.mjs";

const here = path.dirname(fileURLToPath(import.meta.url));
const repo = path.resolve(here, "../../..");
const shotDir = path.join(repo, "docs/user-guides/am/shots");
const surveyDir =
  process.env.GUIDE_SURVEY_DIR ?? path.join(repo, "docs/user-guides/.survey");
const WEB = (process.env.GUIDE_WEB ?? "http://demo.localhost:3140").replace(
  /\/$/,
  "",
);
const ORG = process.env.GUIDE_ORG ?? "demo";

// Every context stands in the head office, so the phone check-in screen shows
// a located punch rather than a permission error.
const PLACE = {
  geolocation: { latitude: 9.0108, longitude: 38.7613, accuracy: 15 },
  permissions: ["geolocation"],
};

const DEVICES = {
  desktop: { viewport: { width: 1280, height: 800 }, deviceScaleFactor: 2 },
  mobile: {
    viewport: { width: 390, height: 780 },
    deviceScaleFactor: 3,
    isMobile: true,
    hasTouch: true,
  },
};

// The guide shows the product, not the development tools around it.
const HIDE_DEV = `
  nextjs-portal, [data-nextjs-toast], #__next-build-watcher, [data-next-badge-root] { display: none !important; }
  *, *::before, *::after { transition: none !important; animation-duration: 0s !important; caret-color: transparent !important; }
`;

const sessions = new Map();

async function signIn(browser, role, device) {
  const key = `${role}:${device}`;
  if (sessions.has(key)) return sessions.get(key);
  const account = ACCOUNTS[role];
  const ctx = await browser.newContext({
    ...DEVICES[device],
    ...PLACE,
    locale: "am-ET",
    timezoneId: "Africa/Addis_Ababa",
    colorScheme: "light",
  });
  await ctx.addInitScript(() => {
    try {
      localStorage.setItem("locale", "am");
      localStorage.setItem("ethr.calendar", "ethiopian");
      localStorage.setItem("theme", "light");
    } catch {}
  });
  const page = await ctx.newPage();
  await page.goto(`${WEB}/login`);
  const org = page.getByPlaceholder("acme");
  if (await org.isVisible().catch(() => false)) await org.fill(ORG);
  await page.locator('input[type="email"]').fill(account.email);
  await page.locator('input[type="password"]').fill(account.password);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL((u) => !u.pathname.startsWith("/login"), {
    timeout: 60_000,
  });
  await page.close();
  sessions.set(key, ctx);
  return ctx;
}

async function settle(page) {
  await page
    .waitForLoadState("networkidle", { timeout: 45_000 })
    .catch(() => {});
  await page.addStyleTag({ content: HIDE_DEV });
  await page.evaluate(() => document.fonts.ready);
  // Skeletons and charts finish a beat after the network goes quiet; a dev
  // server compiling a page on first visit can hold them much longer.
  await page
    .waitForFunction(
      () =>
        !document.querySelector(
          '.animate-pulse, [data-slot="skeleton"], [aria-busy="true"]',
        ),
      null,
      { timeout: 60_000 },
    )
    .catch(() => console.warn(`  still loading: ${page.url()}`));
  await page.waitForTimeout(900);
}

async function anonymous(browser, device) {
  const key = `anon:${device}`;
  if (sessions.has(key)) return sessions.get(key);
  const ctx = await browser.newContext({
    ...DEVICES[device],
    ...PLACE,
    locale: "am-ET",
    timezoneId: "Africa/Addis_Ababa",
    colorScheme: "light",
  });
  await ctx.addInitScript(() => {
    try {
      localStorage.setItem("locale", "am");
      localStorage.setItem("ethr.calendar", "ethiopian");
    } catch {}
  });
  sessions.set(key, ctx);
  return ctx;
}

async function take(browser, shot) {
  if (shot.run) {
    await shot.run();
    console.log(`  ${shot.id} · done`);
    return;
  }
  const device = shot.device ?? "desktop";
  const ctx =
    shot.as === "anonymous"
      ? await anonymous(browser, device)
      : await signIn(browser, shot.as, device);
  const page = await ctx.newPage();
  try {
    // A taller screen for a page that scrolls inside the app shell, where a
    // full-page capture would stop at the window's edge.
    if (shot.height)
      await page.setViewportSize({
        width: DEVICES[device].viewport.width,
        height: shot.height,
      });
    await page.goto(`${WEB}${shot.path}`);
    await settle(page);
    if (shot.ready) await shot.ready(page).first().waitFor({ timeout: 60_000 });
    if (shot.prepare) {
      await shot.prepare(page);
      await settle(page);
    }

    // Measure everything from the top of the page: a box measured after a
    // scroll lands somewhere else on a picture taken from the top.
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.waitForTimeout(200);
    const scale = DEVICES[device].deviceScaleFactor;
    let clip = null;
    if (shot.clip) {
      // One locator, or several whose boxes together make the picture.
      const parts = [shot.clip(page)].flat();
      const boxes = (
        await Promise.all(
          parts.map((l) =>
            l
              .first()
              .boundingBox({ timeout: 5000 })
              .catch(() => null),
          ),
        )
      ).filter(Boolean);
      if (boxes.length === 0)
        throw new Error(`${shot.id}: clip target not found`);
      const x1 = Math.min(...boxes.map((b) => b.x));
      const y1 = Math.min(...boxes.map((b) => b.y));
      const x2 = Math.max(...boxes.map((b) => b.x + b.width));
      const y2 = Math.max(...boxes.map((b) => b.y + b.height));
      const box = { x: x1, y: y1, width: x2 - x1, height: y2 - y1 };
      const pad = shot.clipPad ?? 8;
      clip = {
        x: Math.max(0, box.x - pad),
        y: Math.max(0, box.y - pad),
        width: box.width + pad * 2,
        height: box.height + pad * 2,
      };
    }
    const markers = [];
    for (const [index, mk] of (shot.markers ?? []).entries()) {
      // Responsive layouts keep hidden copies of some controls; mark the one shown.
      const target = mk.target(page).filter({ visible: true }).first();
      const box = await target.boundingBox({ timeout: 5000 }).catch(() => null);
      if (!box) {
        console.warn(`  ${shot.id}: marker ${index + 1} not found`);
        continue;
      }
      const ox = clip?.x ?? 0;
      const oy = clip?.y ?? 0;
      const pad = mk.pad ?? 4;
      markers.push({
        n: mk.n ?? index + 1,
        x: Math.round((box.x - ox - pad) * scale),
        y: Math.round((box.y - oy - pad) * scale),
        w: Math.round((box.width + pad * 2) * scale),
        h: Math.round((box.height + pad * 2) * scale),
        ...(mk.ring === false ? { ring: false } : {}),
        ...(mk.pin ? { pin: mk.pin } : {}),
      });
    }
    const png = await page.screenshot({
      clip: clip ?? undefined,
      fullPage: shot.fullPage ?? false,
      animations: "disabled",
    });
    // The PNG header holds the true size, whatever the page reported.
    const size = { width: png.readUInt32BE(16), height: png.readUInt32BE(20) };
    // A target below the fold would put its number outside the picture.
    const outside = markers.filter(
      (m) => m.x + m.w / 2 > size.width || m.y + m.h / 2 > size.height,
    );
    for (const m of outside)
      console.warn(`  ${shot.id}: marker ${m.n} is outside the picture`);
    markers.splice(
      0,
      markers.length,
      ...markers.filter((m) => !outside.includes(m)),
    );
    await writeFile(path.join(shotDir, `${shot.id}.png`), png);
    await writeFile(
      path.join(shotDir, `${shot.id}.json`),
      JSON.stringify({ device, ...size, path: shot.path, markers }, null, 2) +
        "\n",
    );
    console.log(`  ${shot.id} · ${markers.length} markers`);
    if (shot.after) await shot.after(page);
  } finally {
    await page.close();
  }
}

async function survey(browser, role, paths) {
  await mkdir(surveyDir, { recursive: true });
  const device = process.argv.includes("--mobile") ? "mobile" : "desktop";
  const ctx =
    role === "anonymous"
      ? await anonymous(browser, device)
      : await signIn(browser, role, device);
  for (const p of paths) {
    const page = await ctx.newPage();
    await page.goto(`${WEB}${p}`);
    await settle(page);
    const name = `${role}-${device}${p.replace(/[^\w]+/g, "_")}.png`;
    await page.screenshot({ path: path.join(surveyDir, name), fullPage: true });
    // The page's words, cheaper to read than the picture when writing steps.
    const words = await page.evaluate(() =>
      [...document.querySelectorAll("main, nav, header, [role=dialog]")]
        .map((el) => el.innerText)
        .join("\n----\n"),
    );
    await writeFile(
      path.join(surveyDir, name.replace(/\.png$/, ".txt")),
      words,
    );
    console.log(`  ${name}  (${page.url().replace(WEB, "")})`);
    await page.close();
  }
}

const args = process.argv.slice(2);
const surveyRole = args.find((a) => a.startsWith("--survey="))?.split("=")[1];
const guide = args.find((a) => a.startsWith("--guide="))?.split("=")[1];
const names = args.filter((a) => !a.startsWith("--"));

// The bundled browser when it is installed, otherwise the Chrome on this machine.
const browser = await chromium
  .launch()
  .catch(() => chromium.launch({ channel: "chrome" }));
let failed = 0;
try {
  if (surveyRole) {
    await survey(browser, surveyRole, names);
  } else {
    await mkdir(shotDir, { recursive: true });
    const list = SHOTS.filter((s) =>
      names.length ? names.includes(s.id) : true,
    ).filter((s) => (guide ? s.guide === guide : true));
    for (const shot of list) {
      try {
        await take(browser, shot);
      } catch (e) {
        failed++;
        console.error(`  ${shot.id} FAILED: ${e.message.split("\n")[0]}`);
      }
    }
  }
} finally {
  for (const ctx of sessions.values()) await ctx.close();
  await browser.close();
}
if (failed) process.exit(1);
