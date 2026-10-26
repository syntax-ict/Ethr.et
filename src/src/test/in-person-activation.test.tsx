import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { ActivateForm } from "@/app/(auth)/login/activate/activate-form";
import { IssueActivationCodeButton } from "@/features/employees/components/issue-activation-code-button";

let search = new URLSearchParams();
const push = vi.fn();
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push, replace: vi.fn(), back: vi.fn() }),
  usePathname: () => "/login/activate",
  useSearchParams: () => search,
}));

const hasPermission = vi.fn<(p: string) => boolean>();
vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({ hasPermission }),
}));

function renderWithQuery(ui: React.ReactElement) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>{ui}</QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  search = new URLSearchParams({ tenant: "acme" });
  localStorage.clear();
  localStorage.setItem("locale", "en");
  window.history.replaceState(null, "", "/login/activate?tenant=acme");
  hasPermission.mockReturnValue(true);
  server.use(
    http.get(
      "*/sanctum/csrf-cookie",
      () => new HttpResponse(null, { status: 204 }),
    ),
  );
});

describe("activation page (the employee's side)", () => {
  it("reads the code from the address fragment, then removes it from the address bar", async () => {
    window.history.replaceState(
      null,
      "",
      "/login/activate?tenant=acme#c=ABCD-EFGH-JKMN&e=EMP-0042",
    );
    renderWithQuery(<ActivateForm />);

    await waitFor(() =>
      expect(screen.getByLabelText(/activation code/i)).toHaveValue(
        "ABCD-EFGH-JKMN",
      ),
    );
    expect(screen.getByLabelText(/employee code/i)).toHaveValue("EMP-0042");
    expect(window.location.hash).toBe("");
    expect(window.location.search).toBe("?tenant=acme");
  });

  it("fetches the CSRF cookie, then posts the code with the organisation", async () => {
    const calls: string[] = [];
    let body: Record<string, unknown> | undefined;
    let tenantHeader: string | null = null;
    server.use(
      http.get("*/sanctum/csrf-cookie", () => {
        calls.push("csrf");
        return new HttpResponse(null, { status: 204 });
      }),
      http.post("*/api/v1/auth/activation/redeem", async ({ request }) => {
        calls.push("post");
        tenantHeader = request.headers.get("x-tenant");
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ message: "ok" });
      }),
    );
    renderWithQuery(<ActivateForm />);

    const user = userEvent.setup();
    await user.type(screen.getByLabelText(/employee code/i), "EMP-0042");
    await user.type(screen.getByLabelText(/activation code/i), "ABCDEFGHJKMN");
    await user.type(
      screen.getByLabelText(/^new password/i),
      "a-fresh-secret-9",
    );
    await user.type(
      screen.getByLabelText(/confirm password/i),
      "a-fresh-secret-9",
    );
    await user.click(screen.getByRole("button", { name: /activate account/i }));

    await waitFor(() => expect(calls).toEqual(["csrf", "post"]));
    expect(tenantHeader).toBe("acme");
    expect(body).toMatchObject({
      employee_code: "EMP-0042",
      code: "ABCDEFGHJKMN",
      tenant: "acme",
    });
    expect(await screen.findByText(/your account is ready/i)).toBeVisible();
  });

  it("says the same thing whatever was wrong with the code", async () => {
    server.use(
      http.post("*/api/v1/auth/activation/redeem", () =>
        HttpResponse.json(
          {
            type: "https://ethr.et/errors/invalid-activation-code",
            title: "Invalid Activation Code",
            status: 422,
            detail: "This code is not valid. Ask HR to issue a new one.",
          },
          { status: 422 },
        ),
      ),
    );
    renderWithQuery(<ActivateForm />);

    const user = userEvent.setup();
    await user.type(screen.getByLabelText(/employee code/i), "EMP-0042");
    await user.type(screen.getByLabelText(/activation code/i), "ABCDEFGHJKMN");
    await user.type(
      screen.getByLabelText(/^new password/i),
      "a-fresh-secret-9",
    );
    await user.type(
      screen.getByLabelText(/confirm password/i),
      "a-fresh-secret-9",
    );
    await user.click(screen.getByRole("button", { name: /activate account/i }));

    expect(await screen.findByText(/not valid or has expired/i)).toBeVisible();
  });

  it("refuses a link that does not name the organisation", () => {
    search = new URLSearchParams();
    renderWithQuery(<ActivateForm />);

    expect(screen.getByText(/invalid activation link/i)).toBeVisible();
    expect(screen.queryByLabelText(/activation code/i)).toBeNull();
  });
});

describe("issuing a code (HR's side)", () => {
  const status = {
    login_status: "none",
    state: "none",
    purpose: null,
    expires_at: null,
    document_type: null,
  };

  function renderButton(
    props: { email?: string | null; phone?: string | null } = {},
  ) {
    return renderWithQuery(
      <IssueActivationCodeButton
        employeeId="01EMPLOYEE0000000000000000"
        employeeName="Abebe Kebede"
        email={props.email ?? null}
        phone={props.phone ?? null}
      />,
    );
  }

  it("is hidden for someone who has an email or a phone, or without the permission", () => {
    const { unmount } = renderButton({ email: "a@acme.et" });
    expect(screen.queryByRole("button")).toBeNull();
    unmount();

    const second = renderButton({ phone: "+251911223344" });
    expect(screen.queryByRole("button")).toBeNull();
    second.unmount();

    hasPermission.mockReturnValue(false);
    renderButton();
    expect(screen.queryByRole("button")).toBeNull();
  });

  it("will not issue until the identity check is attested and a document is chosen", async () => {
    server.use(
      http.get("*/api/v1/employees/*/activation-code", () =>
        HttpResponse.json(status),
      ),
    );
    renderButton();

    const user = userEvent.setup();
    await user.click(
      screen.getByRole("button", { name: /activate in person/i }),
    );
    const issue = await screen.findByRole("button", { name: /issue code/i });
    expect(issue).toBeDisabled();

    await user.selectOptions(
      screen.getByLabelText(/document checked/i),
      "kebele_id",
    );
    await user.type(screen.getByLabelText(/your password/i), "hr-password");
    expect(issue).toBeDisabled();

    await user.click(screen.getByLabelText(/checked this person's identity/i));
    expect(issue).toBeEnabled();
  });

  it("shows the code once, and drops it when the dialog closes", async () => {
    let body: Record<string, unknown> | undefined;
    server.use(
      http.get("*/api/v1/employees/*/activation-code", () =>
        HttpResponse.json(status),
      ),
      http.post("*/api/v1/employees/*/activation-code", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(
          {
            code: "ABCD-EFGH-JKMN",
            employee_code: "EMP-0042",
            purpose: "activation",
            expires_at: "2026-10-27T09:00:00Z",
            activation_url:
              "https://ethr.et/login/activate?tenant=acme#c=ABCDEFGHJKMN&e=EMP-0042",
          },
          { status: 201 },
        );
      }),
    );
    renderButton();

    const user = userEvent.setup();
    await user.click(
      screen.getByRole("button", { name: /activate in person/i }),
    );
    await user.selectOptions(
      await screen.findByLabelText(/document checked/i),
      "national_id",
    );
    await user.type(screen.getByLabelText(/your password/i), "hr-password");
    await user.click(screen.getByLabelText(/checked this person's identity/i));
    await user.click(screen.getByRole("button", { name: /issue code/i }));

    expect(await screen.findByTestId("activation-code")).toHaveTextContent(
      "ABCD-EFGH-JKMN",
    );
    expect(body).toMatchObject({
      attested: true,
      document_type: "national_id",
      current_password: "hr-password",
    });

    await user.click(screen.getByRole("button", { name: /^done$/i }));
    await waitFor(() =>
      expect(screen.queryByTestId("activation-code")).toBeNull(),
    );

    // Opened again, it asks afresh: the code is not kept anywhere.
    await user.click(
      screen.getByRole("button", { name: /activate in person/i }),
    );
    expect(
      await screen.findByRole("button", { name: /issue code/i }),
    ).toBeVisible();
    expect(screen.queryByTestId("activation-code")).toBeNull();
  });

  it("shows why a code was refused", async () => {
    server.use(
      http.get("*/api/v1/employees/*/activation-code", () =>
        HttpResponse.json(status),
      ),
      http.post("*/api/v1/employees/*/activation-code", () =>
        HttpResponse.json(
          {
            type: "https://ethr.et/errors/activation-code-refused",
            title: "Activation Code Not Issued",
            status: 422,
            detail:
              "This employee would have no way to sign in. Turn on employee code sign-in in settings first.",
            reason: "identifier",
          },
          { status: 422 },
        ),
      ),
    );
    renderButton();

    const user = userEvent.setup();
    await user.click(
      screen.getByRole("button", { name: /activate in person/i }),
    );
    await user.selectOptions(
      await screen.findByLabelText(/document checked/i),
      "passport",
    );
    await user.type(screen.getByLabelText(/your password/i), "hr-password");
    await user.click(screen.getByLabelText(/checked this person's identity/i));
    await user.click(screen.getByRole("button", { name: /issue code/i }));

    expect(await screen.findByText(/no way to sign in/i)).toBeVisible();
    expect(screen.queryByTestId("activation-code")).toBeNull();
  });
});
