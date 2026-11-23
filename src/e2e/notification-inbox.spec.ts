import { test, expect } from "@playwright/test";
import { api } from "./helpers";

/**
 * One inbox, end to end (2026-11-23). An approval is a notification in the
 * employee's bell, opening it lands on the page it links to, and the API then
 * reports it read. Every assertion is on what the API reads back, not on a toast.
 *
 * The employee is the one told, because that is certain: who an employee's
 * leave request is routed to depends on the organisation's chain, but the person
 * who files it always hears the answer. A running stack is needed, as for every
 * spec here.
 */
const APPROVERS = ["e2e/.auth/admin.json", "e2e/.auth/hr.json"];

/**
 * A Monday far enough out that no earlier run's request overlaps it, worked out
 * in UTC so the weekday does not depend on the machine's zone. The demo
 * organisation's holidays make some Mondays carry no leave days, so the journey
 * asks again for the next Monday when the API refuses one.
 */
function aFarMonday(skipWeeks: number): string {
  const day = new Date();
  day.setUTCDate(day.getUTCDate() + 120 + Math.floor(Math.random() * 200));
  day.setUTCDate(day.getUTCDate() + ((8 - day.getUTCDay()) % 7) + 7 * skipWeeks);
  return day.toISOString().slice(0, 10);
}

type Item = {
  id: string;
  read_at: string | null;
  data: { leave_request_id?: string; url?: string };
};

test("an approved leave request reaches the employee's bell, opens its page, and reads back as read", async ({
  browser,
}) => {
  const employee = await browser.newContext({
    storageState: "e2e/.auth/employee.json",
  });
  const ep = await employee.newPage();
  await ep.goto("/dashboard");

  const types = await api<{
    data: { public_id: string; gender_restriction: string | null }[];
  }>(ep, "GET", "/leave-types?filter[is_active]=1");
  expect(types.status).toBe(200);
  const leaveType = types.data.data.find((t) => !t.gender_restriction)!;

  let filed!: Awaited<ReturnType<typeof api>>;
  for (let skip = 0; skip < 6; skip++) {
    const day = aFarMonday(skip);
    filed = await api(ep, "POST", "/leave/request", {
      leave_type_public_id: leaveType.public_id,
      start_date: day,
      end_date: day,
      reason: "QA inbox journey",
    });
    if (filed.status === 201) break;
  }
  expect(filed.status).toBe(201);
  const requestId: string = (filed.data.data ?? filed.data).public_id;

  try {
    // Whoever may approve answers it; the employee is the one told.
    let approved = 0;
    for (const state of APPROVERS) {
      const ctx = await browser.newContext({ storageState: state });
      const page = await ctx.newPage();
      await page.goto("/dashboard");
      approved = (await api(page, "PUT", `/leave/${requestId}/approve`, {}))
        .status;
      await ctx.close();
      if (approved === 200) break;
    }
    expect(approved).toBe(200);

    // The employee's own list carries it, unread, with the page it links to.
    let item: Item | undefined;
    await expect
      .poll(
        async () => {
          const list = await api<{ data: Item[] }>(
            ep,
            "GET",
            "/notifications?filter[read]=false",
          );
          item = list.data.data.find(
            (n) => n.data.leave_request_id === requestId,
          );
          return item !== undefined;
        },
        { timeout: 30000, intervals: [1000] },
      )
      .toBe(true);
    expect(item!.data.url).toBe("/leave");

    // It is in the bell, and the bell says how many wait.
    await ep.goto("/dashboard");
    const bell = ep.getByRole("button", { name: /^Notifications, \d+ unread$/ });
    await expect(bell).toBeVisible({ timeout: 15000 });
    await bell.click();
    // The bell lists newest first, and this approval is the newest.
    await ep
      .locator("[data-radix-popper-content-wrapper] .max-h-80 button")
      .first()
      .click();

    // Opening it lands on the page it links to.
    await expect(ep).toHaveURL(/\/leave/);

    // And the API reads it back as read.
    await expect
      .poll(
        async () => {
          const all = await api<{ data: Item[] }>(ep, "GET", "/notifications");
          return all.data.data.find((n) => n.id === item!.id)?.read_at ?? null;
        },
        { timeout: 15000 },
      )
      .not.toBeNull();
  } finally {
    await api(ep, "PUT", `/leave/${requestId}/cancel`);
    await employee.close();
  }
});
