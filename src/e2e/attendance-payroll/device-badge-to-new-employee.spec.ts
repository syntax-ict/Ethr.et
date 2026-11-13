import { expect, test } from "@playwright/test";
import { api } from "../helpers";
import { ethiopianMonthName, toEthiopian } from "../../src/lib/calendar/ethiopian";
import { addisDate, as, heldPunchTimes, hikvisionPunch, live, minutesAgo, recordsOf, unmatchedBadges } from "./support";

/**
 * A badge nobody owns becomes a person, and the punches held for it are
 * recorded for them.
 *
 * A terminal that sends its punches to ETHR posts the badge of whoever stood
 * in front of it. When no employee has that badge the punch is held, not
 * dropped. From Unrecognised badges on Devices, "Add as a new employee" opens
 * the add form with the badge, the terminal and the day it was first held as
 * the hire date; saving gives the badge to the new person and records the held
 * punches through the same path a punch from the terminal takes.
 *
 * Both ends are read back from the API, not from the screen: the employee has
 * the badge and the hire date, one attendance record exists with the times the
 * terminal sent, and the badge has left the list of badges nobody owns.
 */
test("a badge nobody owns becomes a new employee and its held punches are recorded", async ({ browser, request }) => {
  // A development server compiles each page the first time it is asked for.
  test.setTimeout(240_000);
  const badge = "7701";
  const { arrived, left } = heldPunchTimes();

  expect(await hikvisionPunch(request, "e2e-dev", badge, arrived, "checkIn")).toBe("employee_not_found");
  expect(await hikvisionPunch(request, "e2e-dev", badge, left, "checkOut")).toBe("employee_not_found");

  const admin = await as(browser, "e2e-dev", "admin");
  try {
    const { page } = admin;

    // What the server holds, and what it makes of it.
    const held = (await unmatchedBadges(page)).filter((row) => row.badge === badge);
    expect(held).toHaveLength(1);
    expect(held[0].device_name).toBe("Front Door Terminal");
    expect(held[0].punches).toBe(2);
    expect(held[0].match?.outcome).toBe("new");

    await page.goto("/devices#unmatched");
    const card = page.locator("#unmatched");
    const row = card.locator("li", { hasText: `Badge ${badge}` });
    await expect(row).toBeVisible();
    await row.getByRole("link", { name: "Add as a new employee" }).click();

    // The form is opened with the badge, the terminal and the hire date.
    await page.waitForURL(/\/employees\/new\?/);
    const opened = new URL(page.url());
    expect(opened.pathname).toBe("/employees/new");
    expect(opened.searchParams.get("badge")).toBe(badge);
    expect(opened.searchParams.get("device")).toBe(held[0].device_public_id);
    const hireDate = opened.searchParams.get("hire_date");
    expect(hireDate).toBe(addisDate(arrived));
    await expect(page.locator("#badge_number")).toHaveValue(badge);
    // The organisation reads dates in the Ethiopian calendar, so the hire date
    // is entered as year, month and day: the same day the link carries.
    const [year, month, day] = hireDate!.split("-").map(Number);
    const ethiopian = toEthiopian(year, month, day);
    await expect(page.locator("#hire_date")).toHaveValue(String(ethiopian.year));
    await expect(page.getByRole("combobox", { name: /Hire Date, Ethiopian month/ })).toHaveText(
      ethiopianMonthName(ethiopian.month),
    );
    await expect(page.getByRole("combobox", { name: /Hire Date, Ethiopian day/ })).toHaveText(String(ethiopian.day));

    await page.locator("#name").fill("Hiwot Alemu");
    await page.getByRole("button", { name: "Save employee" }).click();
    await page.waitForURL(/\/employees\/[0-9A-Z]{26}$/, { timeout: 120_000 });

    // The employee, as the API has it.
    const found = await api(page, "GET", `/employees?search=${encodeURIComponent("Hiwot Alemu")}`);
    expect(found.status, "employee search").toBe(200);
    const person = (
      found.data.data as Array<{
        public_id: string;
        name: string;
        badge_number: string | null;
        hire_date: string;
      }>
    ).find((candidate) => candidate.name === "Hiwot Alemu");
    expect(person, "the new employee").toBeTruthy();
    expect(person!.badge_number).toBe(badge);
    expect(person!.hire_date).toBe(hireDate);

    // The held punches, recorded: once, from the terminal, at the times it sent.
    // The replay is the save's own request, so it is done by now; the wait is
    // for a queue that is not draining to say so.
    const deadline = Date.now() + 60_000;
    let rows = live(await recordsOf(page, person!.public_id, addisDate(minutesAgo(1640)), addisDate(new Date())));
    while (rows.length === 0 && Date.now() < deadline) {
      await page.waitForTimeout(2000);
      rows = live(await recordsOf(page, person!.public_id, addisDate(minutesAgo(1640)), addisDate(new Date())));
    }
    expect(
      rows,
      "no attendance record 60s after saving: the held punches were not replayed; the queue may not be draining",
    ).toHaveLength(1);
    expect(rows[0].source).toBe("biometric");
    expect(Math.abs(new Date(rows[0].check_in!).getTime() - arrived.getTime())).toBeLessThan(2000);
    expect(Math.abs(new Date(rows[0].check_out!).getTime() - left.getTime())).toBeLessThan(2000);

    // And the badge is no longer anyone's to claim.
    expect((await unmatchedBadges(page)).filter((candidate) => candidate.badge === badge)).toHaveLength(0);
  } finally {
    await admin.context.close();
  }
});
