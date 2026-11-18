import { describe, it, expect, afterEach } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import { PrintHeader } from "@/components/layouts/print-header";
import { usePrintTitle } from "@/components/layouts/print-title";

/**
 * The frame at the top of a printed page: organisation (and logo), the
 * page's title, when it was printed and by whom. On screen it is hidden;
 * the print stylesheet shows `.print-header`.
 */
function renderHeader({
  logo = null as string | null,
  page = null as string | null,
} = {}) {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "U1", name: "Almaz Tesfaye", role: "hr_admin" },
        tenant: {
          public_id: "T1",
          name: "Syntax Technology",
          timezone: "Africa/Addis_Ababa",
          logo_path: logo,
        },
        permissions: [],
      }),
    ),
  );
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });

  function Page() {
    usePrintTitle(page);
    return null;
  }

  return render(
    <QueryClientProvider client={client}>
      <CalendarProvider>
        <PrintHeader />
        <Page />
      </CalendarProvider>
    </QueryClientProvider>,
  );
}

afterEach(() => {
  document.title = "";
});

describe("<PrintHeader>", () => {
  it("names the organisation and the page, hidden on screen", async () => {
    document.title = "Attendance · ETHR";
    const { container } = renderHeader();

    expect(await screen.findByText("Syntax Technology")).toBeInTheDocument();
    const header = container.querySelector(".print-header");
    expect(header).toHaveClass("hidden");
    expect(header?.querySelector(".print-header-org")).toHaveTextContent(
      "Syntax Technology",
    );
    expect(header?.querySelector(".print-header-title")).toHaveTextContent(
      "Attendance",
    );
  });

  it("says when it was printed and by whom", async () => {
    const { container } = renderHeader();

    await screen.findByText("Syntax Technology");
    const meta = container.querySelector(".print-header-meta");
    expect(await screen.findByText("by Almaz Tesfaye")).toBeInTheDocument();
    // The date and time are in the reader's calendar and clock, so only
    // their presence is pinned.
    expect(meta?.firstElementChild?.textContent).toMatch(/^Printed \S+/);
  });

  it("carries the organisation's logo when it has one", async () => {
    const { container } = renderHeader({ logo: "https://x.test/logo.png" });

    await screen.findByText("Syntax Technology");
    expect(container.querySelector("img.print-header-logo")).toHaveAttribute(
      "src",
      "https://x.test/logo.png",
    );
  });

  it("draws no logo when there is none", async () => {
    const { container } = renderHeader();

    await screen.findByText("Syntax Technology");
    expect(container.querySelector("img")).toBeNull();
  });

  it("lets a page name itself instead of the document title", async () => {
    document.title = "Employees · ETHR";
    const { container } = renderHeader({ page: "Abebe Kebede · EMP-0042" });

    await screen.findByText("Syntax Technology");
    expect(container.querySelector(".print-header-title")).toHaveTextContent(
      "Abebe Kebede · EMP-0042",
    );
  });

  it("drops the title line when the document has none", async () => {
    const { container } = renderHeader();

    await screen.findByText("Syntax Technology");
    expect(container.querySelector(".print-header-title")).toBeNull();
  });
});
