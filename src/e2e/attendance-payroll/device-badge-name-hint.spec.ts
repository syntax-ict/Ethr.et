import { expect, test } from "@playwright/test";
import { addisDate, as, confirmAssign, employeeId, heldPunchTimes, hikvisionPunch, live, minutesAgo, recordsOf, unmatchedBadges } from "./support";

/**
 * A terminal that names the person behind a number nobody has.
 *
 * A Hikvision terminal sends the enrolled person's name beside the number. The
 * number matches no employee, so its punches are held; the card now says what
 * the terminal calls it and offers the one employee who fits that name (the
 * seeded Hirut Alemayehu Bekele, who has no terminal number). Choosing her
 * only fills the picker: nothing is recorded until Assign is clicked.
 *
 * Read back from the API throughout: the held row carries the name and exactly
 * that person, with the verdict `new`; after the pick nothing has moved; after
 * Assign her attendance record carries the two times the terminal sent and the
 * badge has left the list.
 */
test("a held badge shows the terminal's name and who it could be, and records nothing until assigned", async ({
  browser,
  request,
}) => {
  // A development server compiles each page the first time it is asked for.
  test.setTimeout(240_000);
  const badge = "7710";
  const terminalName = "Hirut Alemayehu";
  const { arrived, left } = heldPunchTimes();

  expect(await hikvisionPunch(request, "e2e-dev", badge, arrived, "checkIn", undefined, terminalName)).toBe("accepted");
  expect(await hikvisionPunch(request, "e2e-dev", badge, left, "checkOut", undefined, terminalName)).toBe("accepted");

  const admin = await as(browser, "e2e-dev", "admin");
  try {
    const { page } = admin;
    const hirut = await employeeId(page, "DEV-0002");

    await expect
      .poll(
        async () =>
          (await unmatchedBadges(page)).filter((row) => row.badge === badge).reduce((n, row) => n + row.punches, 0),
        { timeout: 60_000 },
      )
      .toBe(2);

    const held = (await unmatchedBadges(page)).filter((row) => row.badge === badge);
    expect(held).toHaveLength(1);
    expect(held[0].match?.outcome).toBe("new");
    expect(held[0].name_on_device).toBe(terminalName);
    expect(held[0].name_matches?.map((person) => person.employee_public_id)).toEqual([hirut]);

    const range = [addisDate(minutesAgo(1640)), addisDate(new Date())] as const;

    await page.goto("/devices#unmatched");
    const row = page.locator("#unmatched").locator("li", { hasText: `Badge ${badge}` });
    await expect(row).toContainText(`The terminal calls this person ${terminalName}`);
    await expect(row.getByRole("button", { name: /^Possibly Hirut Alemayehu Bekele/ })).toBeVisible();

    // Choosing her fills the picker and nothing else.
    await row.getByRole("button", { name: /^Possibly Hirut Alemayehu Bekele/ }).click();
    await expect(row.getByRole("button", { name: "Assign", exact: true })).toBeEnabled();
    await page.waitForTimeout(5_000);
    expect((await unmatchedBadges(page)).filter((candidate) => candidate.badge === badge)).toHaveLength(1);
    expect(live(await recordsOf(page, hirut, ...range))).toHaveLength(0);

    await row.getByRole("button", { name: "Assign", exact: true }).click();
    await confirmAssign(page);

    const deadline = Date.now() + 60_000;
    let rows = live(await recordsOf(page, hirut, ...range));
    while (rows.length === 0 && Date.now() < deadline) {
      await page.waitForTimeout(2000);
      rows = live(await recordsOf(page, hirut, ...range));
    }
    expect(
      rows,
      "no attendance record 60s after the assign: the held punches were not replayed; the queue may not be draining",
    ).toHaveLength(1);
    expect(Math.abs(new Date(rows[0].check_in!).getTime() - arrived.getTime())).toBeLessThan(2000);
    expect(Math.abs(new Date(rows[0].check_out!).getTime() - left.getTime())).toBeLessThan(2000);

    expect((await unmatchedBadges(page)).filter((candidate) => candidate.badge === badge)).toHaveLength(0);
  } finally {
    await admin.context.close();
  }
});
