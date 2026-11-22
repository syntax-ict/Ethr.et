import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { AnnouncementsWidget } from "@/features/dashboard/components/announcements-widget";
import AnnouncementsPage from "@/app/(dashboard)/announcements/page";
import { NewsMeta } from "@/features/news/components/news-card";
import { StatusBadge } from "@/components/shared/status-badge";
import { statusToneOf } from "@/lib/status/registry";

/**
 * One announcement, one presentation (2026-11-22): the app's list, the
 * dashboard card and the public news card said the same priority three ways
 * ("High" in the app, "Important" on the public page, no word for low) and
 * shortened the text three ways. They now share `NewsMeta` and the server's
 * excerpt.
 */
const STORY = {
  public_id: "ANN1",
  title: "Office closed on Meskel",
  body: "FULL BODY that the list must not print",
  excerpt: "The office is closed for the holiday.",
  priority: "high",
  category: "notice",
  featured: false,
  status: "published",
  target_type: "all",
  show_on_landing: false,
  cover: null,
  attachments: [],
  published_at: "2026-09-25T06:00:00Z",
  expires_at: null,
  created_at: "2026-09-25T06:00:00Z",
};

const page = (rows: unknown[]) => ({
  data: rows,
  meta: { current_page: 1, last_page: 1, per_page: 25, total: rows.length },
  links: {},
});

function renderWithQuery(ui: ReactNode) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={queryClient}>{ui}</QueryClientProvider>,
  );
}

describe("one announcement presentation", () => {
  it("says Important for a high priority story on the dashboard card, the list and the public card", async () => {
    server.use(
      http.get("*/api/v1/announcements", () =>
        HttpResponse.json(page([STORY])),
      ),
    );

    const widget = renderWithQuery(<AnnouncementsWidget />);
    expect(await screen.findByText("Important")).toBeInTheDocument();
    widget.unmount();

    const list = renderWithQuery(<AnnouncementsPage />);
    expect(await screen.findByText("Important")).toBeInTheDocument();
    list.unmount();

    render(<NewsMeta item={STORY as never} />);
    expect(screen.getByText("Important")).toBeInTheDocument();
  });

  it("shows the server's excerpt in the list, never the whole body", async () => {
    server.use(
      http.get("*/api/v1/announcements", () =>
        HttpResponse.json(page([STORY])),
      ),
    );
    renderWithQuery(<AnnouncementsPage />);

    expect(
      await screen.findByText("The office is closed for the holiday."),
    ).toBeInTheDocument();
    expect(screen.queryByText(/FULL BODY/)).not.toBeInTheDocument();
  });

  it("gives a normal priority story no priority word", () => {
    render(<NewsMeta item={{ ...STORY, priority: "normal" } as never} />);

    expect(screen.queryByText("Important")).not.toBeInTheDocument();
    expect(screen.queryByText("Urgent")).not.toBeInTheDocument();
  });
});

describe("the announcement status domain", () => {
  it("colours each state from the shared registry", () => {
    expect(statusToneOf("draft", "announcement")).toBe("neutral");
    expect(statusToneOf("scheduled", "announcement")).toBe("info");
    expect(statusToneOf("published", "announcement")).toBe("success");
    expect(statusToneOf("archived", "announcement")).toBe("neutral");
  });

  it("draws the registry's word, or the caller's more exact one", () => {
    const { rerender } = render(
      <StatusBadge status="published" domain="announcement" />,
    );
    expect(screen.getByText("Published")).toBeInTheDocument();

    rerender(
      <StatusBadge
        status="scheduled"
        domain="announcement"
        label="Scheduled for 1 Jan"
      />,
    );
    expect(screen.getByText("Scheduled for 1 Jan")).toBeInTheDocument();
  });
});
