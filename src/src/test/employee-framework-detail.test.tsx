import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { buildEmployee } from "./msw/handlers";
import { EmployeeDetail } from "@/app/(dashboard)/employees/[id]/employee-detail";
import { CalendarProvider } from "@/lib/calendar/calendar-context";

vi.mock("next/navigation", () => ({
  usePathname: () => "/employees/EMP1",
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), back: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
}));

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    role: "hr_admin",
    isAtLeast: () => true,
    hasRole: () => true,
    hasPermission: () => false,
    can: new Proxy({} as Record<string, boolean>, { get: () => true }),
  }),
}));

const LABEL = "Not covered by the organisation's employment law";

function renderDetail(employee: Record<string, unknown>) {
  const body: Record<string, unknown> = {
    ...buildEmployee({ public_id: "EMP1" }),
  };
  for (const [key, value] of Object.entries(employee)) {
    if (value === undefined) delete body[key];
    else body[key] = value;
  }
  server.use(
    http.get("*/api/v1/employees/EMP1", () => HttpResponse.json(body)),
  );
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <CalendarProvider>
        <EmployeeDetail routeId="EMP1" />
      </CalendarProvider>
    </QueryClientProvider>,
  );
}

async function startEdit() {
  fireEvent.click(await screen.findByRole("button", { name: /^edit$/i }));
}

describe("employee record, employment law exemption", () => {
  it("offers no exemption control when the server leaves the fields out", async () => {
    renderDetail({
      outside_employment_framework: undefined,
      employment_framework_note: undefined,
    });
    await startEdit();

    await screen.findByDisplayValue("Abebe Kebede");
    expect(screen.queryByLabelText(LABEL)).toBeNull();
    expect(screen.queryByText("Employment law")).toBeNull();
  });

  it("shows the exemption read-only, with its note, when the employee is outside", async () => {
    renderDetail({
      outside_employment_framework: true,
      employment_framework_note: "General manager",
    });

    expect(await screen.findByText("Employment law")).toBeInTheDocument();
    expect(screen.getByText(/General manager/)).toBeInTheDocument();
  });

  it("sends neither field when they did not change, and both when they did", async () => {
    const bodies: Record<string, unknown>[] = [];
    server.use(
      http.put("*/api/v1/employees/EMP1", async ({ request }) => {
        bodies.push((await request.json()) as Record<string, unknown>);
        return HttpResponse.json(buildEmployee({ public_id: "EMP1" }));
      }),
    );
    renderDetail({
      outside_employment_framework: false,
      employment_framework_note: null,
    });

    await startEdit();
    await screen.findByLabelText(LABEL);
    fireEvent.click(screen.getByRole("button", { name: /^save$/i }));
    await waitFor(() => expect(bodies).toHaveLength(1));
    expect(bodies[0]).not.toHaveProperty("outside_employment_framework");
    expect(bodies[0]).not.toHaveProperty("employment_framework_note");

    fireEvent.click(await screen.findByRole("button", { name: /^edit$/i }));
    fireEvent.click(await screen.findByLabelText(LABEL));
    fireEvent.change(screen.getByLabelText("Note (optional)"), {
      target: { value: "Special law" },
    });
    fireEvent.click(screen.getByRole("button", { name: /^save$/i }));
    await waitFor(() => expect(bodies).toHaveLength(2));
    expect(bodies[1]).toMatchObject({
      outside_employment_framework: true,
      employment_framework_note: "Special law",
    });
  }, 30_000);
});
