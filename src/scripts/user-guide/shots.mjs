/**
 * The screenshots the Amharic guides use. Each shot names who takes it, the
 * page, what to do there first, and the controls its numbered markers point
 * at, in the order the guide's steps mention them. See capture.mjs.
 *
 * Targets are found by their Amharic text or accessible name, so a renamed
 * label shows up as a "marker not found" warning on the next capture.
 */

import { execFileSync } from "node:child_process";
import path from "node:path";
import { fileURLToPath } from "node:url";

const API =
  process.env.GUIDE_API_ROOT ??
  path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../../../api");

/**
 * Runs the queue once, as the production cron caller would, so the
 * notifications a decision sends have arrived before the next picture.
 */
function drainQueue() {
  execFileSync(
    "php",
    [
      "artisan",
      "queue:work",
      "--queue=attendance,notifications,default,exports",
      "--stop-when-empty",
    ],
    {
      cwd: API,
      env: {
        ...process.env,
        DB_DATABASE: process.env.GUIDE_DB ?? "ethr_guide",
      },
      stdio: "ignore",
    },
  );
}

export const ACCOUNTS = {
  employee: { email: "selam.tesfaye@sample.ethr.et", password: "password" },
  supervisor: { email: "dawit.mengistu@sample.ethr.et", password: "password" },
  hr: { email: "hirut.alemu@sample.ethr.et", password: "password" },
  admin: { email: "meron.kebede@sample.ethr.et", password: "password" },
};

const header = (page) => page.locator("header").first();
const headerButton = (name) => (page) =>
  header(page).getByRole("button", { name });
const button =
  (name, opts = {}) =>
  (page) =>
    page.getByRole("button", { name, ...opts });
const link =
  (name, opts = {}) =>
  (page) =>
    page.getByRole("link", { name, ...opts });
const text =
  (value, opts = {}) =>
  (page) =>
    page.getByText(value, opts);
const tab = (name) => (page) =>
  page
    .getByRole("tab", { name })
    .or(page.locator("main").getByRole("link", { name, exact: true }))
    .first();
const dialog = (page) => page.getByRole("dialog").last();
const popover = (page) =>
  page
    .locator(
      '[role="menu"], [role="dialog"], [data-radix-popper-content-wrapper]',
    )
    .last();

/** Chooses an option in one of the app's dropdowns (a button over a hidden select). */
async function pick(page, combobox, option) {
  await combobox.click();
  await page.getByRole("option", { name: option, exact: true }).first().click();
  await page.waitForTimeout(250);
}

/** Fills an Ethiopian date picker: year box, month and day dropdowns. */
async function ethDate(page, scope, index, [year, month, day]) {
  await scope.getByRole("spinbutton").nth(index).fill(String(year));
  await pick(
    page,
    scope.getByRole("combobox", { name: "የኢትዮጵያ ወር" }).nth(index),
    month,
  );
  await pick(
    page,
    scope.getByRole("combobox", { name: "የኢትዮጵያ ቀን" }).nth(index),
    String(day),
  );
}

const open = (name) => async (page) => {
  await page.getByRole("button", { name }).first().click();
  await page.waitForTimeout(600);
};

export const SHOTS = [
  // ------------------------------------------------------------ employee
  {
    id: "emp-login",
    guide: "employee",
    as: "anonymous",
    path: "/login",
    prepare: async (page) => {
      const org = page.getByPlaceholder("acme");
      if (await org.isVisible().catch(() => false)) await org.fill("namuna");
      await page
        .locator('input[type="email"]')
        .fill("selam.tesfaye@sample.ethr.et");
      await page.locator('input[type="password"]').fill("password");
    },
    markers: [
      { target: (page) => page.getByPlaceholder("acme") },
      { target: (page) => page.locator('input[type="email"]') },
      { target: (page) => page.locator('input[type="password"]') },
      { target: (page) => page.locator('button[type="submit"]') },
      { target: link("የይለፍ ቃል ረሳኽ?") },
      { target: link("በአንድ ጊዜ ኮድ ይግቡ") },
    ],
  },
  {
    id: "emp-dashboard",
    guide: "employee",
    as: "employee",
    path: "/dashboard",
    markers: [
      {
        target: (page) =>
          page
            .locator("aside, [data-sidebar='sidebar']")
            .first()
            .locator("nav")
            .first(),
      },
      {
        target: (page) =>
          page
            .locator("main")
            .getByText("ግባ", { exact: true })
            .first()
            .locator("xpath=ancestor-or-self::*[self::button or self::a][1]"),
        pin: "below",
      },
      {
        target: (page) =>
          page
            .getByRole("button", { name: "ፈቃድ ጠይቅ" })
            .or(page.getByRole("link", { name: "ፈቃድ ጠይቅ" }))
            .last(),
        pin: "below",
      },
      {
        target: (page) =>
          page.locator("main").getByText("የእኔ ቀን", { exact: true }).first(),
      },
      {
        target: (page) =>
          page.locator("main").getByText("ቀጣይ እርምጃዎች", { exact: true }).first(),
      },
    ],
  },
  {
    id: "emp-header",
    guide: "employee",
    as: "employee",
    path: "/dashboard",
    clip: (page) => [
      header(page).getByText("ሰዎችን፣ መገኘትን፣ ፈቃድንና ገጾችን ይፈልጉ"),
      header(page).getByRole("button", { name: "የመለያ ምናሌ" }),
    ],
    clipPad: 22,
    markers: [
      {
        target: (page) =>
          header(page).getByText("ሰዎችን፣ መገኘትን፣ ፈቃድንና ገጾችን ይፈልጉ"),
        pin: "below",
      },
      { target: headerButton("ቋንቋ እና የቀን መቁጠሪያ"), pin: "below" },
      { target: headerButton("እርዳታ ያግኙ"), pin: "below" },
      { target: headerButton("ማሳወቂያዎች"), pin: "below" },
      { target: headerButton("የመለያ ምናሌ"), pin: "below" },
    ],
  },
  {
    id: "emp-language-menu",
    guide: "employee",
    as: "employee",
    path: "/dashboard",
    prepare: open("ቋንቋ እና የቀን መቁጠሪያ"),
    clip: popover,
    clipPad: 10,
    markers: [
      { target: (page) => popover(page).getByText("English", { exact: true }) },
      { target: (page) => popover(page).getByText("ኢትዮጵያዊ").first() },
    ],
  },
  {
    id: "emp-account-menu",
    guide: "employee",
    as: "employee",
    path: "/dashboard",
    prepare: open("የመለያ ምናሌ"),
    clip: popover,
    clipPad: 10,
    markers: [],
  },
  {
    id: "emp-mobile-home",
    guide: "employee",
    as: "employee",
    device: "mobile",
    path: "/dashboard",
    markers: [
      {
        target: (page) => page.locator("nav").last().getByText("መነሻ"),
        pin: "above",
      },
      {
        target: (page) => page.locator("nav").last().getByText("መገኘት"),
        pin: "above",
      },
      {
        target: (page) => page.locator("nav").last().getByText("ፈቃድ"),
        pin: "above",
      },
      {
        target: (page) => page.locator("nav").last().getByText("ደሞዝ ወረቀቶቼ"),
        pin: "above",
      },
      {
        target: (page) => page.locator("nav").last().getByText("ተጨማሪ"),
        pin: "above",
      },
    ],
  },
  {
    id: "emp-mobile-checkin",
    guide: "employee",
    as: "employee",
    device: "mobile",
    path: "/attendance/mobile",
    height: 1240,
    markers: [
      {
        target: (page) =>
          page.locator("main").getByRole("button", { name: "ግባ", exact: true }),
      },
      { target: text("ራስፎቶ") },
      { target: text("የምዝገባ ረዳት") },
      { target: text("የእኔ ታሪክ") },
    ],
  },
  {
    id: "emp-attendance",
    guide: "employee",
    as: "employee",
    path: "/attendance",
    markers: [
      {
        target: (page) =>
          page.locator("main").getByRole("button", { name: "ግባ", exact: true }),
      },
      { target: text("QR ቃኝ") },
      { target: text("የሞባይል ግብዓት") },
      {
        target: (page) =>
          page.locator("main").getByText("ማስተካከያዎች", { exact: true }),
      },
      { target: button("ማጣሪያዎች") },
      { target: (page) => page.locator("main table tbody tr").first() },
    ],
  },
  {
    id: "emp-correction-form",
    guide: "employee",
    as: "employee",
    path: "/attendance/corrections",
    prepare: async (page) => {
      await open("ማስተካከያ ይጠይቁ")(page);
      const d = dialog(page);
      await ethDate(page, d, 0, [2019, "ጥቅምት", 17]);
      const times = d.locator('input[type="time"]');
      await times.nth(0).fill("08:25");
      await times.nth(1).fill("17:35");
      await d
        .locator("textarea")
        .fill("የጣት አሻራ መሣሪያው ጠዋት አልሠራም፤ 08:25 ላይ ደርሼ ነበር። የክፍሉ ኃላፊ ያውቃሉ።");
    },
    clip: dialog,
    markers: [
      { target: (page) => dialog(page).getByRole("spinbutton").first() },
      { target: (page) => dialog(page).locator('input[type="time"]').first() },
      { target: (page) => dialog(page).locator("textarea") },
      {
        target: (page) =>
          dialog(page).getByRole("button", { name: "ጥያቄ አስገባ" }),
      },
    ],
    after: async (page) => {
      await dialog(page).getByRole("button", { name: "ጥያቄ አስገባ" }).click();
      await page.waitForTimeout(2000);
    },
  },
  {
    id: "emp-leave",
    guide: "employee",
    as: "employee",
    path: "/leave",
    markers: [
      {
        target: (page) =>
          page.locator("main").getByRole("button", { name: "ፈቃድ ጠይቅ" }),
      },
      { target: (page) => page.locator("main").getByText("የዓመት ፈቃድ").first() },
      { target: (page) => page.locator("main table tbody tr").first() },
      {
        target: (page) =>
          page
            .locator("main table tbody tr")
            .first()
            .getByRole("button")
            .last(),
      },
    ],
  },
  {
    id: "emp-leave-form",
    guide: "employee",
    as: "employee",
    path: "/leave",
    prepare: async (page) => {
      await page
        .locator("main")
        .getByRole("button", { name: "ፈቃድ ጠይቅ" })
        .click();
      await page.waitForTimeout(800);
      const d = dialog(page);
      await pick(page, d.getByRole("combobox").first(), "የዓመት ፈቃድ");
      await ethDate(page, d, 0, [2019, "ኅዳር", 23]);
      await ethDate(page, d, 1, [2019, "ኅዳር", 25]);
      await d.locator("textarea").fill("የቤተሰብ ጉዳይ");
    },
    clip: dialog,
    markers: [
      { target: (page) => dialog(page).getByRole("combobox").first() },
      { target: (page) => dialog(page).getByRole("spinbutton").nth(0) },
      { target: (page) => dialog(page).getByRole("spinbutton").nth(1) },
      { target: (page) => dialog(page).locator("textarea") },
      { target: (page) => dialog(page).getByRole("button", { name: "ላክ" }) },
    ],
    after: async (page) => {
      await dialog(page).getByRole("button", { name: "ላክ" }).click();
      await page.waitForTimeout(2000);
    },
  },
  {
    id: "emp-payslips",
    guide: "employee",
    as: "employee",
    path: "/payroll/payslips",
    ready: (page) => page.locator("main").getByText("ተጣራ ክፍያ"),
    markers: [
      { target: (page) => page.locator("main").getByText("ተጣራ ክፍያ").first() },
      {
        target: (page) =>
          page
            .locator("main")
            .getByText("ተጣራ ክፍያ")
            .first()
            .locator("xpath=ancestor::*[.//button][1]")
            .getByRole("button")
            .first(),
        pin: "below",
      },
    ],
  },
  {
    id: "emp-profile",
    guide: "employee",
    as: "employee",
    path: "/profile",
    markers: [
      { target: tab("አጠቃላይ እይታ"), pin: "below" },
      { target: tab("የግል ዝርዝሮች"), pin: "below" },
      { target: tab("የለውጥ ጥያቄዎች"), pin: "below" },
      { target: tab("ሰነዶች"), pin: "below" },
      { target: tab("ደህንነት"), pin: "below" },
      { target: tab("ምርጫዎች"), pin: "below" },
    ],
  },
  {
    id: "emp-profile-personal",
    guide: "employee",
    as: "employee",
    path: "/profile/personal",
    markers: [
      { target: text("ስልክ ቁጥር", { exact: true }) },
      { target: button("ለውጦችን አስቀምጥ") },
      { target: button("አድራሻ ጨምር") },
    ],
  },
  {
    id: "emp-profile-requests",
    guide: "employee",
    as: "employee",
    path: "/profile/requests",
    fullPage: true,
    markers: [
      { target: text("ሕጋዊ ስም", { exact: true }) },
      { target: text("የባንክ ሒሳብ ቁጥር", { exact: true }) },
      { target: button("ለግምገማ ላክ") },
    ],
  },
  {
    id: "emp-security",
    guide: "employee",
    as: "employee",
    path: "/profile/security",
    markers: [
      { target: button("MFA አንቃ") },
      { target: button("የይለፍ ቃል ቀይር") },
      { target: text("ንቁ ክፍለ ጊዜዎች", { exact: true }) },
    ],
  },
  {
    id: "emp-preferences",
    guide: "employee",
    as: "employee",
    path: "/profile/preferences",
    fullPage: true,
    markers: [
      { target: text("የመተግበሪያ ቋንቋ", { exact: true }) },
      { target: text("የቀን አቀራረብ", { exact: true }) },
      { target: button("እስማማለሁ") },
    ],
  },
  {
    id: "emp-notification-prefs",
    guide: "employee",
    as: "employee",
    path: "/notifications/preferences",
    markers: [{ target: button("ለውጦችን አስቀምጥ") }],
  },
  {
    id: "emp-help-dialog",
    guide: "employee",
    as: "employee",
    path: "/support",
    prepare: async (page) => {
      await page
        .locator("main")
        .getByRole("button", { name: "እርዳታ ያግኙ" })
        .first()
        .click();
      await page.waitForTimeout(800);
      const d = dialog(page);
      await d.getByRole("combobox").first().click();
      await page.getByRole("option").first().click();
      await d
        .locator("textarea")
        .fill("የሞባይል ግብዓት ሳደርግ «አካባቢ ያስፈልጋል» ይለኛል። ስልኬ GPS አብርቷል።");
    },
    clip: dialog,
    markers: [
      { target: (page) => dialog(page).getByRole("combobox").first() },
      { target: (page) => dialog(page).locator("textarea") },
      { target: (page) => dialog(page).getByRole("switch").first() },
      { target: (page) => dialog(page).getByRole("button", { name: "ላክ" }) },
    ],
  },

  // ------------------------------------------------------------ manager
  {
    id: "sup-dashboard",
    guide: "manager",
    as: "supervisor",
    path: "/dashboard",
    markers: [
      {
        target: (page) =>
          page
            .locator("aside, [data-sidebar='sidebar']")
            .first()
            .getByRole("link", { name: /ማጽደቂያዎች/ }),
      },
      {
        target: (page) =>
          page
            .locator("main")
            .getByText("ዛሬ የገቡ", { exact: true })
            .locator("xpath=ancestor::*[contains(@class,'rounded')][1]"),
      },
      {
        target: (page) =>
          page
            .getByRole("button", { name: "ፈቃዶችን ገምግም" })
            .or(page.getByRole("link", { name: "ፈቃዶችን ገምግም" }))
            .first(),
        pin: "below",
      },
      {
        target: (page) =>
          page
            .getByRole("button", { name: "ወስን" })
            .or(page.getByRole("link", { name: "ወስን" }))
            .first(),
      },
    ],
  },
  {
    id: "sup-approvals",
    guide: "manager",
    as: "supervisor",
    path: "/approvals",
    markers: [
      { target: (page) => page.locator("main").getByRole("combobox").first() },
      { target: (page) => page.locator("main").getByRole("checkbox").first() },
      { target: (page) => page.locator("main").getByRole("checkbox").nth(1) },
      {
        target: (page) =>
          page
            .locator("main")
            .getByRole("button", { name: /ፍቀድ|አጽድቅ/ })
            .first(),
        pin: "below",
      },
      {
        target: (page) =>
          page
            .locator("main")
            .getByRole("button", { name: /ውድቅ|ተቀበል|አትቀበል/ })
            .first(),
        pin: "below",
      },
    ],
  },
  {
    id: "sup-reject-dialog",
    guide: "manager",
    as: "supervisor",
    path: "/approvals",
    prepare: async (page) => {
      await page
        .locator("main")
        .getByRole("button", { name: /ውድቅ|ተቀበል|አትቀበል/ })
        .first()
        .click();
      await page.waitForTimeout(800);
      await dialog(page)
        .locator("textarea")
        .fill("በዚህ ሳምንት የቡድኑ ሦስት አባላት በፈቃድ ላይ ናቸው። እባክዎ ቀኑን ወደ ቀጣዩ ሳምንት ያዙሩት።");
    },
    clip: dialog,
    markers: [{ target: (page) => dialog(page).locator("textarea") }],
    // Then the supervisor decides two requests, so the employee has
    // something in their notifications.
    after: async (page) => {
      await page.keyboard.press("Escape");
      await page.waitForTimeout(500);
      for (let i = 0; i < 2; i++) {
        const approve = page
          .locator("main")
          .getByRole("button", { name: /ፍቀድ|አጽድቅ/ })
          .first();
        if (!(await approve.isVisible().catch(() => false))) break;
        await approve.click();
        await page.waitForTimeout(800);
        const confirm = page
          .getByRole("dialog")
          .getByRole("button", { name: /ፍቀድ|አጽድቅ|አረጋግጥ/ })
          .last();
        if (await confirm.isVisible().catch(() => false)) await confirm.click();
        await page.waitForTimeout(2000);
      }
    },
  },
  {
    id: "sup-decide",
    guide: "manager",
    run: drainQueue,
  },
  {
    id: "sup-team-attendance",
    guide: "manager",
    as: "supervisor",
    path: "/attendance/team",
    prepare: async (page) => {
      await pick(
        page,
        page
          .locator("main")
          .getByRole("combobox", { name: "የኢትዮጵያ ቀን" })
          .first(),
        "20",
      );
    },
    markers: [
      {
        target: (page) => page.locator("main").getByRole("spinbutton").first(),
      },
      {
        target: (page) => page.locator("main").getByRole("button").first(),
        pin: "below",
      },
      { target: (page) => page.locator("main table tbody tr").first() },
    ],
  },
  {
    id: "sup-team-leave",
    guide: "manager",
    as: "supervisor",
    path: "/leave",
    prepare: async (page) => {
      await page.locator("main").getByText("የቡድን ፈቃድ", { exact: true }).click();
      await page.waitForTimeout(800);
    },
    markers: [
      {
        target: (page) =>
          page.locator("main").getByText("የቡድን ፈቃድ", { exact: true }),
        pin: "below",
      },
    ],
  },
  {
    id: "sup-overtime-requests",
    guide: "manager",
    as: "supervisor",
    path: "/attendance/overtime-requests",
    markers: [
      {
        target: (page) =>
          page.locator("main").locator('input[type="date"], input').first(),
      },
      { target: (page) => page.locator("main").getByRole("combobox").first() },
      { target: (page) => page.locator("main").locator("textarea").first() },
      {
        target: (page) =>
          page.locator("main").getByRole("button", { name: "ጥያቄውን ላክ" }),
        pin: "below",
      },
    ],
  },
  {
    id: "sup-analytics",
    guide: "manager",
    as: "supervisor",
    path: "/analytics",
    markers: [],
  },

  // ------------------------------------------------------------ admin
  {
    id: "adm-setup",
    guide: "admin",
    as: "admin",
    path: "/setup/guided",
    markers: [
      {
        target: (page) =>
          page.locator("main").getByText("ድርጅት", { exact: true }).first(),
        pin: "below",
      },
      {
        target: (page) =>
          page.locator("main").getByText("ሠራተኞች", { exact: true }).first(),
        pin: "below",
      },
      {
        target: (page) =>
          page.locator("main").getByText("መግቢያ", { exact: true }).first(),
        pin: "below",
      },
      {
        target: (page) =>
          page.locator("main").getByText("አስጀምር", { exact: true }).first(),
        pin: "below",
      },
    ],
  },
  {
    id: "adm-organization",
    guide: "admin",
    as: "admin",
    path: "/organization",
    markers: [
      { target: tab("አወቃቀር"), pin: "below" },
      { target: tab("ቅርንጫፎች"), pin: "below" },
      { target: tab("ክፍሎች"), pin: "below" },
      { target: tab("የስራ መደቦች"), pin: "below" },
      { target: tab("ደረጃዎች"), pin: "below" },
    ],
  },
  {
    id: "adm-employees",
    guide: "admin",
    as: "admin",
    path: "/employees",
    markers: [
      {
        target: (page) =>
          page
            .locator("main")
            .getByRole("button", { name: "ሰራተኛ ጨምር" })
            .or(page.locator("main").getByRole("link", { name: "ሰራተኛ ጨምር" })),
        pin: "below",
      },
      { target: (page) => page.locator("main").getByText("ሁሉም ሁኔታዎች").first() },
      { target: (page) => page.locator("main table tbody tr").first() },
    ],
  },
  {
    id: "adm-employee-new",
    guide: "admin",
    as: "admin",
    path: "/employees/new",
    markers: [
      {
        target: (page) =>
          page.locator("main").getByText("ግለሰብ", { exact: true }).first(),
      },
      {
        target: (page) =>
          page.locator("main").getByText("አድራሻና መግቢያ", { exact: true }).first(),
      },
      {
        target: (page) =>
          page.locator("main").getByText("ሥራ", { exact: true }).first(),
      },
      {
        target: (page) =>
          page.locator("main").getByText("ክፍያና መታወቂያ", { exact: true }).first(),
      },
    ],
  },
  {
    id: "adm-employee-import",
    guide: "admin",
    as: "admin",
    path: "/employees/import",
    markers: [
      { target: text("የCSV ፋይልዎን እዚህ ይጣሉ ወይም") },
      {
        target: (page) =>
          page
            .getByRole("button", { name: "CSV አብነት አውርድ" })
            .or(page.getByRole("link", { name: "CSV አብነት አውርድ" })),
      },
    ],
  },
  {
    id: "adm-employee-record",
    guide: "admin",
    as: "admin",
    path: "/employees",
    prepare: async (page) => {
      await page
        .locator("main table tbody tr")
        .first()
        .getByRole("link")
        .first()
        .click();
      await page.waitForURL(/\/employees\/[^/]+$/);
    },
    markers: [
      {
        target: (page) =>
          page.locator("main").getByRole("button", { name: "ወደ ETHR ጋብዝ" }),
        pin: "below",
      },
      {
        target: (page) =>
          page.locator("main").getByRole("button", { name: "አርም" }).first(),
        pin: "below",
      },
      {
        target: (page) =>
          page.locator("main").getByText("የሕይወት ዑደት", { exact: true }).first(),
        pin: "below",
      },
      {
        target: (page) =>
          page.locator("main").getByText("ታሪክ", { exact: true }).first(),
        pin: "below",
      },
    ],
  },
  {
    id: "adm-users",
    guide: "admin",
    as: "admin",
    path: "/settings/users",
    markers: [
      {
        target: (page) =>
          page.locator("main").getByRole("button", { name: "ተጠቃሚ ጋብዝ" }),
        pin: "below",
      },
      { target: (page) => page.locator("main table thead th").nth(2) },
    ],
  },
  {
    id: "adm-invite-dialog",
    guide: "admin",
    as: "admin",
    path: "/settings/users",
    prepare: async (page) => {
      await page
        .locator("main")
        .getByRole("button", { name: "ተጠቃሚ ጋብዝ" })
        .click();
      await page.waitForTimeout(800);
    },
    clip: dialog,
    markers: [],
  },
  {
    id: "adm-attendance-settings",
    guide: "admin",
    as: "admin",
    path: "/settings/attendance",
    markers: [
      { target: text("የመገኘት ዝግጅት", { exact: true }) },
      { target: text("የነቁ የመገኘት ዘዴዎች", { exact: true }) },
      {
        target: (page) =>
          page.locator("main").getByRole("button", { name: "ለውጦችን አስቀምጥ" }),
        pin: "below",
      },
    ],
  },
  {
    id: "adm-shifts",
    guide: "admin",
    as: "admin",
    path: "/shifts",
    markers: [
      {
        target: (page) =>
          page
            .locator("main")
            .getByRole("button", { name: "አዲስ ፈረቃ" })
            .or(page.locator("main").getByRole("link", { name: "አዲስ ፈረቃ" })),
        pin: "below",
      },
      {
        target: (page) =>
          page.locator("main").getByText("ምደባዎች", { exact: true }).first(),
        pin: "below",
      },
      {
        target: (page) =>
          page.locator("main").getByText("ዙር ፈረቃዎች", { exact: true }).first(),
        pin: "below",
      },
      {
        target: (page) =>
          page.locator("main").getByText("መደበኛ", { exact: true }).first(),
      },
    ],
  },
  {
    id: "adm-phones",
    guide: "admin",
    as: "admin",
    path: "/attendance/devices",
    markers: [],
  },
  {
    id: "adm-leave-types",
    guide: "admin",
    as: "admin",
    path: "/settings/leave-types",
    markers: [
      { target: text("የሥራ ሳምንት", { exact: true }) },
      {
        target: (page) =>
          page.locator("main").getByRole("button", { name: "የፈቃድ አይነት ጨምር" }),
        pin: "below",
      },
    ],
  },
  {
    id: "adm-holidays",
    guide: "admin",
    as: "admin",
    path: "/settings/holidays",
    markers: [
      {
        target: (page) =>
          page
            .locator("main")
            .getByRole("button", { name: "የኢትዮጵያ በዓላትን በራስ-ሰር ለይ" }),
        pin: "below",
      },
      {
        target: (page) =>
          page.locator("main").getByRole("button", { name: "በዓል ጨምር" }),
        pin: "below",
      },
    ],
  },
  {
    id: "adm-approval-chains",
    guide: "admin",
    as: "admin",
    path: "/settings/approval-chains",
    markers: [
      {
        target: (page) =>
          page
            .locator("main")
            .getByRole("button", { name: "አዲስ ሰንሰለት" })
            .first(),
        pin: "below",
      },
    ],
  },
  {
    id: "adm-payroll",
    guide: "admin",
    as: "admin",
    path: "/payroll",
    markers: [
      {
        target: (page) =>
          page
            .locator("main")
            .getByRole("button", { name: "ደመወዝ አስሂድ" })
            .or(page.locator("main").getByRole("link", { name: "ደመወዝ አስሂድ" })),
        pin: "below",
      },
      { target: text("ማጽደቅ በመጠበቅ ላይ") },
    ],
  },
  {
    id: "adm-payroll-run",
    guide: "admin",
    as: "admin",
    path: "/payroll",
    prepare: async (page) => {
      await page.locator("main").getByText("ማጽደቅ በመጠበቅ ላይ").first().click();
      await page.waitForURL(/\/payroll\/[^/]+$/).catch(() => {});
    },
    // The run's header and its four steps; the absence list below them runs
    // to a page of its own with the demo data.
    clip: (page) => [
      page.locator("main h1").first(),
      page.locator("main").getByText(/^ማስላት/).first(),
      page.locator("main").getByRole("button", { name: "ወጪ ያውጡ" }),
      page
        .locator("main")
        .getByText(/^ተከፍሏል/)
        .first(),
    ],
    clipPad: 16,
    markers: [
      {
        target: (page) =>
          page
            .locator("main")
            .getByText(/^መገምገም/)
            .first(),
      },
      {
        target: (page) =>
          page.locator("main").getByRole("button", { name: "ደመወዝ አጽድቅ" }),
        pin: "below",
      },
      {
        target: (page) =>
          page.locator("main").getByRole("button", { name: "ወጪ ያውጡ" }),
        pin: "below",
      },
    ],
  },
  {
    id: "adm-payroll-settings",
    guide: "admin",
    as: "admin",
    path: "/settings/payroll",
    markers: [],
  },
  {
    id: "adm-reports",
    guide: "admin",
    as: "admin",
    path: "/reports",
    height: 1150,
    markers: [
      {
        target: (page) =>
          page.locator("main").getByText("ገንቢ", { exact: true }).first(),
        pin: "below",
      },
      {
        target: (page) =>
          page.locator("main").getByText("የውሂብ ምንጭ", { exact: true }).first(),
      },
      {
        target: (page) => page.locator("main").getByText("አምዶች").first(),
      },
      {
        target: (page) =>
          page.locator("main").getByText("ማጣሪያዎች", { exact: true }).first(),
      },
    ],
  },
  {
    id: "adm-announcement",
    guide: "admin",
    as: "admin",
    path: "/announcements",
    prepare: async (page) => {
      await page
        .locator("main")
        .getByRole("button", { name: "አዲስ ማስታወቂያ" })
        .first()
        .click();
      await page.waitForTimeout(800);
    },
    clip: dialog,
    markers: [],
  },
  {
    id: "adm-settings",
    guide: "admin",
    as: "admin",
    path: "/settings",
    markers: [],
  },

  // ------------------------------------------------------------ after the manager decides
  {
    id: "emp-notifications-menu",
    guide: "employee",
    as: "employee",
    path: "/dashboard",
    prepare: async (page) => {
      await header(page).getByRole("button", { name: "ማሳወቂያዎች" }).click();
      await page.waitForTimeout(800);
    },
    clip: popover,
    clipPad: 10,
    markers: [],
  },
];
