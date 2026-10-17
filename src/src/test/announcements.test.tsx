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
    await user.click(screen.getByRole("button", { name: /^Publish$/ }));

    expect(await screen.findByText("Payroll runs Friday")).toBeInTheDocument();
    expect(posted).toEqual({
      title: "Payroll runs Friday",
      body: "Cut-off is Thursday.",
      priority: "normal",
      category: null,
      show_on_landing: false,
      featured: false,
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

    await user.click(
      await screen.findByRole("button", { name: /^Delete: Office closed/ }),
    );
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

    await user.click(
      await screen.findByRole("button", { name: /^Delete: Office closed/ }),
    );
    expect(await screen.findByText(/cannot be recovered/i)).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: /cancel/i }));

    expect(deleted).toBe(false);
  });
});

/**
 * The publishing desk (2026-10-16): every state for a manager, one-click
 * publish and unpublish, the public-website and featured switches, and the
 * rule that only a whole-organisation announcement can be public.
 */
describe("<AnnouncementsPage> publishing", () => {
  const DRAFT = {
    ...ANNOUNCEMENT,
    public_id: "ANN2",
    title: "Canteen reopens",
    status: "draft",
    published_at: null,
    show_on_landing: true,
    featured: true,
    category: "notice",
  };

  it("asks for every state and shows each one, with its website flags", async () => {
    let manageAsked = false;
    server.use(
      me(["announcement.manage"]),
      http.get("*/api/v1/announcements", ({ request }) => {
        manageAsked ||= new URL(request.url).searchParams.get("manage") === "1";
        return HttpResponse.json(
          page([{ ...ANNOUNCEMENT, status: "published" }, DRAFT]),
        );
      }),
    );
    renderWithQuery(<AnnouncementsPage />);

    expect(await screen.findByText("Canteen reopens")).toBeInTheDocument();
    expect(manageAsked).toBe(true);
    expect(screen.getAllByText("Draft").length).toBeGreaterThan(0);
    expect(screen.getByText("Published")).toBeInTheDocument();
    expect(screen.getByText("On website")).toBeInTheDocument();
    expect(screen.getByText("Featured")).toBeInTheDocument();
  });

  it("publishes a draft in one click", async () => {
    let put: Record<string, unknown> | null = null;
    server.use(
      me(["announcement.manage"]),
      http.get("*/api/v1/announcements", () =>
        HttpResponse.json(page([DRAFT])),
      ),
      http.put("*/api/v1/announcements/ANN2", async ({ request }) => {
        put = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ ...DRAFT, status: "published" });
      }),
    );
    const user = userEvent.setup();
    renderWithQuery(<AnnouncementsPage />);

    await user.click(
      await screen.findByRole("button", { name: /publish now/i }),
    );

    await waitFor(() => expect(put).not.toBeNull());
    expect(typeof put!.published_at).toBe("string");
  });

  it("marks a new story for the website and features it", async () => {
    let posted: Record<string, unknown> | null = null;
    server.use(
      me(["announcement.manage"]),
      http.get("*/api/v1/announcements", () => HttpResponse.json(page([]))),
      http.post("*/api/v1/announcements", async ({ request }) => {
        posted = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(
          { ...ANNOUNCEMENT, public_id: "ANN3" },
          { status: 201 },
        );
      }),
    );
    const user = userEvent.setup();
    renderWithQuery(<AnnouncementsPage />);

    await user.click(
      await screen.findByRole("button", { name: /New Announcement/ }),
    );
    await user.type(screen.getByRole("textbox", { name: "Title" }), "Open day");
    await user.type(
      screen.getByRole("textbox", { name: "Content" }),
      "Visit us.",
    );

    const featured = screen.getByRole("switch", { name: /feature it/i });
    // Featuring needs the story to be public first.
    expect(featured).toBeDisabled();
    await user.click(screen.getByRole("switch", { name: /public website/i }));
    expect(featured).toBeEnabled();
    await user.click(featured);
    await user.click(screen.getByRole("button", { name: /^Publish$/ }));

    await waitFor(() => expect(posted).not.toBeNull());
    expect(posted).toMatchObject({
      show_on_landing: true,
      featured: true,
      publish_now: true,
    });
  });

  it("uploads a chosen cover photo once the new story is saved, and previews it", async () => {
    const created = vi.fn();
    const uploaded = vi.fn();
    // jsdom has no object URLs; the page only needs one for its preview.
    const { createObjectURL, revokeObjectURL } = URL;
    URL.createObjectURL = vi.fn(() => "blob:cover");
    URL.revokeObjectURL = vi.fn();
    server.use(
      me(["announcement.manage"]),
      http.get("*/api/v1/announcements", () => HttpResponse.json(page([]))),
      http.post("*/api/v1/announcements", () => {
        created();
        return HttpResponse.json(
          { ...ANNOUNCEMENT, public_id: "ANN3", cover: null },
          { status: 201 },
        );
      }),
      http.post("*/api/v1/announcements/ANN3/cover", () => {
        uploaded();
        return HttpResponse.json({ ...ANNOUNCEMENT, public_id: "ANN3" });
      }),
    );
    const user = userEvent.setup();
    renderWithQuery(<AnnouncementsPage />);

    await user.click(
      await screen.findByRole("button", { name: /New Announcement/ }),
    );
    await user.type(screen.getByRole("textbox", { name: "Title" }), "Open day");
    await user.type(
      screen.getByRole("textbox", { name: "Content" }),
      "Visit us.",
    );
    await user.upload(
      screen.getByLabelText("Cover photo", { selector: "input" }),
      new File(["photo"], "opening.jpg", { type: "image/jpeg" }),
    );
    expect(screen.getByRole("img", { name: "Cover photo" })).toHaveAttribute(
      "src",
      "blob:cover",
    );
    expect(screen.getByRole("button", { name: "Remove" })).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /^Publish$/ }));

    await waitFor(() => expect(uploaded).toHaveBeenCalledTimes(1));
    expect(created).toHaveBeenCalledTimes(1);
    URL.createObjectURL = createObjectURL;
    URL.revokeObjectURL = revokeObjectURL;
  });

  it("attaches chosen PDFs once the new story is saved, and refuses anything else", async () => {
    const uploaded = vi.fn();
    server.use(
      me(["announcement.manage"]),
      http.get("*/api/v1/announcements", () => HttpResponse.json(page([]))),
      http.post("*/api/v1/announcements", () =>
        HttpResponse.json(
          { ...ANNOUNCEMENT, public_id: "ANN3", cover: null, attachments: [] },
          { status: 201 },
        ),
      ),
      http.post("*/api/v1/announcements/ANN3/files", () => {
        uploaded();
        return HttpResponse.json({ ...ANNOUNCEMENT, public_id: "ANN3" });
      }),
    );
    const user = userEvent.setup({ applyAccept: false });
    renderWithQuery(<AnnouncementsPage />);

    await user.click(
      await screen.findByRole("button", { name: /New Announcement/ }),
    );
    await user.type(screen.getByRole("textbox", { name: "Title" }), "Circular");
    await user.type(
      screen.getByRole("textbox", { name: "Content" }),
      "Read the attached.",
    );
    const input = screen.getByLabelText("Documents", { selector: "input" });
    await user.upload(
      input,
      new File(["x"], "photo.png", { type: "image/png" }),
    );
    expect(toast.error).toHaveBeenCalledWith("Choose a PDF document.");
    await user.upload(
      input,
      new File(["%PDF-1.4"], "Leave circular.pdf", { type: "application/pdf" }),
    );
    await user.upload(
      input,
      new File(["%PDF-1.4"], "Form.pdf", { type: "application/pdf" }),
    );
    expect(screen.getByText("Leave circular.pdf")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Remove: Form.pdf" }));
    expect(screen.queryByText("Form.pdf")).toBeNull();

    await user.click(screen.getByRole("button", { name: /^Publish$/ }));

    await waitFor(() => expect(uploaded).toHaveBeenCalledTimes(1));
  });

  it("lists a story's documents for readers to download", async () => {
    server.use(
      me([]),
      http.get("*/api/v1/announcements", () =>
        HttpResponse.json(
          page([
            {
              ...ANNOUNCEMENT,
              attachments: [
                {
                  id: "F1",
                  name: "Holiday rota.pdf",
                  size: 2_400_000,
                  url: null,
                },
              ],
            },
          ]),
        ),
      ),
    );
    renderWithQuery(<AnnouncementsPage />);

    const button = await screen.findByRole("button", {
      name: /holiday rota\.pdf/i,
    });
    expect(button).toHaveTextContent("2.3 MB");
  });

  it("keeps an announcement for one department off the website", async () => {
    server.use(
      me(["announcement.manage"]),
      http.get("*/api/v1/announcements", () =>
        HttpResponse.json(
          page([
            { ...ANNOUNCEMENT, target_type: "department", status: "published" },
          ]),
        ),
      ),
    );
    const user = userEvent.setup();
    renderWithQuery(<AnnouncementsPage />);

    await user.click(
      await screen.findByRole("button", { name: /^Edit: Office closed/ }),
    );

    expect(
      screen.getByRole("switch", { name: /public website/i }),
    ).toBeDisabled();
    expect(
      screen.getByText(
        /only announcements for the whole organisation can be public/i,
      ),
    ).toBeInTheDocument();
  });

  it("previews a story exactly as the news page shows it, links included", async () => {
    server.use(
      me(["announcement.manage"]),
      http.get("*/api/v1/announcements", () =>
        HttpResponse.json(
          page([
            {
              ...DRAFT,
              body: "First part.\n\nDetails at https://acme.example/menu.",
            },
          ]),
        ),
      ),
    );
    const user = userEvent.setup();
    renderWithQuery(<AnnouncementsPage />);

    await user.click(
      await screen.findByRole("button", { name: /^Preview: Canteen reopens/ }),
    );

    const dialog = await screen.findByRole("dialog");
    expect(dialog).toHaveTextContent("First part.");
    expect(
      screen.getByRole("link", { name: /acme\.example\/menu/ }),
    ).toHaveAttribute("href", "https://acme.example/menu");
  });
});
