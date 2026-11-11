import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import {
  PASSWORD_CHANGE_PATH,
  PASSWORD_CHANGE_REQUIRED,
  apiClient,
  passwordChangeRedirect,
} from "@/api/client";
import { ChangePasswordForm } from "@/app/(auth)/login/change-password/change-password-form";
import { MigrationLogins } from "@/features/onboarding/v2/components/migration-logins";

/**
 * A starting password for a batch of employees (owner decision 2026-11-10).
 *
 * The API holds someone who signed in with one at the change-password screen
 * (`RequirePasswordChange`). These pin the browser's half: where that refusal
 * sends them, the screen itself, and the panel HR gives the password from.
 */

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

vi.mock("@/lib/auth/use-auth-host-context", () => ({
  useAuthHostContext: () => ({
    context: "generic",
    tenantName: null,
    tenantBrand: null,
  }),
}));

const originalLocation = window.location;

function stubLocation(pathname: string) {
  // jsdom refuses a real navigation, so `location` is a plain object for the
  // duration, with a real origin for `apiClient`'s relative baseURL.
  Object.defineProperty(window, "location", {
    configurable: true,
    writable: true,
    value: {
      href: `http://localhost:3000${pathname}`,
      origin: "http://localhost:3000",
      protocol: "http:",
      host: "localhost:3000",
      hostname: "localhost",
      port: "3000",
      pathname,
      search: "",
      hash: "",
      assign: vi.fn(),
      replace: vi.fn(),
    },
  });
}

function withClient(ui: React.ReactElement) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>{ui}</QueryClientProvider>,
  );
}

function refusal(status: number, type: string) {
  return {
    response: { status, data: { type, title: "", status, detail: "" } },
  } as unknown as Parameters<typeof passwordChangeRedirect>[0];
}

beforeEach(() => {
  localStorage.clear();
  localStorage.setItem("locale", "en");
});

afterEach(() => {
  Object.defineProperty(window, "location", {
    configurable: true,
    writable: true,
    value: originalLocation,
  });
});

describe("passwordChangeRedirect", () => {
  it("sends the refusal to the change-password screen", () => {
    expect(
      passwordChangeRedirect(
        refusal(403, PASSWORD_CHANGE_REQUIRED),
        "/dashboard",
      ),
    ).toBe(PASSWORD_CHANGE_PATH);
  });

  it("does not redirect from the change-password screen itself", () => {
    expect(
      passwordChangeRedirect(
        refusal(403, PASSWORD_CHANGE_REQUIRED),
        PASSWORD_CHANGE_PATH,
      ),
    ).toBeNull();
  });

  it("ignores every other refusal", () => {
    expect(
      passwordChangeRedirect(
        refusal(403, "https://ethr.et/errors/forbidden"),
        "/dashboard",
      ),
    ).toBeNull();
    expect(
      passwordChangeRedirect(
        refusal(401, PASSWORD_CHANGE_REQUIRED),
        "/dashboard",
      ),
    ).toBeNull();
  });
});

describe("a request the API refuses until the password is changed", () => {
  it("takes the browser to the change-password screen", async () => {
    stubLocation("/dashboard");
    server.use(
      http.get("*/api/v1/employees", () =>
        HttpResponse.json(
          {
            type: PASSWORD_CHANGE_REQUIRED,
            title: "Password Change Required",
            status: 403,
            detail: "Choose your own password to continue.",
          },
          { status: 403 },
        ),
      ),
    );

    await expect(apiClient.get("/employees")).rejects.toBeTruthy();

    expect(window.location.assign).toHaveBeenCalledWith(PASSWORD_CHANGE_PATH);
  });
});

describe("<ChangePasswordForm>", () => {
  it("sends the starting password and the new one, then loads the app afresh", async () => {
    stubLocation(PASSWORD_CHANGE_PATH);
    let posted: unknown = null;
    server.use(
      http.post("*/api/v1/auth/password/change", async ({ request }) => {
        posted = await request.json();
        return HttpResponse.json({ message: "Password changed successfully." });
      }),
    );
    withClient(<ChangePasswordForm />);

    await userEvent.type(
      screen.getByLabelText(/Starting password/),
      "Kp7mQ2xRt9Wb",
    );
    await userEvent.type(
      screen.getByLabelText(/New password/),
      "my-own-secret-42",
    );
    await userEvent.type(
      screen.getByLabelText(/Confirm password/),
      "my-own-secret-42",
    );
    await userEvent.click(
      screen.getByRole("button", { name: "Save and continue" }),
    );

    await waitFor(() =>
      expect(posted).toEqual({
        current_password: "Kp7mQ2xRt9Wb",
        password: "my-own-secret-42",
        password_confirmation: "my-own-secret-42",
      }),
    );
    await waitFor(() =>
      expect(window.location.assign).toHaveBeenCalledWith("/dashboard"),
    );
  });

  it("does not accept the starting password as the new one", async () => {
    stubLocation(PASSWORD_CHANGE_PATH);
    const change = vi.fn();
    server.use(
      http.post("*/api/v1/auth/password/change", () => {
        change();
        return HttpResponse.json({});
      }),
    );
    withClient(<ChangePasswordForm />);

    await userEvent.type(
      screen.getByLabelText(/Starting password/),
      "Kp7mQ2xRt9Wb",
    );
    await userEvent.type(screen.getByLabelText(/New password/), "Kp7mQ2xRt9Wb");
    await userEvent.type(
      screen.getByLabelText(/Confirm password/),
      "Kp7mQ2xRt9Wb",
    );
    await userEvent.click(
      screen.getByRole("button", { name: "Save and continue" }),
    );

    expect(
      await screen.findByText(
        "Choose a password different from the starting password",
      ),
    ).toBeInTheDocument();
    expect(change).not.toHaveBeenCalled();
    expect(window.location.assign).not.toHaveBeenCalled();
  });

  it("shows why when the starting password has run out", async () => {
    stubLocation(PASSWORD_CHANGE_PATH);
    server.use(
      http.post("*/api/v1/auth/password/change", () =>
        HttpResponse.json(
          {
            type: "https://ethr.et/errors/starting-password-expired",
            title: "Starting Password Expired",
            status: 403,
            detail:
              "This starting password has expired. Ask HR for a new way to sign in.",
          },
          { status: 403 },
        ),
      ),
    );
    withClient(<ChangePasswordForm />);

    await userEvent.type(
      screen.getByLabelText(/Starting password/),
      "Kp7mQ2xRt9Wb",
    );
    await userEvent.type(
      screen.getByLabelText(/New password/),
      "my-own-secret-42",
    );
    await userEvent.type(
      screen.getByLabelText(/Confirm password/),
      "my-own-secret-42",
    );
    await userEvent.click(
      screen.getByRole("button", { name: "Save and continue" }),
    );

    expect(
      await screen.findByText(/This starting password has expired/),
    ).toBeInTheDocument();
    expect(window.location.assign).not.toHaveBeenCalled();
  });
});

const BATCH = "01JBQ8Z6ZK3M9V2X7Y4W5T6R8S";
const NO_EMAIL = "01JBQ8Z6ZK3M9V2X7Y4W5T6R82";

const LOGINS = {
  without_login: [
    {
      employee_public_id: "01JBQ8Z6ZK3M9V2X7Y4W5T6R81",
      name: "Abebe Kebede",
      email: "abebe@acme.et",
      phone: null,
      channel: "email",
      invited: false,
    },
    {
      employee_public_id: NO_EMAIL,
      name: "Hanna Girma",
      email: null,
      phone: null,
      channel: "in_person",
      invited: false,
    },
  ],
  hire_dates_defaulted: 0,
};

function panelHandlers(permissions: string[]) {
  return [
    http.get("*/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "01JBQ8Z6ZK3M9V2X7Y4W5T6R80", role: "hr_admin" },
        permissions,
        tenant: null,
      }),
    ),
    http.get(`*/api/v1/onboarding/migration/batches/${BATCH}/logins`, () =>
      HttpResponse.json(LOGINS),
    ),
  ];
}

describe("the starting password in <MigrationLogins>", () => {
  it("gives the people with no email one password, shown once with the 72-hour warning", async () => {
    let posted: unknown = null;
    server.use(
      ...panelHandlers(["users.invite", "users.issueStartingPassword"]),
      http.post("*/api/v1/employees/starting-password", async ({ request }) => {
        posted = await request.json();
        return HttpResponse.json(
          {
            password: "Kp7mQ2xRt9Wb",
            expires_at: "2026-11-13T09:00:00Z",
            issued: 1,
            people: [
              {
                employee_public_id: NO_EMAIL,
                name: "Hanna Girma",
                employee_code: "EMP-0042",
              },
            ],
            skipped: { active: 0, role: 0, closed: 0, reachable: 0 },
          },
          { status: 201 },
        );
      }),
    );
    const print = vi.spyOn(window, "print").mockImplementation(() => {});
    withClient(<MigrationLogins batchId={BATCH} />);

    await userEvent.click(
      await screen.findByRole("button", { name: "Give a starting password" }),
    );

    const dialog = await screen.findByRole("dialog");
    expect(
      within(dialog).getByText("Give 1 people a starting password"),
    ).toBeInTheDocument();
    expect(
      within(dialog).getByText(/could sign in as them/),
    ).toBeInTheDocument();
    // Nothing is sent before HR has typed their own password.
    expect(
      within(dialog).getByRole("button", { name: "Make the password" }),
    ).toBeDisabled();

    await userEvent.type(
      within(dialog).getByLabelText("Your password"),
      "hr-password",
    );
    await userEvent.click(
      within(dialog).getByRole("button", { name: "Make the password" }),
    );

    await waitFor(() =>
      expect(posted).toEqual({
        employee_ids: [NO_EMAIL],
        current_password: "hr-password",
      }),
    );

    expect(await screen.findByTestId("starting-password")).toHaveTextContent(
      "Kp7mQ2xRt9Wb",
    );
    expect(
      screen.getByText(/stops working after 72 hours/),
    ).toBeInTheDocument();
    expect(screen.getByText("EMP-0042")).toBeInTheDocument();
    expect(screen.getByText("Given to 1 people")).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: "Print" }));
    expect(print).toHaveBeenCalledOnce();

    // Closing drops it: it cannot be read back.
    await userEvent.click(screen.getByRole("button", { name: "Done" }));
    await waitFor(() =>
      expect(screen.queryByText("Kp7mQ2xRt9Wb")).not.toBeInTheDocument(),
    );

    print.mockRestore();
  });

  it("says why when the organisation does not sign in by employee code", async () => {
    server.use(
      ...panelHandlers(["users.issueStartingPassword"]),
      http.post("*/api/v1/employees/starting-password", () =>
        HttpResponse.json(
          {
            type: "https://ethr.et/errors/starting-password-refused",
            title: "Starting Password Not Issued",
            status: 422,
            detail:
              "This organisation does not sign in with employee numbers. Turn that on in Settings first.",
            reason: "identifier",
            skipped: {},
          },
          { status: 422 },
        ),
      ),
    );
    withClient(<MigrationLogins batchId={BATCH} />);

    await userEvent.click(
      await screen.findByRole("button", { name: "Give a starting password" }),
    );
    const dialog = await screen.findByRole("dialog");
    await userEvent.type(
      within(dialog).getByLabelText("Your password"),
      "hr-password",
    );
    await userEvent.click(
      within(dialog).getByRole("button", { name: "Make the password" }),
    );

    expect(await within(dialog).findByRole("alert")).toHaveTextContent(
      "does not sign in with employee numbers",
    );
    expect(screen.queryByTestId("starting-password")).not.toBeInTheDocument();
  });

  it("is not offered without the permission", async () => {
    server.use(...panelHandlers(["users.invite"]));
    withClient(<MigrationLogins batchId={BATCH} />);

    expect(await screen.findByText("Hanna Girma")).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Give a starting password" }),
    ).not.toBeInTheDocument();
  });

  it("is not offered when everyone listed can be invited", async () => {
    server.use(
      http.get("*/auth/me", () =>
        HttpResponse.json({
          user: { public_id: "01JBQ8Z6ZK3M9V2X7Y4W5T6R80", role: "hr_admin" },
          permissions: ["users.invite", "users.issueStartingPassword"],
          tenant: null,
        }),
      ),
      http.get(`*/api/v1/onboarding/migration/batches/${BATCH}/logins`, () =>
        HttpResponse.json({
          without_login: [LOGINS.without_login[0]],
          hire_dates_defaulted: 0,
        }),
      ),
    );
    withClient(<MigrationLogins batchId={BATCH} />);

    expect(await screen.findByText("Abebe Kebede")).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Give a starting password" }),
    ).not.toBeInTheDocument();
  });
});
