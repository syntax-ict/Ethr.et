import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { LoginForm } from "@/app/(auth)/login/login-form";

const push = vi.fn();
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push, replace: vi.fn(), back: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
}));

/**
 * Signing in starts a new session, so it has to start a new cache.
 *
 * `app/providers.tsx` creates one QueryClient in `useState` and never replaces
 * it, so everything any query has fetched stays in memory for as long as the
 * page lives. Most identity changes destroy that page — logout, impersonation
 * in and out, and the 401-refresh-failed path all hard-navigate — but reaching
 * the login form does not: `AuthGuard` sends a failed session here with
 * `router.replace`, which keeps the JS context alive, and this form then left
 * for /dashboard with `router.push`.
 *
 * Between those two SPA transitions the cache survives, so the next person to
 * sign in on that tab inherits whatever the previous one had loaded — across
 * tenants, since the cache keys carry no tenant.
 *
 * This test asserts the boundary itself rather than the scenario: whatever the
 * cache held before, it is empty once authentication succeeds.
 */
const originalLocation = window.location;

beforeEach(() => {
  vi.clearAllMocks();
  localStorage.clear();
  localStorage.setItem("locale", "en");
  Object.defineProperty(window, "location", {
    configurable: true,
    writable: true,
    value: {
      href: "http://localhost:3000/login",
      origin: "http://localhost:3000",
      protocol: "http:",
      host: "localhost:3000",
      hostname: "localhost",
      port: "3000",
      pathname: "/login",
      search: "",
      hash: "",
      assign: vi.fn(),
    },
  });

  server.use(
    // `baseURL: ""` — this one is requested off the API prefix.
    http.get(
      "*/sanctum/csrf-cookie",
      () => new HttpResponse(null, { status: 204 }),
    ),
    http.post("*/auth/login", () => HttpResponse.json({ mfa_required: false })),
    http.get("*/auth/host-context", () =>
      HttpResponse.json({ context: "single_host", tenant: null }),
    ),
  );
});

afterEach(() => {
  Object.defineProperty(window, "location", {
    configurable: true,
    writable: true,
    value: originalLocation,
  });
});

describe("the login cache boundary", () => {
  it("drops everything the previous session cached before going to the dashboard", async () => {
    const client = new QueryClient({
      defaultOptions: {
        queries: { retry: false },
        mutations: { retry: false },
      },
    });

    // Stand in for anything the previous occupant of this tab had loaded.
    client.setQueryData(["auth", "me"], {
      user: { public_id: "01HZPREVIOUS", email: "previous@other-tenant.et" },
      permissions: ["payroll.process"],
      tenant: { public_id: "01HZOTHERTENANT", subdomain: "other" },
    });
    client.setQueryData(["employees", { page: 1 }], {
      data: [{ public_id: "01HZEMP", name: "Someone Else" }],
    });

    const utils = render(
      <QueryClientProvider client={client}>
        <LoginForm />
      </QueryClientProvider>,
    );

    // By id: "Password" also matches the "Forgot password?" link.
    const { container } = utils;
    const email = container.querySelector("#email") as HTMLInputElement;
    const password = container.querySelector("#password") as HTMLInputElement;

    await waitFor(() => expect(email).toBeInTheDocument());

    fireEvent.change(email, { target: { value: "new.user@acme.et" } });
    fireEvent.change(password, { target: { value: "correct-horse" } });
    fireEvent.submit(container.querySelector("form") as HTMLFormElement);

    await waitFor(() => expect(push).toHaveBeenCalledWith("/dashboard"));

    // Not "refetched later" — gone. A stale-but-present entry renders instantly
    // on the next screen, which is the whole problem.
    expect(client.getQueryData(["auth", "me"])).toBeUndefined();
    expect(client.getQueryData(["employees", { page: 1 }])).toBeUndefined();
  });
});
