import { describe, it, expect, afterEach } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import { PrintHeader } from "@/components/layouts/print-header";

/**
 * The line at the top of a printed page: organisation, page, date. On
 * screen it is hidden; the print stylesheet shows `.print-header`. The page
 * name is the document title without the product suffix.
 */
function renderHeader() {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "U1", name: "Test", role: "hr_admin" },
        tenant: {
          public_id: "T1",
          name: "Syntax Technology",
          timezone: "Africa/Addis_Ababa",
        },
        permissions: [],
      }),
    ),
  );
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <CalendarProvider>
        <PrintHeader />
      </CalendarProvider>
    </QueryClientProvider>,
  );
}

afterEach(() => {
  document.title = "";
});

describe("<PrintHeader>", () => {
  it("names the organisation, the page and today's date, hidden on screen", async () => {
    document.title = "Attendance · ETHR";
    const { container } = renderHeader();

    expect(await screen.findByText("Syntax Technology")).toBeInTheDocument();
    const header = container.querySelector(".print-header");
    expect(header).toHaveClass("hidden");
    expect(header).toHaveTextContent(/Syntax Technology · Attendance/);
    // The date is in the reader's calendar, so only its presence is pinned.
    expect(header?.lastElementChild?.textContent?.trim()).not.toBe("");
  });

  it("drops the page name when the document has none", async () => {
    const { container } = renderHeader();

    await screen.findByText("Syntax Technology");
    expect(container.querySelector(".print-header")).not.toHaveTextContent("·");
  });
});
