import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { toast } from "sonner";
import { server } from "./msw/server";
import { AnnouncementsWidget } from "@/features/dashboard/components/announcements-widget";
import AnnouncementsPage from "@/app/(dashboard)/announcements/page";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

/**
 * Shaped like AnnouncementResource, which has no `status` field. The dashboard
 * widget filtered on `status === "published"`, so it dropped every row and
 * always said "No announcements" — the hand-written type declared a field the
 * API never sends, and nothing rendered the widget against a real shape.
 */
const ANNOUNCEMENT = {
  public_id: "ANN1",
  title: "Office closed on Meskel",
  body: "The office is closed for the holiday.",
  priority: "high",
  target_type: "all",
  published_at: "2026-09-25T06:00:00Z",
  expires_at: null,
  created_at: "2026-09-25T06:00:00Z",
};

const page = (rows: unknown[]) => ({
  data: rows,
  meta: { current_page: 1, last_page: 1, per_page: 25, total: rows.length },
  links: {},
});

function me(permissions: string[]) {
  return http.get("*/api/v1/auth/me", () =>
    HttpResponse.json({
      user: { public_id: "U1", name: "Custom Role User", role: "employee" },
      tenant: { public_id: "T1", name: "Demo", timezone: "Africa/Addis_Ababa" },
      permissions,
    }),
  );
}

function renderWithQuery(ui: ReactNode) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={queryClient}>{ui}</QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.mocked(toast.error).mockClear();
});

describe("<AnnouncementsWidget>", () => {
  it("shows published announcements returned by the API", async () => {
    server.use(
      me([]),
      http.get("*/api/v1/announcements", () =>
        HttpResponse.json(page([ANNOUNCEMENT])),
      ),
    );
    renderWithQuery(<AnnouncementsWidget />);

    expect(
      await screen.findByText("Office closed on Meskel"),
    ).toBeInTheDocument();
  });
});

describe("<AnnouncementsPage>", () => {
  it("names every form field for assistive technology", async () => {
    server.use(
      me(["announcement.manage"]),
      http.get("*/api/v1/announcements", () => HttpResponse.json(page([]))),
    );
    const user = userEvent.setup();
    renderWithQuery(<AnnouncementsPage />);

    await user.click(
      await screen.findByRole("button", { name: /New Announcement/ }),
    );

    expect(screen.getByRole("textbox", { name: "Title" })).toBeInTheDocument();
    expect(
      screen.getByRole("textbox", { name: "Content" }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("combobox", { name: "Priority" }),
    ).toBeInTheDocument();
  });

  it("offers management to a custom role holding announcement.manage alone", async () => {
    // The page gated on employee.create. The built-in roles hold both, so they
    // never disagreed — a custom role is where they come apart.
    server.use(
      me(["announcement.manage"]),
      http.get("*/api/v1/announcements", () =>
        HttpResponse.json(page([ANNOUNCEMENT])),
      ),
    );
    renderWithQuery(<AnnouncementsPage />);

    expect(
      await screen.findByRole("button", { name: /New Announcement/ }),
    ).toBeInTheDocument();
  });

  it("hides management from a role that can create employees but not manage announcements", async () => {
    server.use(
      me(["employee.create"]),
      http.get("*/api/v1/announcements", () =>
        HttpResponse.json(page([ANNOUNCEMENT])),
      ),
    );
    renderWithQuery(<AnnouncementsPage />);

    await screen.findByText("Office closed on Meskel");
    expect(
      screen.queryByRole("button", { name: /New Announcement/ }),
    ).not.toBeInTheDocument();
  });

  it("publishes immediately and refreshes the list", async () => {
    let posted: unknown = null;
    let rows: (typeof ANNOUNCEMENT)[] = [];
    server.use(
      me(["announcement.manage"]),
      http.get("*/api/v1/announcements", () => HttpResponse.json(page(rows))),
      http.post("*/api/v1/announcements", async ({ request }) => {
        posted = await request.json();
        rows = [{ ...ANNOUNCEMENT, title: "Payroll runs Friday" }];
        return HttpResponse.json(rows[0], { status: 201 });
      }),
    );
    const user = userEvent.setup();
    renderWithQuery(<AnnouncementsPage />);

    await user.click(
      await screen.findByRole("button", { name: /New Announcement/ }),
    );
    await user.type(
      screen.getByRole("textbox", { name: "Title" }),
      "Payroll runs Friday",
    );
    await user.type(
      screen.getByRole("textbox", { name: "Content" }),
      "Cut-off is Thursday.",
    );
    await user.click(screen.getByRole("button", { name: /Publish/ }));

    expect(await screen.findByText("Payroll runs Friday")).toBeInTheDocument();
    expect(posted).toEqual({
      title: "Payroll runs Friday",
      body: "Cut-off is Thursday.",
      priority: "normal",
      publish_now: true,
    });
  });

  it("says so when a delete fails", async () => {
    server.use(
      me(["announcement.manage"]),
      http.get("*/api/v1/announcements", () =>
        HttpResponse.json(page([ANNOUNCEMENT])),
      ),
      http.delete(
        "*/api/v1/announcements/ANN1",
        () => new HttpResponse(null, { status: 500 }),
      ),
    );
    const user = userEvent.setup();
    renderWithQuery(<AnnouncementsPage />);

    await user.click(await screen.findByRole("button", { name: "Delete" }));
    // Deleting is confirmed first (N70); the dialog's button is the last
    // "Delete" on screen.
    const confirm = await screen.findAllByRole("button", { name: "Delete" });
    await user.click(confirm[confirm.length - 1]);

    await waitFor(() => expect(toast.error).toHaveBeenCalled());
  });

  it("asks before deleting, and deletes nothing when cancelled", async () => {
    // Announcements are not soft-deleted, and one click on a bare icon used
    // to remove one for good (audit N70).
    let deleted = false;
    server.use(
      me(["announcement.manage"]),
      http.get("*/api/v1/announcements", () =>
        HttpResponse.json(page([ANNOUNCEMENT])),
      ),
      http.delete("*/api/v1/announcements/ANN1", () => {
        deleted = true;
        return new HttpResponse(null, { status: 204 });
      }),
    );
    const user = userEvent.setup();
    renderWithQuery(<AnnouncementsPage />);

    await user.click(await screen.findByRole("button", { name: "Delete" }));
    expect(await screen.findByText(/cannot be recovered/i)).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: /cancel/i }));

    expect(deleted).toBe(false);
  });
});
