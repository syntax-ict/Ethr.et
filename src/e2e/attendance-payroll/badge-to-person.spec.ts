import { expect, test, type Page } from "@playwright/test";
import { api } from "../helpers";
import {
  addisDate,
  as,
  deviceId,
  heldBadges,
  live,
  minutesAgo,
  pushTerminalPunch,
  PUSH_TERMINAL,
  recordsOf,
} from "./support";

/**
 * From a badge a push-only terminal sent to a person, with its punches
 * (#707, #708, #710). The reference organisation runs a Hikvision terminal
 * that only sends to ETHR: ETHR cannot ask it who is enrolled, so the one
 * thing it learns about a newcomer is a badge nobody has. These journeys
 * post that badge the way the firmware does, and end on what the API reads
 * back: the employee, the attendance the held punches became, and the held
 * list emptied. A toast is not an assertion.
 *
 * The `mock` adapter cannot stand in: the webhook routes are only hikvision,
 * zkteco and suprema, and the push-only rule (`DeviceManager::receivesOnly`)
 * is a Hikvision with no address.
 */

type EmployeeRow = {
  public_id: string;
  name: string;
  badge_number: string | null;
  hire_date: string;
};

async function employeeNamed(page: Page, name: string): Promise<EmployeeRow> {
  const found = await api(
    page,
    "GET",
    `/employees?search=${encodeURIComponent(name)}`,
  );
  expect(found.status, `employee search for ${name}`).toBe(200);
  const match = (found.data.data as EmployeeRow[]).find(
    (row) => row.name === name,
  );
  expect(match, `employee ${name}`).toBeTruthy();

  return match!;
}

const today = () => ({
  from: addisDate(minutesAgo(1560)),
  to: addisDate(new Date()),
});

test("a badge nobody has becomes a new employee, and its held punch becomes their attendance", async ({
  browser,
  request,
}) => {
  const arrived = minutesAgo(120);
  expect(await pushTerminalPunch(request, "7777", "check_in", arrived)).toBe(
    "employee_not_found",
  );

  const admin = await as(browser, "e2e-att", "admin");

  try {
    // Held, and the server says nobody has it.
    const held = (await heldBadges(admin.page)).find(
      (row) => row.badge === "7777",
    );
    expect(held?.punches).toBe(1);
    expect(held?.match?.outcome).toBe("new");

    await admin.page.goto("/devices");
    const row = admin.page.locator("li", { hasText: "Badge 7777" });
    await expect(row).toBeVisible({ timeout: 30000 });
    await row.getByRole("link", { name: "Add as a new employee" }).click();

    // The form arrives with the badge, and says what saving does.
    await admin.page.waitForURL(/\/employees\/new\?/);
    await expect(
      admin.page.getByText(`Badge 7777 from ${PUSH_TERMINAL.name}`),
    ).toBeVisible({ timeout: 30000 });
    await expect(admin.page.locator("#badge_number")).toHaveValue("7777");

    await admin.page.getByLabel(/Full Name/).fill("Kidist Alemayehu");

    const replayed = admin.page.waitForResponse(
      (response) =>
        response.url().includes("/devices/unmatched-punches/assign") &&
        response.request().method() === "POST",
    );
    await admin.page.getByRole("button", { name: "Save employee" }).click();
    expect((await replayed).status()).toBe(200);
    await admin.page.waitForURL(/\/employees\/[0-9A-Z]{26}$/, {
      timeout: 30000,
    });

    // What the database holds.
    const person = await employeeNamed(admin.page, "Kidist Alemayehu");
    expect(person.badge_number).toBe("7777");
    // The day the badge was first held, not whatever day the form was filled.
    expect(person.hire_date.slice(0, 10)).toBe(addisDate(new Date()));

    const rows = live(
      await recordsOf(admin.page, person.public_id, today().from, today().to),
    );
    expect(rows).toHaveLength(1);
    expect(rows[0].source).toBe("biometric");
    // The terminal dated the arrival; the replay kept its time.
    expect(
      Math.abs(new Date(rows[0].check_in!).getTime() - arrived.getTime()),
    ).toBeLessThan(2000);

    expect(
      (await heldBadges(admin.page)).map((row) => row.badge),
    ).not.toContain("7777");
  } finally {
    await admin.context.close();
  }
});

test("a badge whose holder arrives by import is named on the card, and one click replays it", async ({
  browser,
  request,
}) => {
  const arrived = minutesAgo(90);
  expect(await pushTerminalPunch(request, "7778", "check_in", arrived)).toBe(
    "employee_not_found",
  );

  const admin = await as(browser, "e2e-att", "admin");

  try {
    // The list HR typed up from the badge sheet, pasted into the import.
    await admin.page.goto("/employees/import");
    const list = admin.page.getByRole("textbox", {
      name: "From a spreadsheet or list",
    });
    await expect(list).toBeVisible({ timeout: 30000 });
    await list.fill("name,badge\nHana Worku,7778");

    const stage = admin.page.getByRole("button", { name: "Stage for review" });
    await expect(stage).toBeEnabled({ timeout: 30000 });
    await stage.click();

    await admin.page.getByRole("button", { name: "Commit migration" }).click();
    await admin.page.getByRole("button", { name: "Bring them in" }).click();
    await expect(
      admin.page.getByRole("button", { name: "Committed" }),
    ).toBeVisible({ timeout: 60000 });

    const person = await employeeNamed(admin.page, "Hana Worku");
    expect(person.badge_number).toBe("7778");

    // Importing links the badge; it does not replay what was held.
    const held = (await heldBadges(admin.page)).find(
      (row) => row.badge === "7778",
    );
    expect(held?.match?.outcome).toBe("matched");
    expect(held?.match?.employee_public_id).toBe(person.public_id);
    expect(
      live(
        await recordsOf(admin.page, person.public_id, today().from, today().to),
      ),
    ).toHaveLength(0);

    await admin.page.goto("/devices");
    const row = admin.page.locator("li", { hasText: "Badge 7778" });
    await expect(row.getByText("This is Hana Worku")).toBeVisible({
      timeout: 30000,
    });

    const replayed = admin.page.waitForResponse(
      (response) =>
        response.url().includes("/devices/unmatched-punches/assign") &&
        response.request().method() === "POST",
    );
    await row
      .getByRole("button", { name: "Assign and replay 1 punches" })
      .click();
    expect((await replayed).status()).toBe(200);

    const rows = live(
      await recordsOf(admin.page, person.public_id, today().from, today().to),
    );
    expect(rows).toHaveLength(1);
    expect(
      Math.abs(new Date(rows[0].check_in!).getTime() - arrived.getTime()),
    ).toBeLessThan(2000);
    expect(
      (await heldBadges(admin.page)).map((row) => row.badge),
    ).not.toContain("7778");
  } finally {
    await admin.context.close();
  }
});

test("a terminal that only sends is never offered as a roster, nor swapped for another", async ({
  browser,
}) => {
  const admin = await as(browser, "e2e-att", "admin");

  try {
    const terminal = await deviceId(admin.page, PUSH_TERMINAL.name);

    // The roster read is refused, not answered empty.
    const roster = await api(
      admin.page,
      "GET",
      `/devices/${terminal}/enrollments`,
    );
    expect(roster.status).toBe(409);
    expect(roster.data.code).toBe("device-roster-unavailable");

    // Opened for it, the page names it and chooses no other terminal, though
    // e2e-att has a readable one too.
    let staged = false;
    admin.page.on("request", (sent) => {
      if (
        sent.method() === "POST" &&
        sent.url().includes("/onboarding/migration/devices/")
      )
        staged = true;
    });
    await admin.page.goto(`/employees/from-device?device=${terminal}`);
    await expect(
      admin.page.getByText(
        `${PUSH_TERMINAL.name} sends its punches to ETHR and cannot be asked who is enrolled on it. Choose another terminal.`,
      ),
    ).toBeVisible({ timeout: 30000 });
    await expect(
      admin.page.getByRole("button", { name: "Discover enrolled users" }),
    ).toBeDisabled();

    expect(staged).toBe(false);
  } finally {
    await admin.context.close();
  }
});
