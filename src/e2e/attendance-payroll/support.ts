import {
  expect,
  test,
  type APIRequestContext,
  type Browser,
  type BrowserContext,
  type Page,
} from "@playwright/test";
import path from "path";
import { fileURLToPath } from "url";
import { api } from "../helpers";

/**
 * What the attendance-to-payslip journeys share: where each organisation
 * lives, who signs in to it, a biometric terminal without hardware, and the
 * reads every journey ends on.
 *
 * The organisations, sign-ins, badges and the terminal's token are the ones
 * `api/database/seeders/E2eAttendanceSeeder.php` creates. Change one side and
 * change the other.
 */

const here = path.dirname(fileURLToPath(import.meta.url));

export type Organisation = "e2e-att" | "e2e-pay" | "e2e-other" | "e2e-dev";

export const PASSWORD = "password";
export const ZONE = "Africa/Addis_Ababa";

/** `http://{tenant}.localhost:3000` unless E2E_ORIGIN says otherwise. */
export function origin(organisation: Organisation): string {
  return (process.env.E2E_ORIGIN ?? "http://{tenant}.localhost:3000").replace(
    "{tenant}",
    organisation,
  );
}

export function email(organisation: Organisation, login: string): string {
  return `${login}@${organisation}.ethr.test`;
}

export function statePath(organisation: Organisation, login: string): string {
  return path.join(here, "..", ".auth", `${organisation}-${login}.json`);
}

/** Every session the journeys use, signed in once by global-setup. */
export const SESSIONS: Array<{ organisation: Organisation; login: string }> = [
  { organisation: "e2e-att", login: "admin" },
  { organisation: "e2e-att", login: "emp1" },
  { organisation: "e2e-att", login: "emp3" },
  { organisation: "e2e-pay", login: "admin" },
  { organisation: "e2e-pay", login: "finance" },
  { organisation: "e2e-other", login: "admin" },
  { organisation: "e2e-dev", login: "admin" },
];

/**
 * A page signed in as `login` at the organisation's own address, already on
 * the dashboard so `api()` has the session's cookies and XSRF token to send.
 */
export async function as(
  browser: Browser,
  organisation: Organisation,
  login: string,
): Promise<{ context: BrowserContext; page: Page }> {
  const context = await browser.newContext({
    baseURL: origin(organisation),
    storageState: statePath(organisation, login),
  });
  const page = await context.newPage();
  await page.goto("/dashboard");
  await page.waitForLoadState("networkidle").catch(() => {});

  return { context, page };
}

/** The date in Addis Ababa, `YYYY-MM-DD`: the day a record is filed under. */
export function addisDate(moment: Date): string {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: ZONE,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(moment);
}

/** The wall clock in Addis Ababa, `YYYY-MM-DD HH:mm:ss`, as a terminal reports it. */
export function addisClock(moment: Date): string {
  const time = new Intl.DateTimeFormat("en-GB", {
    timeZone: ZONE,
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hour12: false,
  }).format(moment);

  return `${addisDate(moment)} ${time}`;
}

/** The instant of a wall-clock time in Addis Ababa, which keeps UTC+3 all year. */
export function addis(date: string, clock: string): Date {
  return new Date(`${date}T${clock}:00+03:00`);
}

export function minutesAgo(minutes: number): Date {
  return new Date(Date.now() - minutes * 60_000);
}

/**
 * One punch from the organisation's biometric terminal, as its firmware posts
 * it: the badge, the terminal's own wall clock and 0 for in or 1 for out,
 * authenticated by the terminal's token. No hardware and no vendor SDK.
 *
 * Nothing in the request names the organisation, as nothing does from a real
 * terminal: it posts to the deployment's own address and cannot send
 * `X-Tenant`. The token finds the device and the device says whose punch it
 * is. Keep it that way: a journey that sent the organisation would pass even
 * if the server stopped working that out (it did not, until 2026-11-10, and
 * every such punch was answered `processed` and lost).
 *
 * Returns how many punches the server took from the batch.
 */
export async function devicePunch(
  request: APIRequestContext,
  organisation: Organisation,
  badge: string,
  type: "check_in" | "check_out",
  at: Date,
  token = `${organisation}-zk-token`,
): Promise<number> {
  // Posted to plain `localhost`, the deployment's own address here: it names
  // no organisation, as the apex does not in production, and Node on Windows
  // cannot resolve *.localhost in any case.
  const target = new URL(origin(organisation));
  if (target.hostname.endsWith(".localhost")) target.hostname = "localhost";

  const response = await request.post(
    `${target.origin}/api/v1/devices/webhook/zkteco`,
    {
      headers: { "X-Webhook-Token": token, Accept: "application/json" },
      data: {
        records: [
          {
            pin: badge,
            timestamp: addisClock(at),
            punch: type === "check_in" ? 0 : 1,
          },
        ],
      },
    },
  );
  expect(response.status(), `device webhook for badge ${badge}`).toBe(200);

  return (await response.json()).count as number;
}

/**
 * One punch from the organisation's Hikvision terminal in push mode, as its
 * firmware posts it: multipart, the event as JSON in `event_log`, the person's
 * id on the terminal in `employeeNoString`, the terminal's own wall clock with
 * its offset in `dateTime`, and the token in the query string. As with
 * `devicePunch`, nothing in the request names the organisation.
 *
 * A badge nobody holds is answered 200 `employee_not_found` and kept; one an
 * employee holds is answered 200 `processed`. Returns the answer's `status`.
 */
export async function hikvisionPunch(
  request: APIRequestContext,
  organisation: Organisation,
  badge: string,
  at: Date,
  status: "checkIn" | "checkOut",
  token = "e2e-dev-hik-token",
): Promise<string> {
  const target = new URL(origin(organisation));
  if (target.hostname.endsWith(".localhost")) target.hostname = "localhost";

  const response = await request.post(
    `${target.origin}/api/v1/devices/webhook/hikvision?token=${token}`,
    {
      headers: { Accept: "application/json" },
      multipart: {
        event_log: JSON.stringify({
          dateTime: `${addisClock(at).replace(" ", "T")}+03:00`,
          eventType: "AccessControllerEvent",
          AccessControllerEvent: {
            employeeNoString: badge,
            attendanceStatus: status,
            majorEventType: 5,
            subEventType: 75,
          },
        }),
      },
    },
  );
  expect(response.status(), `hikvision webhook for badge ${badge}`).toBe(200);

  return (await response.json()).status as string;
}

export type UnmatchedBadge = {
  device_public_id: string;
  device_name: string;
  badge: string;
  punches: number;
  first_seen: string;
  last_seen: string;
  match: {
    outcome: string;
    employee_public_id: string | null;
    candidates: Array<{ employee_public_id: string; employee_name: string }>;
  } | null;
};

/** The badges held for nobody, as the Devices card is given them. */
export async function unmatchedBadges(page: Page): Promise<UnmatchedBadge[]> {
  const found = await api(page, "GET", "/devices/unmatched-punches");
  expect(found.status, "unmatched punches").toBe(200);

  return found.data.data as UnmatchedBadge[];
}

/**
 * The two punches a badge arrives with: in 50 minutes ago and out 20 minutes
 * ago. They must fall on the Addis date the server first sees them, which is
 * the hire date the card offers, so a run in the 50 minutes after midnight
 * skips with the reason. It does not fail: the gate blocks a merge, and a push
 * between 21:00 and 21:50 UTC would otherwise go red for the time of day.
 */
export function heldPunchTimes(): { arrived: Date; left: Date } {
  const arrived = minutesAgo(50);
  const left = minutesAgo(20);
  test.skip(
    addisDate(arrived) !== addisDate(new Date()),
    "It is less than 50 minutes after midnight in Addis Ababa: the punches would be dated the day before the " +
      "server first saw them, and the hire date the card offers would fall after them.",
  );

  return { arrived, left };
}

/** e2e-att's push-only Hikvision terminal: `E2eAttendanceSeeder::PUSH_TOKEN`. */
export const PUSH_TERMINAL = {
  name: "Main Gate Terminal",
  token: "e2e-att-hik-token",
};

/**
 * One punch from e2e-att's Hikvision terminal that only sends to ETHR, as its
 * firmware posts it: multipart, the event as JSON in `event_log`, the token
 * in the address. Like a real terminal it names no organisation. A badge no
 * employee has is held, not recorded, and the answer says so.
 *
 * Returns the server's `status`: `processed`, or `employee_not_found` for a
 * badge it held.
 */
export async function pushTerminalPunch(
  request: APIRequestContext,
  badge: string,
  type: "check_in" | "check_out",
  at: Date,
): Promise<string> {
  const target = new URL(origin("e2e-att"));
  if (target.hostname.endsWith(".localhost")) target.hostname = "localhost";

  const response = await request.post(
    `${target.origin}/api/v1/devices/webhook/hikvision?token=${PUSH_TERMINAL.token}`,
    {
      headers: { Accept: "application/json" },
      multipart: {
        event_log: JSON.stringify({
          dateTime: `${addisClock(at).replace(" ", "T")}+03:00`,
          eventType: "AccessControllerEvent",
          AccessControllerEvent: {
            employeeNoString: badge,
            attendanceStatus: type === "check_in" ? "checkIn" : "checkOut",
            majorEventType: 5,
            subEventType: 75,
          },
        }),
      },
    },
  );
  expect(response.status(), `push terminal webhook for badge ${badge}`).toBe(
    200,
  );

  return (await response.json()).status as string;
}

/** The device's public id, found by name as an administrator. */
export async function deviceId(page: Page, name: string): Promise<string> {
  const found = await api(page, "GET", "/devices");
  expect(found.status, "device list").toBe(200);
  const match = (
    found.data.data as Array<{ public_id: string; name: string }>
  ).find((row) => row.name === name);
  expect(match, `device ${name}`).toBeTruthy();

  return match!.public_id;
}

/** The badges held right now, as the Unrecognised badges card reads them. */
export async function heldBadges(
  page: Page,
): Promise<
  Array<{
    badge: string;
    punches: number;
    match: { outcome: string; employee_public_id: string | null } | null;
  }>
> {
  const found = await api(page, "GET", "/devices/unmatched-punches");
  expect(found.status, "unrecognised badges").toBe(200);

  return found.data.data;
}

export type AttendanceRow = {
  public_id: string;
  date: string;
  check_in: string | null;
  check_out: string | null;
  source: string;
  status: string;
  overtime_minutes: number;
  conflict: { action: string; with_record_public_id: string | null } | null;
  punches: Array<{ kind: string; source: string; at: string; outcome: string }>;
};

/** The employee's public id, looked up by their code as an administrator. */
export async function employeeId(page: Page, code: string): Promise<string> {
  const found = await api(
    page,
    "GET",
    `/employees?search=${encodeURIComponent(code)}`,
  );
  expect(found.status, `employee search for ${code}`).toBe(200);
  const match = (
    found.data.data as Array<{ public_id: string; employee_code: string }>
  ).find((row) => row.employee_code === code);
  expect(match, `employee ${code}`).toBeTruthy();

  return match!.public_id;
}

/**
 * What the database holds for one employee between two dates, voided rows
 * included, read as an administrator of their organisation.
 */
export async function recordsOf(
  page: Page,
  employee: string,
  from: string,
  to: string,
): Promise<AttendanceRow[]> {
  const found = await api(
    page,
    "GET",
    `/attendance?per_page=100&filter[employee_public_id]=${employee}&filter[date_from]=${from}&filter[date_to]=${to}`,
  );
  expect(found.status, "attendance list").toBe(200);

  return found.data.data as AttendanceRow[];
}

export function live(rows: AttendanceRow[]): AttendanceRow[] {
  return rows.filter((row) => row.status !== "voided");
}

export type Period = { start: string; end: string; workdays: string[] };

/**
 * Last calendar month in Addis Ababa, whole, with its Monday-to-Friday dates
 * from the second week on. A journey that pays a month has to pay one that is
 * over: this month's days are still being worked, and the punches for a past
 * month can be dated by the terminal that reports them.
 */
export function lastMonth(now = new Date()): Period {
  const [year, month] = addisDate(now).split("-").map(Number);
  const first = new Date(Date.UTC(year, month - 2, 1));
  const last = new Date(Date.UTC(year, month - 1, 0));
  const iso = (day: Date) => day.toISOString().slice(0, 10);

  const workdays: string[] = [];
  for (
    let day = new Date(Date.UTC(year, month - 2, 8));
    day <= last;
    day.setUTCDate(day.getUTCDate() + 1)
  ) {
    if (day.getUTCDay() >= 1 && day.getUTCDay() <= 5) workdays.push(iso(day));
  }

  return { start: iso(first), end: iso(last), workdays };
}

export type PayrollEntry = {
  public_id: string;
  employee_public_id: string;
  basic_salary_cents: number;
  gross_cents: number;
  net_cents: number;
  income_tax_cents: number;
  calculation_log?: {
    version: string;
    steps: Array<Record<string, unknown> & { step: string }>;
  };
};

export type PayrollRun = {
  public_id: string;
  status: string;
  employee_count: number;
  period_start: string;
  period_end: string;
  entries: PayrollEntry[];
};

/**
 * The run once the queue has processed it, read with every entry's
 * calculation log. Payroll is queued, as in production: if nothing drains the
 * queue the run stays `processing`, and this says so rather than timing out
 * with no reason.
 */
export async function processedRun(
  page: Page,
  run: string,
  timeoutMs = 180_000,
): Promise<PayrollRun> {
  const deadline = Date.now() + timeoutMs;
  let status = "unknown";

  while (Date.now() < deadline) {
    const found = await api(page, "GET", `/payroll/runs/${run}?include_log=1`);
    expect(found.status, "payroll run").toBe(200);
    status = found.data.status;
    if (status === "completed") return found.data as PayrollRun;
    expect(status, "payroll run failed while processing").not.toBe("failed");
    await page.waitForTimeout(2000);
  }

  throw new Error(
    `The payroll run is still "${status}" after ${timeoutMs / 1000}s. Nothing is draining the queue: start ` +
      "php artisan queue:work --queue=attendance,notifications,default,exports against the same database.",
  );
}

/** One step of an entry's calculation log, by name. */
export function step(
  entry: PayrollEntry,
  name: string,
): Record<string, unknown> {
  const found = entry.calculation_log?.steps.find(
    (candidate) => candidate.step === name,
  );
  expect(found, `calculation log step "${name}"`).toBeTruthy();

  return found!;
}
