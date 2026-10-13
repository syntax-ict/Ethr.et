import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { LoginForm } from "@/app/(auth)/login/login-form";

vi.mock("next/navigation", () => ({
  usePathname: () => "/login",
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), back: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
}));

function renderAt(url: string) {
  window.history.replaceState({}, "", url);
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <LoginForm />
    </QueryClientProvider>,
  );
}

/**
 * `/login?org={slug}` — what the sign-in flow's own links carry, and what the
 * entry URL redirected with before #221 served the page at `ethr.et/{slug}`.
 *
 * It used to only prefill an editable field. One click past the organisation's
 * address — "Forgot password?", a text code — therefore lost the organisation
 * and its branding (organisation-landing follow-up G5). Now `?org=` makes the
 * page that organisation's, exactly as `ethr.et/{slug}` is: named, no field to
 * retype.
 */
describe("the login page handed an organisation by ?org=", () => {
  beforeEach(() => {
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
                  sign_in: { sso: false, otp: false },
                  default_locale: "en",
                }
              : null,
        }),
      ),
    );
  });

  afterEach(() => {
    localStorage.clear();
    window.history.replaceState({}, "", "/");
  });

  it("is that organisation's sign-in page", async () => {
    renderAt("/login?org=acme");

    expect(
      await screen.findByRole("heading", { name: /Acme/ }),
    ).toBeInTheDocument();
    expect(screen.queryByLabelText(/organi[sz]ation subdomain/i)).toBeNull();
  });

  it("takes ?org= over the organisation this browser last used", async () => {
    localStorage.setItem("tenant", "habru");
    renderAt("/login?org=acme");

    expect(
      await screen.findByRole("heading", { name: /Acme/ }),
    ).toBeInTheDocument();
  });

  it("stores nothing until the person signs in", async () => {
    localStorage.setItem("tenant", "habru");
    renderAt("/login?org=acme");

    await screen.findByRole("heading", { name: /Acme/ });
    expect(localStorage.getItem("tenant")).toBe("habru");
  });

  it("ignores a value that is not an organisation slug", async () => {
    localStorage.setItem("tenant", "habru");
    renderAt("/login?org=%3Cscript%3E");

    expect(await screen.findByLabelText(/organi[sz]ation/i)).toHaveValue(
      "habru",
    );
  });

  it("keeps the organisation on the forgot-password and text-code links", async () => {
    renderAt("/login?org=acme");

    await screen.findByRole("heading", { name: /Acme/ });
    const forgot = screen.getByRole("link", { name: /forgot/i });
    expect(forgot.getAttribute("href")).toBe("/login/forgot?org=acme");
  });
});
