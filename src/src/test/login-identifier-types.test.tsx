import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { LoginForm } from "@/app/(auth)/login/login-form";

vi.mock("next/navigation", () => ({
  usePathname: () => "/login",
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), back: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
}));

/**
 * K-44: the sign-in form accepted only an email address.
 *
 * In-person activation (#440) ends with "Sign in with your employee code", and
 * the server resolves the value by every identifier type the organisation
 * enables (AuthIdentifierResolver). The form refused anything that was not an
 * email before it asked, so an employee with no email had no way in.
 */
let sent: Record<string, unknown> | null;

function organisation(identifiers?: string[]) {
  server.use(
    http.get("*/api/v1/auth/tenant-context", ({ request }) =>
      HttpResponse.json({
        tenant:
          request.headers.get("X-Tenant") === "acme"
            ? {
                name: "Acme",
                subdomain: "acme",
                logo_path: null,
                brand: null,
                landing: null,
                sign_in: {
                  sso: false,
                  otp: false,
                  ...(identifiers ? { identifiers } : {}),
                },
                default_locale: "en",
              }
            : null,
      }),
    ),
  );
}

function renderAt(url: string) {
  window.history.replaceState({}, "", url);
  return render(
    <QueryClientProvider
      client={
        new QueryClient({ defaultOptions: { queries: { retry: false } } })
      }
    >
      <LoginForm />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  sent = null;
  server.use(
    http.post("*/api/v1/auth/login", async ({ request }) => {
      sent = (await request.json()) as Record<string, unknown>;
      return HttpResponse.json({ mfa_required: false });
    }),
  );
});

afterEach(() => {
  localStorage.clear();
  window.history.replaceState({}, "", "/");
});

describe("an organisation that signs in by employee code", () => {
  it("names the field after what it accepts", async () => {
    organisation(["employee_code", "email"]);
    renderAt("/login?org=acme");

    expect(
      await screen.findByLabelText("Employee code or email"),
    ).toHaveAttribute("type", "text");
  });

  it("signs in an employee who has only an employee code", async () => {
    organisation(["employee_code"]);
    const user = userEvent.setup();
    renderAt("/login?org=acme");

    await user.type(await screen.findByLabelText("Employee code"), "EMP-0042");
    await user.type(screen.getByLabelText(/^password$/i), "a-fresh-secret-9");
    await user.click(screen.getByRole("button", { name: /^sign in$/i }));

    await waitFor(() => expect(sent).not.toBeNull());
    expect(sent).toMatchObject({
      identifier: "EMP-0042",
      password: "a-fresh-secret-9",
      tenant: "acme",
    });
    expect(screen.queryByText("Enter a valid email address.")).toBeNull();
  });

  it("still asks for a value before it sends anything", async () => {
    organisation(["employee_code"]);
    const user = userEvent.setup();
    renderAt("/login?org=acme");

    await screen.findByLabelText("Employee code");
    await user.type(screen.getByLabelText(/^password$/i), "a-fresh-secret-9");
    await user.click(screen.getByRole("button", { name: /^sign in$/i }));

    expect(
      await screen.findByText("Enter your employee code."),
    ).toBeInTheDocument();
    expect(sent).toBeNull();
  });
});

describe("an organisation that signs in by email only", () => {
  it.each([
    ["says so", ["email"]],
    ["predates the list", undefined],
  ])("keeps the email field and its check when it %s", async (_, list) => {
    organisation(list);
    const user = userEvent.setup();
    renderAt("/login?org=acme");

    const field = await screen.findByLabelText("Email");
    expect(field).toHaveAttribute("type", "email");

    await user.type(field, "EMP-0042");
    await user.type(screen.getByLabelText(/^password$/i), "secret");
    await user.click(screen.getByRole("button", { name: /^sign in$/i }));

    expect(
      await screen.findByText("Enter a valid email address."),
    ).toBeInTheDocument();
    expect(sent).toBeNull();
  });
});

describe("the shared sign-in page, before the organisation is known", () => {
  it("accepts any identifier and leaves the decision to the server", async () => {
    server.use(
      http.get("*/api/v1/auth/tenant-context", () =>
        HttpResponse.json({ tenant: null }),
      ),
    );
    const user = userEvent.setup();
    renderAt("/login");

    await user.type(
      await screen.findByLabelText(/organi[sz]ation subdomain/i),
      "acme",
    );
    await user.type(
      screen.getByLabelText("Email, phone number, employee code or username"),
      "EMP-0042",
    );
    await user.type(screen.getByLabelText(/^password$/i), "a-fresh-secret-9");
    await user.click(screen.getByRole("button", { name: /^sign in$/i }));

    await waitFor(() => expect(sent).not.toBeNull());
    expect(sent).toMatchObject({ identifier: "EMP-0042", tenant: "acme" });
  });
});
