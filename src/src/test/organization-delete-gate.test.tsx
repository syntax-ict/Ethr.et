import { afterEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import OrganizationPage from "@/app/(dashboard)/organization/page";

const granted = vi.hoisted(() => ({ abilities: [] as string[] }));

vi.mock("next/navigation", () => ({
  usePathname: () => "/organization",
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), back: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
}));

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    role: "hr_admin",
    isAtLeast: () => true,
    hasRole: () => true,
    hasPermission: (ability: string) => granted.abilities.includes(ability),
    can: new Proxy({} as Record<string, boolean>, { get: () => true }),
  }),
}));

const BRANCH = {
  public_id: "01HZBRANCH0001",
  name: "Bole Branch",
  name_am: null,
  code: "BOLE",
  city: "Addis Ababa",
  is_active: true,
  employees_count: 3,
};

function serveOrganization() {
  const page = (data: unknown[]) => ({
    data,
    meta: { current_page: 1, last_page: 1, per_page: 100, total: data.length },
    links: { first: null, last: null, prev: null, next: null },
  });
  server.use(
    http.get("*/api/v1/organization/tree", () => HttpResponse.json([])),
    http.get("*/api/v1/organization/branches", () =>
      HttpResponse.json(page([BRANCH])),
    ),
  );
}

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <OrganizationPage />
    </QueryClientProvider>,
  );
}

async function openBranches() {
  await userEvent.click(await screen.findByRole("tab", { name: /branches/i }));
  await screen.findByText("Bole Branch");
}

// HR and finance admins may open the organisation page, create and edit, but
// `org.delete` is tenant-admin only. Every delete button was shown to them and
// every delete refused with a 403 (audit N55).
describe("deleting from the organisation page", () => {
  afterEach(() => {
    granted.abilities = [];
  });

  it("is not offered to someone without org.delete", async () => {
    granted.abilities = ["org.create", "org.update"];
    serveOrganization();
    renderPage();
    await openBranches();

    expect(
      screen.queryByRole("button", { name: /delete/i }),
    ).not.toBeInTheDocument();
  });

  it("is offered to someone who holds org.delete", async () => {
    granted.abilities = ["org.create", "org.update", "org.delete"];
    serveOrganization();
    renderPage();
    await openBranches();

    expect(screen.getByRole("button", { name: /delete/i })).toBeInTheDocument();
  });
});
