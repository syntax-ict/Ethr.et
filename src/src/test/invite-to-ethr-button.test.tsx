import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { toast } from "sonner";
import { server } from "./msw/server";
import { InviteToEthrButton } from "@/features/employees/components/invite-to-ethr-button";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const hasPermission = vi.fn<(p: string) => boolean>();
vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({ hasPermission }),
}));

/**
 * Production QA 2026-10-11, P15: an employee's profile had no way to give them
 * a login linked to their record.
 */
function renderButton(email: string | null = "hana@acme.et") {
  const qc = new QueryClient({
    defaultOptions: { mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={qc}>
      <InviteToEthrButton
        employeeId="01EMPLOYEEHANA00000000000"
        email={email}
      />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  hasPermission.mockReturnValue(true);
});

describe("Invite to ETHR", () => {
  it("invites a login linked to this employee", async () => {
    let body: Record<string, unknown> | undefined;
    server.use(
      http.post("*/api/v1/users", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ public_id: "01USER" }, { status: 201 });
      }),
    );

    renderButton();
    fireEvent.click(screen.getByRole("button", { name: /Invite to ETHR/ }));

    await waitFor(() => expect(body).toBeDefined());
    expect(body).toMatchObject({
      email: "hana@acme.et",
      role: "employee",
      employee_id: "01EMPLOYEEHANA00000000000",
      send_activation: true,
    });
    await waitFor(() => expect(toast.success).toHaveBeenCalled());
  });

  it("says so when the address already signs in", async () => {
    server.use(
      http.post("*/api/v1/users", () =>
        HttpResponse.json(
          {
            type: "https://ethr.et/errors/validation",
            title: "Validation Failed",
            status: 422,
            detail: "The given data was invalid.",
            errors: { email: ["The email has already been taken."] },
          },
          { status: 422 },
        ),
      ),
    );

    renderButton();
    fireEvent.click(screen.getByRole("button", { name: /Invite to ETHR/ }));

    await waitFor(() => expect(toast.info).toHaveBeenCalled());
    expect(toast.error).not.toHaveBeenCalled();
  });

  it("is not offered without an email or without the permission", () => {
    const { unmount } = renderButton(null);
    expect(screen.queryByRole("button", { name: /Invite to ETHR/ })).toBeNull();
    unmount();

    hasPermission.mockReturnValue(false);
    renderButton();
    expect(screen.queryByRole("button", { name: /Invite to ETHR/ })).toBeNull();
  });
});
