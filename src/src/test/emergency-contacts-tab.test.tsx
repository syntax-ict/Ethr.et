import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { EmergencyContactsTab } from "@/features/employees/components/emergency-contacts-tab";

const EMPLOYEE_ID = "01HZEMPLOYEE0000000000001";
const CONTACTS_URL = `*/api/v1/employees/${EMPLOYEE_ID}/emergency-contacts`;

/** The shape `EmergencyContactResource` renders — nothing more. */
function buildContact(overrides: Record<string, unknown> = {}) {
  return {
    public_id: "01HZCONTACT00000000000001",
    name: "Abebe Kebede",
    relationship: "Spouse",
    phone: "+251911223344",
    email: null,
    priority: 1,
    ...overrides,
  };
}

function renderTab() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  return render(<EmergencyContactsTab employeeId={EMPLOYEE_ID} />, {
    wrapper: Wrapper,
  });
}

describe("<EmergencyContactsTab>", () => {
  it("lists the contacts the endpoint returns as a bare array", async () => {
    // Regression: the tab read `data.data` from an unwrapped collection, so an
    // employee with emergency contacts on file showed none — the one screen
    // someone opens when they need to call one.
    server.use(
      http.get(CONTACTS_URL, () => HttpResponse.json([buildContact()])),
    );
    renderTab();

    expect(await screen.findByText("Abebe Kebede")).toBeInTheDocument();
    expect(screen.queryByText("No emergency contacts")).not.toBeInTheDocument();
  });
});
