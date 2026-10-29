import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import am from "@/lib/i18n/locales/am.json";
import { PageTitleBar } from "@/components/layouts/page-title-bar";
import { useDocumentTitle } from "@/lib/hooks/useDocumentTitle";

// A detail page (/employees/<ULID>) borrows its parent's route metadata, and its
// heading, breadcrumb and browser tab must be translated like the parent's. They
// were not: the translation key was built from the URL, `route.employees.<ULID>`,
// which exists in no dictionary, so an Amharic user got the English fallback on
// every record page (live test against the local production rehearsal,
// 2026-10-06), while the list page above it was in Amharic.

const path = vi.hoisted(() => ({
  value: "/employees/01M496SQ1JPXTTW187E0Y0TTZW",
}));

vi.mock("next/navigation", () => ({
  usePathname: () => path.value,
}));

const dict = am as Record<string, string>;

function TitleProbe() {
  useDocumentTitle();
  return null;
}

beforeEach(() => {
  localStorage.clear();
  localStorage.setItem("locale", "am");
  path.value = "/employees/01M496SQ1JPXTTW187E0Y0TTZW";
});

// The title bar reads the person's permissions to name the sidebar section
// that holds the page, and those come from a query.
function withQueries({ children }: { children: ReactNode }) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}

describe("detail pages are translated like the section they belong to", () => {
  it("translates the list page (control: the harness itself translates)", async () => {
    path.value = "/employees";
    render(<PageTitleBar />, { wrapper: withQueries });

    expect(
      await screen.findByRole("heading", {
        level: 1,
        name: dict["route.employees.label"],
      }),
    ).toBeInTheDocument();
  });

  it("heads an employee record with the Amharic section name", async () => {
    render(<PageTitleBar />, { wrapper: withQueries });

    expect(
      await screen.findByRole("heading", {
        level: 1,
        name: dict["route.employees.label"],
      }),
    ).toBeInTheDocument();
    expect(screen.getByText(dict["route.employees.desc"])).toBeInTheDocument();
    expect(
      screen.queryByText("Manage your organization's workforce"),
    ).toBeNull();
  });

  it("titles the browser tab of a payroll run in Amharic", async () => {
    path.value = "/payroll/01M496VPDBNR3N3SW52E5SK3C7";
    render(<TitleProbe />);

    await waitFor(() =>
      expect(document.title.startsWith(dict["route.payroll.label"])).toBe(true),
    );
  });

  // Next writes a route's streamed metadata title after a client navigation,
  // which replaced this hook's title on /admin/tenants/<id> for good
  // (production, 2026-10-28).
  it("puts its title back when something later replaces it", async () => {
    path.value = "/admin/tenants/01M4NNB7QQY1CS1MR5S1DWWWCH";
    render(<TitleProbe />);

    const expected = dict["route.admin.tenants.label"];
    await waitFor(() => expect(document.title.startsWith(expected)).toBe(true));

    document.title = "ETHR | HR and Payroll Software for Ethiopia";

    await waitFor(() => expect(document.title.startsWith(expected)).toBe(true));
  });
});
