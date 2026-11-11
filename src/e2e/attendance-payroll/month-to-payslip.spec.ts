import { expect, test } from "@playwright/test";
import { api } from "../helpers";
import {
  addis,
  as,
  devicePunch,
  employeeId,
  lastMonth,
  live,
  processedRun,
  recordsOf,
  step,
  type PayrollEntry,
} from "./support";

/**
 * J7 and J8, acceptance matrix E1, E3, E5 and E6: a month of punches, through
 * the queue, into an approved run and a payslip.
 *
 *   verified punches → attendance engine → records → payroll engine
 *   → calculation log → approval → payslip
 *
 * What it proves about the boundary between the two:
 *  - a day reported five times is paid once;
 *  - overtime in the log is the overtime on the one record of that day;
 *  - late arrival and absence are listed and, with deductions off, not taken;
 *  - the person who processed a run cannot release it;
 *  - a punch that arrives after the run was approved is recorded, and the
 *    stored entry does not move, while a human edit of that day is refused.
 *
 * The month is last month, dated by the terminal that reports it: this month
 * is still being worked.
 */
test("a month of punches is paid once, and stays paid", async ({ browser, request }) => {
  test.setTimeout(420_000);

  const period = lastMonth();
  const [ordinary, long, repeated] = period.workdays;
  const punch = (badge: string, type: "check_in" | "check_out", day: string, clock: string) =>
    devicePunch(request, "e2e-pay", badge, type, addis(day, clock));

  // Selam: an ordinary day, a day two hours long, and a day the terminal
  // reports again and again.
  expect(await punch("9101", "check_in", ordinary, "08:25")).toBe(1);
  expect(await punch("9101", "check_out", ordinary, "17:30")).toBe(1);

  expect(await punch("9101", "check_in", long, "08:25")).toBe(1);
  expect(await punch("9101", "check_out", long, "19:30")).toBe(1);

  expect(await punch("9101", "check_in", repeated, "08:20")).toBe(1);
  await punch("9101", "check_in", repeated, "08:20");
  await punch("9101", "check_in", repeated, "08:22");
  expect(await punch("9101", "check_out", repeated, "17:30")).toBe(1);
  await punch("9101", "check_out", repeated, "17:32");

  // Yonas: one day, 40 minutes late. Hana: nothing all month.
  expect(await punch("9102", "check_in", ordinary, "09:10")).toBe(1);
  expect(await punch("9102", "check_out", ordinary, "17:30")).toBe(1);

  const finance = await as(browser, "e2e-pay", "finance");
  const admin = await as(browser, "e2e-pay", "admin");

  try {
    const selam = await employeeId(admin.page, "PAY-0001");
    const yonas = await employeeId(admin.page, "PAY-0002");
    const hana = await employeeId(admin.page, "PAY-0003");

    // The attendance payroll is about to read.
    const worked = live(await recordsOf(admin.page, selam, period.start, period.end));
    expect(worked.map((record) => record.date).sort()).toEqual([ordinary, long, repeated].sort());
    const longDay = worked.find((record) => record.date === long)!;
    expect(longDay.overtime_minutes).toBe(120);
    expect(worked.find((record) => record.date === repeated)!.overtime_minutes).toBe(0);
    expect((await recordsOf(admin.page, yonas, period.start, period.end))[0].status).toBe("late");

    // Finance processes the month. It is queued, as in production.
    const requested = await api(finance.page, "POST", "/payroll/process", {
      period_start: period.start,
      period_end: period.end,
      idempotency_key: `j7-${period.start}-${Date.now()}`,
    });
    expect(requested.status).toBe(202);
    const runId = requested.data.public_id as string;

    const run = await processedRun(finance.page, runId);
    expect(run.employee_count).toBe(3);
    const entryOf = (employee: string): PayrollEntry =>
      run.entries.find((entry) => entry.employee_public_id === employee)!;

    // Selam is paid for the long day's two hours, and for nothing else extra:
    // the day reported five times added no minute.
    const selamEntry = entryOf(selam);
    const overtime = step(selamEntry, "overtime");
    expect(overtime.overtime_minutes).toBe(longDay.overtime_minutes);
    expect((overtime.by_type as Record<string, { minutes: number }>).normal.minutes).toBe(120);
    expect(overtime.overtime_amount_cents as number).toBeGreaterThan(0);
    expect(selamEntry.gross_cents).toBe(selamEntry.basic_salary_cents + (overtime.overtime_amount_cents as number));

    // Yonas was late and Hana never came. With deductions off both are paid
    // in full, and Hana's missing days are on the log for someone to settle.
    const yonasEntry = entryOf(yonas);
    expect(step(yonasEntry, "overtime").overtime_minutes).toBe(0);
    expect(yonasEntry.gross_cents).toBe(yonasEntry.basic_salary_cents);

    const hanaEntry = entryOf(hana);
    expect(hanaEntry.gross_cents).toBe(hanaEntry.basic_salary_cents);
    expect((step(hanaEntry, "absences").unexplained_absence_days as string[]).length).toBeGreaterThan(15);

    const register = await api(finance.page, "GET", `/payroll/runs/${runId}/register`);
    expect(register.status).toBe(200);

    // The person who processed the run cannot release it.
    const selfReleased = await api(finance.page, "PUT", `/payroll/runs/${runId}/approve`, {});
    expect(selfReleased.status).toBe(403);

    const released = await api(admin.page, "PUT", `/payroll/runs/${runId}/approve`, {});
    expect(released.status).toBe(200);
    expect((await api(admin.page, "GET", `/payroll/runs/${runId}`)).data.status).toBe("approved");

    // The payslip is the stored entry.
    const payslips = await api(admin.page, "GET", `/payroll/payslips/${selam}`);
    expect(payslips.status).toBe(200);
    expect(JSON.stringify(payslips.data)).toContain(selamEntry.public_id);

    // The run's own page names the people it paid.
    await admin.page.goto(`/payroll/${runId}`);
    await expect(admin.page.getByText("Selam Girma").first()).toBeVisible({ timeout: 90000 });

    // After the release: the terminal delivers a punch it had been holding
    // for a paid day. It is a fact about what happened, so it is recorded...
    expect(await punch("9101", "check_in", ordinary, "19:00")).toBe(1);
    expect(await recordsOf(admin.page, selam, ordinary, ordinary)).toHaveLength(2);

    // ...and nothing that was paid moves.
    const reread = await api(admin.page, "GET", `/payroll/runs/${runId}?include_log=1`);
    expect(reread.data.status).toBe("approved");
    expect(reread.data.entries.find((entry: PayrollEntry) => entry.employee_public_id === selam)).toEqual(selamEntry);

    // A person editing that day is refused: the day is paid.
    const edited = await api(admin.page, "POST", "/attendance/manual", {
      idempotency_key: `j7-manual-${Date.now()}`,
      employee_public_id: yonas,
      date: ordinary,
      check_in: "08:00",
      check_out: "17:00",
      reason: "Entered after the run was approved",
    });
    expect(edited.status).toBe(422);
    expect(edited.data.type).toBe("https://ethr.et/errors/payroll-period-closed");
  } finally {
    await finance.context.close();
    await admin.context.close();
  }
});
