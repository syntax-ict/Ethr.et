// Phone-width layout detector for the browser pane (audit N36).
//
// Paste the whole file into the browser tool's JavaScript call, on a page
// already at the width you want to test (resize the viewport first, e.g.
// 375x812). It waits for the page to settle, checks itself against planted
// defects, then reports. One call per page; no screenshots needed.
//
// Returns:
//   selfTest   "ok", or which planted defect it failed to catch. If not "ok",
//              do not trust the rest of the result.
//   offScreen  outermost elements past either viewport edge, unless inside an
//              in-bounds box that scrolls or clips (a wide table in an
//              overflow-x-auto wrapper is fine). Left-edge overflow matters
//              most: it cannot be scrolled to.
//   pageScroll how far <main> scrolls sideways. The only check that catches
//              overflowing *text*, which has no element box of its own.
//   spills     controls that stick out of their own parent by more than 2px
//              while staying on screen, e.g. a select squeezed out of a
//              fixed-width box. Invisible to the two checks above.
//
// The app sends X-Frame-Options: DENY, so it cannot be swept in iframes:
// navigate to each page and run this once per page.
await (async () => {
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  await sleep(2500);
  for (let i = 0; i < 24; i++) {
    if (!document.querySelector('.animate-pulse, [aria-busy="true"]')) break;
    await sleep(500);
  }
  await sleep(600);

  const vw = innerWidth;
  const inView = (b) => b.left >= -1 && b.right <= vw + 1;
  const clips = (el) => /(auto|scroll|hidden|clip)/.test(getComputedStyle(el).overflowX);
  const insideClipBox = (el) => {
    for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
      if (p.tagName === "MAIN") return false;
      if (clips(p) && inView(p.getBoundingClientRect())) return true;
    }
    return false;
  };
  const describe = (el, b) =>
    `${el.tagName.toLowerCase()}.${String(el.className).split(" ").slice(0, 4).join(".")} ` +
    `[${Math.round(b.left)}..${Math.round(b.right)}] "${(el.innerText || el.value || "").trim().slice(0, 30).replace(/\n/g, " ")}"`;

  function scan(root) {
    const offScreen = [];
    const spills = [];
    for (const el of root.querySelectorAll("*")) {
      const b = el.getBoundingClientRect();
      if (!b.width || !b.height) continue;
      if (!inView(b) && !insideClipBox(el)) {
        const p = el.parentElement;
        const parentAlsoOff = p && !inView(p.getBoundingClientRect()) && !insideClipBox(p);
        if (!parentAlsoOff) offScreen.push({ el, text: describe(el, b) });
      }
      if (el.matches('input:not([type=hidden]), button, select, [role=combobox]')) {
        const p = el.parentElement;
        if (!p || clips(p) || getComputedStyle(el).position === "absolute") continue;
        const pb = p.getBoundingClientRect();
        const by = Math.max(pb.left - b.left, b.right - pb.right);
        if (by > 2) spills.push({ el, text: `${describe(el, b)} out of its box by ${Math.round(by)}px` });
      }
    }
    return { offScreen, spills };
  }

  // Self-test: plant the three defect shapes this detector exists for.
  const host = document.querySelector("main") ?? document.body;
  const probes = document.createElement("div");
  probes.innerHTML =
    '<div style="display:flex;justify-content:flex-end"><div data-probe="left" style="display:flex;flex-shrink:0"><b style="width:600px;display:block">x</b></div></div>' +
    '<div data-probe="wide" style="width:900px">x</div>' +
    '<div style="width:100px;display:flex"><button data-probe="spill" style="width:180px;flex-shrink:0">x</button></div>';
  host.prepend(probes);
  const planted = scan(host);
  const found = [...planted.offScreen, ...planted.spills].map((hit) => hit.el);
  const missed = ["left", "wide", "spill"].filter(
    (name) => !found.includes(probes.querySelector(`[data-probe=${name}]`)),
  );
  probes.remove();

  const { offScreen, spills } = scan(document.body);
  const main = document.querySelector("main");
  return {
    path: location.pathname,
    viewport: `${vw}x${innerHeight}`,
    selfTest: missed.length ? `MISSED ${missed.join(", ")}` : "ok",
    pageScroll: main ? main.scrollWidth - main.clientWidth : null,
    offScreen: offScreen.slice(0, 6).map((hit) => hit.text),
    spills: spills.slice(0, 6).map((hit) => hit.text),
  };
})();
