import { expect, type APIRequestContext, type Browser, type BrowserContext, type Page } from "@playwright/test";
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

export type Organisation = "e2e-att" | "e2e-pay" | "e2e-other";

export const PASSWORD = "password";
export const ZONE = "Africa/Addis_Ababa";

/** `http://{tenant}.localhost:3000` unless E2E_ORIGIN says otherwise. */
export function origin(organisation: Organisation): string {
  return (process.env.E2E_ORIGIN ?? "http://{tenant}.localhost:3000").replace("{tenant}", organisation);
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
  return new Intl.DateTimeFormat("en-CA", { timeZone: ZONE, year: "numeric", month: "2-digit", day: "2-digit" }).format(
    moment,
  );
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

  const response = await request.post(`${target.origin}/api/v1/devices/webhook/zkteco`, {
    headers: { "X-Webhook-Token": token, Accept: "application/json" },
    data: { records: [{ pin: badge, timestamp: addisClock(at), punch: type === "check_in" ? 0 : 1 }] },
  });
  expect(response.status(), `device webhook for badge ${badge}`).toBe(200);

  return (await response.json()).count as number;
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
  const found = await api(page, "GET", `/employees?search=${encodeURIComponent(code)}`);
  expect(found.status, `employee search for ${code}`).toBe(200);
  const match = (found.data.data as Array<{ public_id: string; employee_code: string }>).find(
    (row) => row.employee_code === code,
  );
  expect(match, `employee ${code}`).toBeTruthy();

  return match!.public_id;
}

/**
 * What the database holds for one employee between two dates, voided rows
 * included, read as an administrator of their organisation.
 */
export async function recordsOf(page: Page, employee: string, from: string, to: string): Promise<AttendanceRow[]> {
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
