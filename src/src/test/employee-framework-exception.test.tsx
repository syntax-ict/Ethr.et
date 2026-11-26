import { describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { buildEmployee } from "./msw/handlers";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import NewEmployeePage from "@/app/(dashboard)/employees/new/page";

const router = vi.hoisted(() => ({ push: vi.fn(), back: vi.fn() }));

vi.mock("next/navigation", () => ({
  useRouter: () => router,
  useSearchParams: () => new URLSearchParams(),
}));

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

function renderPage() {
  localStorage.setItem("ethr.calendar", "gregorian");
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={qc}>
      <CalendarProvider>
        <NewEmployeePage />
      </CalendarProvider>
    </QueryClientProvider>,
  );
}

const LABEL = "Not covered by the organisation's employment law";

describe("New employee form, employment law exception", () => {
  it("sends the flag and its note when ticked", async () => {
    let body: Record<string, unknown> | undefined;
    server.use(
      http.post("*/api/v1/employees", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(buildEmployee(), { status: 201 });
      }),
    );
    const { container } = renderPage();

    fireEvent.change(screen.getByLabelText(/Full Name/), {
      target: { value: "Hana Girma" },
    });
    fireEvent.change(document.getElementById("hire_date")!, {
      target: { value: "2026-10-01" },
    });
    expect(screen.queryByLabelText("Note (optional)")).toBeNull();
    fireEvent.click(screen.getByLabelText(LABEL));
    fireEvent.change(screen.getByLabelText("Note (optional)"), {
      target: { value: " General manager " },
    });
    fireEvent.submit(container.querySelector("form")!);

    await waitFor(() => expect(body).toBeDefined());
    expect(body).toMatchObject({
      outside_employment_framework: true,
      employment_framework_note: "General manager",
    });
  }, 30_000);

  it("sends neither field when the box is not ticked", async () => {
    let body: Record<string, unknown> | undefined;
    server.use(
      http.post("*/api/v1/employees", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(buildEmployee(), { status: 201 });
      }),
    );
    const { container } = renderPage();

    fireEvent.change(screen.getByLabelText(/Full Name/), {
      target: { value: "Hana Girma" },
    });
    fireEvent.change(document.getElementById("hire_date")!, {
      target: { value: "2026-10-01" },
    });
    fireEvent.submit(container.querySelector("form")!);

    await waitFor(() => expect(body).toBeDefined());
    expect(body).not.toHaveProperty("outside_employment_framework");
    expect(body).not.toHaveProperty("employment_framework_note");
  }, 30_000);
});
