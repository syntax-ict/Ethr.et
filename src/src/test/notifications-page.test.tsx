import { describe, expect, it } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import NotificationsPage from "@/app/(dashboard)/notifications/page";

function notification(id: string, read: boolean) {
  return {
    id,
    type: "App\\Notifications\\Test",
    data: { title: `Notification ${id}`, message: `Notification ${id}` },
    read_at: read ? "2026-10-01T00:00:00Z" : null,
    created_at: "2026-10-01T00:00:00Z",
  };
}

// The page fetched the first 25 and offered nothing past them, and each card
// was a clickable div that the keyboard never reached (audit N83).
describe("notifications page", () => {
  it("pages past the first 25 and marks one read from the keyboard", async () => {
    const pagesAsked: string[] = [];
    let markedRead = "";
    server.use(
      http.get("*/api/v1/notifications", ({ request }) => {
        const page = new URL(request.url).searchParams.get("page") ?? "1";
        pagesAsked.push(page);
        return HttpResponse.json({
          data: [notification(`n${page}`, false)],
          meta: {
            current_page: Number(page),
            last_page: 2,
            per_page: 25,
            total: 26,
          },
          links: { first: null, last: null, prev: null, next: null },
        });
      }),
      http.put("*/api/v1/notifications/:id/read", ({ params }) => {
        markedRead = String(params.id);
        return HttpResponse.json({});
      }),
    );
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    render(
      <QueryClientProvider client={client}>
        <NotificationsPage />
      </QueryClientProvider>,
    );
    const user = userEvent.setup();

    await screen.findByText("Notification n1");
    await user.click(screen.getByRole("button", { name: /next/i }));
    await screen.findByText("Notification n2");
    expect(pagesAsked).toContain("2");

    const item = screen.getByRole("button", { name: /notification n2/i });
    item.focus();
    await user.keyboard("{Enter}");
    await waitFor(() => expect(markedRead).toBe("n2"));
  });
});
