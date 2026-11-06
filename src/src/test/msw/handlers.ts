import { http, HttpResponse } from "msw";
import type { components } from "@/api/generated";

type EmployeeResource = components["schemas"]["EmployeeResource"];

type TenantJourney = import("@/features/admin/journey").TenantJourney;
type JourneySummary = import("@/features/admin/journey").JourneySummary;

/** A freshly registered organisation's journey, for the console's journey card. */
export function buildJourney(
  overrides: Partial<TenantJourney> = {},
): TenantJourney {
  return {
    stage: "registered",
    stages: [
      "registered",
      "configured",
      "people_added",
      "team_activated",
      "first_use",
      "successfully_using",
    ],
    stage_reached_at: "2026-11-01T08:00:00+00:00",
    lifecycle: "active",
    health_state: "ok",
    reasons: [],
    milestones: { registered: "2026-11-01T08:00:00+00:00" },
    metrics: {
      employees: 0,
      team_users: 0,
      activated_users: 0,
      invited_pending: 0,
      users_logged_in_30d: 0,
      punches_14d: 0,
      punches_30d: 0,
      punch_sources_30d: [],
      leave_requests_14d: 0,
      leave_requests_30d: 0,
      payroll_enabled: false,
      payroll_runs: 0,
      devices: 0,
      devices_silent: 0,
      open_support_requests: 0,
      invoices_overdue: 0,
      invoices_past_due: 0,
      trial_days_left: null,
      last_login_at: null,
    },
    readiness: { score: 22, level: "not_ready", gaps: [] },
    last_activity_at: null,
    computed_at: "2026-11-06T08:00:00+00:00",
    events: [],
    ...overrides,
  };
}

export function buildJourneySummary(
  overrides: Partial<JourneySummary> = {},
): JourneySummary {
  return {
    total: 0,
    not_computed: 0,
    computed_at: null,
    stages: [
      "registered",
      "configured",
      "people_added",
      "team_activated",
      "first_use",
      "successfully_using",
    ].map((stage) => ({ stage, count: 0 })),
    health: { ok: 0, warning: 0, critical: 0, unknown: 0 },
    lifecycle: {
      trial: 0,
      trial_expired: 0,
      active: 0,
      suspended: 0,
      cancelled: 0,
    },
    ...overrides,
  };
}

export function buildEmployee(
  overrides: Partial<EmployeeResource> = {},
): EmployeeResource {
  return {
    public_id: "01HZEMPLOYEE0000000000001",
    name: "Abebe Kebede",
    name_am: "አበበ ከበደ",
    email: "abebe@example.com",
    phone: "+251911223344",
    employee_code: "EMP-0001",
    badge_number: null,
    nationality_status: "ethiopian",
    nationality_needs_review: false,
    has_kiosk_pin: false,
    attendance_mode: "office",
    attendance_device_exempt_until: null,
    gender: "male",
    date_of_birth: "1990-01-01",
    nationality: "Ethiopian",
    marital_status: "single",
    status: "active",
    hire_date: "2024-01-01",
    probation_end_date: "2024-04-01",
    confirmation_date: "2024-04-01",
    termination_date: "",
    // Kept internally consistent: a photo_path implies presigned URLs, which
    // EmployeeResource always returns alongside it.
    photo_path: "employees/01HZEMPLOYEE0000000000001.jpg",
    photo_url: "https://storage.test/employees/01HZEMPLOYEE0000000000001.jpg",
    photo_thumb_url:
      "https://storage.test/employees/01HZEMPLOYEE0000000000001-150.jpg",
    created_at: "2024-01-01T00:00:00Z",
    updated_at: "2024-01-01T00:00:00Z",
    ...overrides,
  };
}

// Wildcard-origin patterns so the same handlers match regardless of the
// jsdom test origin axios resolves the relative apiClient baseURL against.
export const handlers = [
  // Every signed-out form fetches Sanctum's CSRF cookie before it posts.
  // Sets the cookie as Sanctum does, so the client does not ask again.
  http.get("*/sanctum/csrf-cookie", () => {
    document.cookie = "XSRF-TOKEN=test; path=/";
    return new HttpResponse(null, { status: 204 });
  }),
  // The kiosk's waiting screen asks for a code to show beside the keypad
  // (KioskQrPanel); QR is off unless a test says otherwise.
  http.post("*/kiosk/qr", () => new HttpResponse(null, { status: 403 })),
  // The attendance page asks for the caller's own exceptions on every load
  // (MyExceptionsCard); none, unless a test says otherwise.
  http.get("*/api/v1/attendance/my-exceptions", () =>
    HttpResponse.json({
      period: { start: "2026-06-01", end: "2026-06-30" },
      exceptions: [],
    }),
  ),
  http.get("*/api/v1/employees", () => {
    const employees = [buildEmployee()];
    return HttpResponse.json({
      data: employees,
      meta: {
        current_page: 1,
        last_page: 1,
        per_page: 25,
        total: employees.length,
        from: 1,
        to: employees.length,
      },
      links: { first: "", last: "", prev: null, next: null },
    });
  }),

  http.get("*/api/v1/employees/:publicId", ({ params }) => {
    return HttpResponse.json(
      buildEmployee({ public_id: params.publicId as string }),
    );
  }),
  // The console's organisation page lists the feature switches for it
  // (TenantFeaturesCard); none, unless a test says otherwise.
  http.get("*/api/v1/admin/tenants/:publicId/features", () =>
    HttpResponse.json({ data: [] }),
  ),
  // ...and its journey card, and the organisations list its funnel: a new
  // organisation with nothing measured yet, unless a test says otherwise.
  http.get("*/api/v1/admin/tenants/journey-summary", () =>
    HttpResponse.json({ data: buildJourneySummary() }),
  ),
  http.get("*/api/v1/admin/tenants/:publicId/journey", () =>
    HttpResponse.json({ data: buildJourney() }),
  ),
];
