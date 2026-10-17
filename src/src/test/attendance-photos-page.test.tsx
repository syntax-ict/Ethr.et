import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import AttendancePhotosPage from "@/app/(dashboard)/attendance/photos/page";
import type { AttendancePhoto } from "@/features/attendance/api";

/**
 * The attendance-photo review page (docs/operations/ATTENDANCE-PHOTO-RETENTION.md):
 * when each selfie is kept until or deleted after, a reason required to hold
 * one, and only HR offered the release.
 */
const can = { manageAttendance: false, viewAttendancePhotos: true };
vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({ can }),
}));

vi.mock("@/features/auth/api", async (importOriginal) => ({
  ...(await importOriginal<object>()),
  useCurrentTenant: () => ({ data: { timezone: "Africa/Addis_Ababa" } }),
}));

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

function photo(overrides: Partial<AttendancePhoto> = {}): AttendancePhoto {
  return {
    public_id: "r1",
    date: "2026-10-13",
    check_in: "2026-10-13T05:00:00Z",
    check_out: "2026-10-13T14:00:00Z",
    employee: { public_id: "e1", name: "Abebe Kebede", employee_code: "E-1" },
    has_check_in_photo: true,
    has_check_out_photo: false,
    keep_until: "2026-10-20T14:00:00Z",
    review_started_at: null,
    delete_after: null,
    held: false,
    held_at: null,
    hold_reason: null,
    held_by: null,
    ...overrides,
  };
}

function list(rows: AttendancePhoto[]) {
  return HttpResponse.json({
    data: rows,
    meta: { current_page: 1, last_page: 1, per_page: 25, total: rows.length },
    links: { next: null, prev: null },
  });
}

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <AttendancePhotosPage />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  localStorage.setItem("locale", "en");
  can.manageAttendance = false;
});

describe("Attendance photos page", () => {
  it("says how long each photo is kept, and when one under review is deleted", async () => {
    server.use(
      http.get("*/attendance/photos", () =>
        list([
          photo(),
          photo({
            public_id: "r2",
            employee: {
              public_id: "e2",
              name: "Sara Haile",
              employee_code: null,
            },
            review_started_at: "2026-10-20T15:00:00Z",
            delete_after: "2026-10-22T15:00:00Z",
          }),
        ]),
      ),
    );
    renderPage();

    expect(await screen.findByText("Abebe Kebede")).toBeInTheDocument();
    expect(screen.getByText(/^Kept until/)).toBeInTheDocument();
    expect(screen.getByText(/^Deleted after/)).toBeInTheDocument();
    // Only the punches that have a selfie offer one.
    expect(
      screen.getAllByRole("button", { name: /Check-in photo/ }),
    ).toHaveLength(2);
    expect(
      screen.queryByRole("button", { name: /Check-out photo/ }),
    ).not.toBeInTheDocument();
  });

  it("requires a reason to hold a photo, and sends it", async () => {
    let body: Record<string, unknown> | null = null;
    server.use(
      http.get("*/attendance/photos", () => list([photo()])),
      http.post("*/attendance/photos/r1/hold", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(photo({ held: true, hold_reason: "x" }));
      }),
    );
    renderPage();

    fireEvent.click(
      await screen.findByRole("button", { name: /Hold for complaint/ }),
    );
    const confirm = screen.getByRole("button", { name: "Hold photo" });
    expect(confirm).toBeDisabled();

    fireEvent.change(screen.getByLabelText("Reason"), {
      target: { value: "Complaint about the late mark" },
    });
    fireEvent.click(confirm);

    await waitFor(() =>
      expect(body).toEqual({ reason: "Complaint about the late mark" }),
    );
  });

  it("offers the release only to HR", async () => {
    server.use(
      http.get("*/attendance/photos", () =>
        list([
          photo({
            held: true,
            hold_reason: "Open complaint",
            held_by: "Hana HR",
          }),
        ]),
      ),
    );
    const { unmount } = renderPage();
    expect(
      await screen.findByText("Held by Hana HR: Open complaint"),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: /Release hold/ }),
    ).not.toBeInTheDocument();
    unmount();

    can.manageAttendance = true;
    renderPage();
    expect(
      await screen.findByRole("button", { name: /Release hold/ }),
    ).toBeInTheDocument();
  });
});
