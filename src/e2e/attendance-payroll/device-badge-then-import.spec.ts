import { expect, test } from "@playwright/test";
import { api } from "../helpers";
import { addisDate, as, heldPunchTimes, hikvisionPunch, live, minutesAgo, recordsOf, unmatchedBadges } from "./support";

/**
 * A badge is held first, the person arrives later, and one click records the
 * punches.
 *
 * The terminal sends a badge nobody has, so its punches are held. Later HR
 * imports the staff list, with that badge against one person. Nothing is
 * replayed by the import: badge numbers on a list are not unique and a typo
 * must not record a week of someone else's punches. The badge's card on
 * Devices now says whose it is ("This is ...") and offers "Assign and replay",
 * and that click is what records them.
 *
 * Read back from the API throughout: the person exists with the badge, the
 * card's match is `matched` before the click, one attendance record carries the
 * times the terminal sent, and the badge has left the list of unowned ones.
 */
test("a badge held before the import is recorded for the person it names when assigned", async ({
  browser,
  request,
}) => {
  // A development server compiles each page the first time it is asked for.
  test.setTimeout(240_000);
  const badge = "7702";
  const name = "Genet Tadesse";
  const { arrived, left } = heldPunchTimes();
  const hired = addisDate(arrived);

  expect(await hikvisionPunch(request, "e2e-dev", badge, arrived, "checkIn")).toBe("accepted");
  expect(await hikvisionPunch(request, "e2e-dev", badge, left, "checkOut")).toBe("accepted");

  const admin = await as(browser, "e2e-dev", "admin");
  try {
    const { page } = admin;

    // The terminal is answered `accepted` at once; the queue worker (the one
    // the payroll journey already needs) holds the punches, so wait for them.
    await expect
      .poll(
        async () =>
          (await unmatchedBadges(page)).filter((row) => row.badge === badge).reduce((n, row) => n + row.punches, 0),
        { timeout: 60_000 },
      )
      .toBe(2);

    const held = (await unmatchedBadges(page)).filter((row) => row.badge === badge);
    expect(held).toHaveLength(1);
    expect(held[0].punches).toBe(2);
    expect(held[0].match?.outcome).toBe("new");

    // The staff list, with the badge against the person, from a file.
    await page.goto("/employees/import");
    await page.getByRole("button", { name: "Gregorian (G.C.)" }).click();
    await page.getByTestId("people-file").setInputFiles({
      name: "staff.csv",
      mimeType: "text/csv",
      buffer: Buffer.from(`Name,Badge,Hire date\n${name},${badge},${hired}\n`),
    });
    await expect(page.getByText("1 person ready")).toBeVisible();
    await page.getByRole("button", { name: "Stage for review" }).click();
    await expect(page.getByRole("table", { name: "Migration review" })).toContainText(name);
    await page.getByRole("button", { name: "Commit migration" }).click();
    await page.getByRole("button", { name: "Bring them in" }).click();

    // The person, as the API has it. Nothing has been replayed yet.
    let person: { public_id: string; badge_number: string | null; hire_date: string } | undefined;
    const deadline = Date.now() + 60_000;
    while (!person && Date.now() < deadline) {
      const found = await api(page, "GET", `/employees?search=${encodeURIComponent(name)}`);
      expect(found.status, "employee search").toBe(200);
      person = (
        found.data.data as Array<{
          public_id: string;
          name: string;
          badge_number: string | null;
          hire_date: string;
        }>
      ).find((candidate) => candidate.name === name);
      if (!person) await page.waitForTimeout(2000);
    }
    expect(person, "the imported employee, 60s after committing").toBeTruthy();
    expect(person!.badge_number).toBe(badge);
    expect(person!.hire_date).toBe(hired);

    const range = [addisDate(minutesAgo(1640)), addisDate(new Date())] as const;
    expect(live(await recordsOf(page, person!.public_id, ...range))).toHaveLength(0);

    // The card now says whose badge it is.
    const now = (await unmatchedBadges(page)).filter((row) => row.badge === badge);
    expect(now).toHaveLength(1);
    expect(now[0].match?.outcome).toBe("matched");
    expect(now[0].match?.employee_public_id).toBe(person!.public_id);

    await page.goto("/devices#unmatched");
    const row = page.locator("#unmatched").locator("li", { hasText: `Badge ${badge}` });
    await expect(row).toContainText(`This is ${name}`);
    await row.getByRole("button", { name: "Assign and replay 2 punches" }).click();

    const waitUntil = Date.now() + 60_000;
    let rows = live(await recordsOf(page, person!.public_id, ...range));
    while (rows.length === 0 && Date.now() < waitUntil) {
      await page.waitForTimeout(2000);
      rows = live(await recordsOf(page, person!.public_id, ...range));
    }
    expect(
      rows,
      "no attendance record 60s after the assign: the held punches were not replayed; the queue may not be draining",
    ).toHaveLength(1);
    expect(rows[0].source).toBe("biometric");
    expect(Math.abs(new Date(rows[0].check_in!).getTime() - arrived.getTime())).toBeLessThan(2000);
    expect(Math.abs(new Date(rows[0].check_out!).getTime() - left.getTime())).toBeLessThan(2000);

    expect((await unmatchedBadges(page)).filter((candidate) => candidate.badge === badge)).toHaveLength(0);
  } finally {
    await admin.context.close();
  }
});
