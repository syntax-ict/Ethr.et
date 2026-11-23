import { test, expect } from "@playwright/test";
import { api } from "./helpers";

/**
 * One inbox, end to end (2026-11-23). An employee's leave request is a
 * notification in the approver's bell, opening it lands on the page it links
 * to, and the API then reports it read. Every assertion is on what the API
 * reads back, not on a toast.
 *
 * A running stack is needed, as for every spec here. The approver is whoever
 * the demo organisation's workflow names for this employee, so the journey asks
 * the API which of the two admin accounts was told rather than assuming one.
 */
const APPROVERS = ["e2e/.auth/hr.json", "e2e/.auth/admin.json"];

/** A Monday far enough out that no earlier run's request overlaps it. */
function aFarMonday(): string {
  const day = new Date();
  day.setDate(day.getDate() + 120 + Math.floor(Math.random() * 200));
  day.setDate(day.getDate() + ((8 - day.getDay()) % 7));
  return day.toISOString().slice(0, 10);
}

type Item = {
  id: string;
  read_at: string | null;
  data: { leave_request_id?: string; url?: string };
};

test("a leave request reaches the approver's bell, opens its page, and reads back as read", async ({
  browser,
}) => {
  const employee = await browser.newContext({
    storageState: "e2e/.auth/employee.json",
  });
  const ep = await employee.newPage();
  await ep.goto("/dashboard");

  const types = await api<{
    data: { public_id: string; gender_restriction: string | null }[];
  }>(
    ep,
    "GET",
    "/leave-types?filter[is_active]=1",
  );
  expect(types.status).toBe(200);
  const day = aFarMonday();
  const filed = await api(ep, "POST", "/leave/request", {
    leave_type_public_id: types.data.data.find((t) => !t.gender_restriction)!
      .public_id,
    start_date: day,
    end_date: day,
    reason: "QA inbox journey",
  });
  expect(filed.status).toBe(201);
  const requestId: string = (filed.data.data ?? filed.data).public_id;

  try {
    // Find the approver the API told, by the request the notification names.
    let approver: { state: string; item: Item } | undefined;
    await expect
      .poll(
        async () => {
          for (const state of APPROVERS) {
            const ctx = await browser.newContext({ storageState: state });
            const page = await ctx.newPage();
            await page.goto("/dashboard");
            const list = await api<{ data: Item[] }>(
              page,
              "GET",
              "/notifications?filter[read]=false",
            );
            await ctx.close();
            const item = list.data.data.find(
              (n) => n.data.leave_request_id === requestId,
            );
            if (item) {
              approver = { state, item };
              return true;
            }
          }
          return false;
        },
        { timeout: 60000, intervals: [2000] },
      )
      .toBe(true);

    const { state, item } = approver!;
    const ctx = await browser.newContext({ storageState: state });
    const page = await ctx.newPage();
    await page.goto("/dashboard");

    // It is in the bell, and the bell says how many wait.
    const bell = page.getByRole("button", { name: /^Notifications, \d+ unread$/ });
    await expect(bell).toBeVisible({ timeout: 15000 });
    await bell.click();
    await page
      .getByRole("button", { name: /asked for leave|leave request/i })
      .first()
      .click();

    // Opening it lands on the page it links to.
    expect(item.data.url).toBe("/approvals");
    await expect(page).toHaveURL(/\/approvals/);

    // And the API reads it back as read.
    await expect
      .poll(
        async () => {
          const all = await api<{ data: Item[] }>(page, "GET", "/notifications");
          return all.data.data.find((n) => n.id === item.id)?.read_at ?? null;
        },
        { timeout: 15000 },
      )
      .not.toBeNull();
    await ctx.close();
  } finally {
    await api(ep, "PUT", `/leave/${requestId}/cancel`);
    await employee.close();
  }
});
