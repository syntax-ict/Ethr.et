import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { toast } from "sonner";
import { server } from "./msw/server";
import { EmergencyContactsTab } from "@/features/employees/components/emergency-contacts-tab";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

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

  it("reports a failed load instead of claiming there are no contacts", async () => {
    // Regression: an error fell through to "No emergency contacts".
    server.use(
      http.get(CONTACTS_URL, () => new HttpResponse(null, { status: 500 })),
    );
    renderTab();

    expect(await screen.findByText("Couldn't load this")).toBeInTheDocument();
    expect(screen.queryByText("No emergency contacts")).not.toBeInTheDocument();
  });

  it("names the delete control after the contact it removes", async () => {
    // Regression: an icon-only button with no accessible name.
    server.use(
      http.get(CONTACTS_URL, () => HttpResponse.json([buildContact()])),
    );
    renderTab();

    expect(
      await screen.findByRole("button", { name: "Delete Abebe Kebede" }),
    ).toBeInTheDocument();
  });

  it("says so when a delete fails", async () => {
    // Regression: no onError, so a failed delete changed nothing on screen
    // and said nothing.
    server.use(
      http.get(CONTACTS_URL, () => HttpResponse.json([buildContact()])),
      http.delete(
        `${CONTACTS_URL}/:id`,
        () => new HttpResponse(null, { status: 500 }),
      ),
    );
    vi.mocked(toast.error).mockClear();

    const user = userEvent.setup();
    renderTab();
    await user.click(
      await screen.findByRole("button", { name: "Delete Abebe Kebede" }),
    );

    await waitFor(() =>
      expect(toast.error).toHaveBeenCalledWith("Could not delete the contact"),
    );
  });
});
