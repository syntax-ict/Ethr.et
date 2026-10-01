import { describe, it, expect } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { LifecycleTab } from "@/features/employees/components/lifecycle-tab";
import { CalendarProvider } from "@/lib/calendar/calendar-context";

const EMPLOYEE_ID = "01HZEMPLOYEE0000000000001";
const TRANSITIONS_URL = `*/api/v1/employees/${EMPLOYEE_ID}/transitions`;
const TRANSITION_URL = `*/api/v1/employees/${EMPLOYEE_ID}/transition`;

/** The shape `EmployeeTransitionResource` renders — nothing more. */
function buildTransition(overrides: Record<string, unknown> = {}) {
  return {
    public_id: "01HZTRANSITION00000000001",
    from_status: "hired",
    to_status: "probation",
    reason: "Started probation period",
    effective_date: "2026-01-05",
    approved_by: {
      public_id: "01HZUSER00000000000000001",
      name: "Hiwot Tadesse",
      employee_code: null,
      photo_path: null,
      photo_url: null,
      photo_thumb_url: null,
    },
    created_at: "2026-01-05T08:00:00Z",
    ...overrides,
  };
}

function hrAdmin() {
  return http.get("*/api/v1/auth/me", () =>
    HttpResponse.json({
      user: { public_id: "U1", name: "HR Admin", role: "hr_admin" },
      tenant: { public_id: "T1", name: "Demo", timezone: "Africa/Addis_Ababa" },
      permissions: ["employee.create", "employee.view"],
    }),
  );
}

function renderTab(currentStatus = "probation") {
  // The effective-date field is a DualCalendarDateInput, which defaults to
  // Ethiopian entry mode unless a preference is stored.
  localStorage.setItem("ethr.calendar", "gregorian");
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>
      <CalendarProvider>{children}</CalendarProvider>
    </QueryClientProvider>
  );
  return render(
    <LifecycleTab employeeId={EMPLOYEE_ID} currentStatus={currentStatus} />,
    { wrapper: Wrapper },
  );
}

describe("<LifecycleTab>", () => {
  it("lists the history with who approved each move", async () => {
    server.use(
      hrAdmin(),
      http.get(TRANSITIONS_URL, () => HttpResponse.json([buildTransition()])),
    );
    renderTab();

    expect(
      await screen.findByText("Started probation period"),
    ).toBeInTheDocument();
    expect(screen.getByText("by Hiwot Tadesse")).toBeInTheDocument();
  });

  it("posts the chosen status and refreshes the history", async () => {
    let listCalls = 0;
    const bodies: Array<Record<string, unknown>> = [];
    server.use(
      hrAdmin(),
      http.get(TRANSITIONS_URL, () => {
        listCalls++;
        return HttpResponse.json([]);
      }),
      http.post(TRANSITION_URL, async ({ request }) => {
        bodies.push((await request.json()) as Record<string, unknown>);
        return HttpResponse.json(
          buildTransition({ from_status: "probation", to_status: "confirmed" }),
          { status: 201 },
        );
      }),
    );

    const user = userEvent.setup();
    renderTab("probation");

    await user.click(
      await screen.findByRole("button", { name: /Transition Status/ }),
    );
    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("radio", { name: "Confirmed" }));
    await user.type(
      within(dialog).getByLabelText("Reason"),
      "Completed probation",
    );
    await user.click(
      within(dialog).getByRole("button", { name: "Apply Transition" }),
    );

    await waitFor(() => expect(bodies).toHaveLength(1));
    expect(bodies[0]).toMatchObject({
      to_status: "confirmed",
      reason: "Completed probation",
    });
    expect(bodies[0].effective_date).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    await waitFor(() => expect(listCalls).toBe(2));
  });
});
