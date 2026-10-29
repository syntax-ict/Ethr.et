import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { PageTitleBar } from "@/components/layouts/page-title-bar";
import {
  PageActionsSlotProvider,
  useEntityTitle,
} from "@/components/layouts/page-actions-slot";

vi.mock("next/navigation", () => ({
  usePathname: () => "/employees/01HQZX3NDJKMNP4RSTVWXY5Z6A",
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), back: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
}));

function EmployeePage({ name }: { name?: string }) {
  useEntityTitle(name);
  return <h1>{name}</h1>;
}

/**
 * On a detail page the title bar repeated the list's title and description
 * ("Employees — Manage your organization's workforce") above the person's
 * own header, and the page added a separate "Back" button. It now ends its
 * breadcrumb with the record and leaves the heading to the page.
 */

// The title bar reads the person's permissions to name the sidebar section
// that holds the page, and those come from a query.
function withQueries({ children }: { children: ReactNode }) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}

describe("<PageTitleBar> on a detail page", () => {
  it("ends the breadcrumb with the record and links back to the list", async () => {
    render(
      <PageActionsSlotProvider>
        <PageTitleBar />
        <EmployeePage name="Abel Bauch" />
      </PageActionsSlotProvider>,
      { wrapper: withQueries },
    );

    const trail = await screen.findByRole("navigation", {
      name: /breadcrumb/i,
    });
    expect(
      await screen.findByText("Abel Bauch", { selector: "[aria-current]" }),
    ).toBeInTheDocument();
    expect(trail.querySelector('a[href="/employees"]')).not.toBeNull();

    // One heading: the page's, not a second "Employees" above it.
    expect(screen.getAllByRole("heading", { level: 1 })).toHaveLength(1);
    expect(
      screen.getByRole("heading", { level: 1, name: "Abel Bauch" }),
    ).toBeInTheDocument();
  });

  it("shows the list's title while the record is still loading", () => {
    render(
      <PageActionsSlotProvider>
        <PageTitleBar />
        <EmployeePage />
      </PageActionsSlotProvider>,
      { wrapper: withQueries },
    );

    expect(
      screen.getByRole("heading", { level: 1, name: /employees/i }),
    ).toBeInTheDocument();
  });
});
