import { describe, it, expect, beforeEach, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import AnnouncementsPage from "@/app/(dashboard)/announcements/page";
import { AnnouncementsWidget } from "@/features/dashboard/components/announcements-widget";
import { safeInternalPath } from "@/lib/utils/safe-path";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

/**
 * Reading an announcement (2026-11-22). Until this, an employee could not open
 * one at all: the list clamped the body to two lines and only a manager had
 * the preview, so the notification that said an announcement was published
 * led to a list of first lines.
 */
const STORY = {
  public_id: "ANN1",
  title: "Office closed on Meskel",
  body: "The office is closed.\n\nEnjoy the holiday.",
  excerpt: "The office is closed.",
  category: "notice",
  priority: "normal",
  target_type: "all",
  status: "published",
  show_on_landing: false,
  featured: false,
  cover: null,
  attachments: [],
  author: { name: "Hanna Bekele" },
  published_at: "2026-09-25T06:00:00Z",
  expires_at: null,
  created_at: "2026-09-25T06:00:00Z",
  updated_at: "2026-09-25T06:00:00Z",
};

const page = (rows: unknown[]) => ({
  data: rows,
  meta: { current_page: 1, last_page: 1, per_page: 25, total: rows.length },
  links: {},
});

function me(permissions: string[]) {
  return http.get("*/api/v1/auth/me", () =>
    HttpResponse.json({
      user: { public_id: "U1", name: "Reader", role: "employee" },
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
  window.history.replaceState(null, "", "/announcements");
});

describe("the reading pane", () => {
  it("lets a reader with no permissions open a story in full", async () => {
    server.use(
      me([]),
      http.get("*/api/v1/announcements", () =>
        HttpResponse.json(page([STORY])),
      ),
      http.get("*/api/v1/announcements/ANN1", () => HttpResponse.json(STORY)),
    );
    const user = userEvent.setup();
    renderWithQuery(<AnnouncementsPage />);

    await user.click(
      await screen.findByRole("button", { name: "Office closed on Meskel" }),
    );

    const pane = await screen.findByRole("dialog");
    await waitFor(() => expect(pane).toHaveTextContent("Enjoy the holiday."));
    expect(pane).toHaveTextContent("Hanna Bekele");
    // The address carries the story, so it can be sent to a colleague.
    expect(window.location.search).toContain("read=ANN1");
  });

  it("opens straight from an address that names a story", async () => {
    window.history.replaceState(null, "", "/announcements?read=ANN1");
    server.use(
      me([]),
      http.get("*/api/v1/announcements", () =>
        HttpResponse.json(page([STORY])),
      ),
      http.get("*/api/v1/announcements/ANN1", () => HttpResponse.json(STORY)),
    );
    renderWithQuery(<AnnouncementsPage />);

    const pane = await screen.findByRole("dialog");
    await waitFor(() => expect(pane).toHaveTextContent("Enjoy the holiday."));
  });

  it("says a story is not available rather than which reason it is", async () => {
    window.history.replaceState(null, "", "/announcements?read=GONE");
    server.use(
      me([]),
      http.get("*/api/v1/announcements", () =>
        HttpResponse.json(page([STORY])),
      ),
      // A draft, a story for another department and a missing id all answer
      // the same way, which is what stops a public id being confirmed.
      http.get("*/api/v1/announcements/GONE", () =>
        HttpResponse.json({ detail: "Not Found" }, { status: 404 }),
      ),
    );
    renderWithQuery(<AnnouncementsPage />);

    expect(
      await screen.findByText("This announcement is not available"),
    ).toBeInTheDocument();
  });

  it("says the connection failed rather than that the story was taken down", async () => {
    window.history.replaceState(null, "", "/announcements?read=ANN1");
    server.use(
      me([]),
      http.get("*/api/v1/announcements", () =>
        HttpResponse.json(page([STORY])),
      ),
      // Only a 404 means the story is not this reader's to see. Telling them a
      // notice may have been withdrawn because the connection dropped would
      // have them believe a withdrawal that never happened.
      http.get("*/api/v1/announcements/ANN1", () =>
        HttpResponse.json({ detail: "Server Error" }, { status: 500 }),
      ),
    );
    renderWithQuery(<AnnouncementsPage />);

    const pane = await screen.findByRole("dialog");
    await waitFor(() =>
      expect(
        screen.queryByText("This announcement is not available"),
      ).not.toBeInTheDocument(),
    );
    expect(pane).toHaveTextContent("Couldn't load this");
    expect(pane).toHaveTextContent("Try again");
  });

  it("keeps the featured mark out of a reader's view", async () => {
    window.history.replaceState(null, "", "/announcements?read=ANN1");
    const featured = { ...STORY, featured: true, show_on_landing: true };
    server.use(
      me([]),
      http.get("*/api/v1/announcements", () =>
        HttpResponse.json(page([featured])),
      ),
      http.get("*/api/v1/announcements/ANN1", () =>
        HttpResponse.json(featured),
      ),
    );
    renderWithQuery(<AnnouncementsPage />);

    const pane = await screen.findByRole("dialog");
    await waitFor(() => expect(pane).toHaveTextContent("Enjoy the holiday."));
    // "Featured" means first on the organisation's public website. It is the
    // publishing desk's business, and the dashboard card already withholds it.
    expect(pane).not.toHaveTextContent("Featured");
  });

  it("tells a manager that what they are reading is still a draft", async () => {
    window.history.replaceState(null, "", "/announcements?read=ANN1");
    const draft = { ...STORY, status: "draft", published_at: null };
    server.use(
      me(["announcement.manage"]),
      http.get("*/api/v1/announcements", () =>
        HttpResponse.json(page([draft])),
      ),
      http.get("*/api/v1/announcements/ANN1", () => HttpResponse.json(draft)),
    );
    renderWithQuery(<AnnouncementsPage />);

    const pane = await screen.findByRole("dialog");
    await waitFor(() => expect(pane).toHaveTextContent("Enjoy the holiday."));
    expect(pane).toHaveTextContent("Draft");
  });

  it("offers a document the reader can fetch, though it has no public address", async () => {
    const withFile = {
      ...STORY,
      attachments: [{ id: "F1", name: "Circular.pdf", size: 2048, url: null }],
    };
    server.use(
      me([]),
      http.get("*/api/v1/announcements", () =>
        HttpResponse.json(page([withFile])),
      ),
      http.get("*/api/v1/announcements/ANN1", () =>
        HttpResponse.json(withFile),
      ),
    );
    const user = userEvent.setup();
    renderWithQuery(<AnnouncementsPage />);

    await user.click(
      await screen.findByRole("button", { name: "Office closed on Meskel" }),
    );

    // A button, not an inert row: in the app the download goes through the
    // API as a blob, so `url` is null and a link would lead nowhere.
    expect(
      await screen.findByRole("button", { name: /Circular\.pdf/ }),
    ).toBeInTheDocument();
  });
});

describe("where a notification and the dashboard card lead", () => {
  it("links the dashboard card at the story, not at the list", async () => {
    server.use(
      me([]),
      http.get("*/api/v1/announcements", () =>
        HttpResponse.json(page([STORY])),
      ),
    );
    renderWithQuery(<AnnouncementsWidget />);

    const card = await screen.findByRole("link", {
      name: /Office closed on Meskel/,
    });
    expect(card).toHaveAttribute("href", "/announcements?read=ANN1");
  });

  it("keeps the query string on a notification's address", () => {
    // The server stores `/announcements?read=<id>`; a sanitiser that dropped
    // the query would land every reader on the list again.
    expect(safeInternalPath("/announcements?read=ANN1")).toBe(
      "/announcements?read=ANN1",
    );
  });
});
