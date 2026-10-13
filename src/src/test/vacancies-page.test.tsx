import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import VacanciesPage from "@/app/(dashboard)/vacancies/page";
import type { Vacancy } from "@/features/vacancies/api";

/**
 * Vacancies inside the app: employees read what is open; whoever holds
 * `vacancy.manage` sees every state and can post, close and reopen.
 */
const canManage = vi.fn(() => false);
vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({ can: { manageVacancies: canManage() } }),
}));

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

function vacancy(overrides: Partial<Vacancy>): Vacancy {
  return {
    public_id: "v1",
    title: "Machine operator",
    employment_type: "full_time",
    location: null,
    description: "Run the line.",
    requirements: null,
    positions: 1,
    visibility: "internal",
    status: "open",
    published_at: "2026-10-10T08:00:00Z",
    closes_on: null,
    apply_email: null,
    apply_url: null,
    department: null,
    branch: null,
    created_at: "2026-10-10T08:00:00Z",
    ...overrides,
  };
}

function page(rows: Vacancy[]) {
  return {
    data: rows,
    meta: { current_page: 1, last_page: 1, total: rows.length },
  };
}

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <VacanciesPage />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  canManage.mockReturnValue(false);
  localStorage.setItem("locale", "en");
});

describe("Vacancies page", () => {
  it("shows an employee the open positions, without managing controls", async () => {
    server.use(
      http.get("*/vacancies", () => HttpResponse.json(page([vacancy({})]))),
    );

    renderPage();

    expect(await screen.findByText("Machine operator")).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: /post vacancy/i }),
    ).not.toBeInTheDocument();
    expect(screen.queryByRole("tab")).not.toBeInTheDocument();
  });

  it("lets HR filter by state and close an open vacancy", async () => {
    canManage.mockReturnValue(true);
    let body: Record<string, unknown> | null = null;
    server.use(
      http.get("*/vacancies", () =>
        HttpResponse.json(
          page([
            vacancy({}),
            vacancy({
              public_id: "v2",
              title: "Draft role",
              status: "draft",
              published_at: null,
            }),
          ]),
        ),
      ),
      http.put("*/vacancies/v1", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(vacancy({ status: "closed" }));
      }),
    );

    renderPage();

    expect(await screen.findByText("Machine operator")).toBeInTheDocument();
    expect(screen.queryByText("Draft role")).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole("tab", { name: /draft/i }));
    expect(screen.getByText("Draft role")).toBeInTheDocument();

    fireEvent.click(screen.getByRole("tab", { name: /open/i }));
    fireEvent.click(screen.getByRole("button", { name: /^close$/i }));
    await waitFor(() => expect(body).toEqual({ status: "closed" }));
  });
});
