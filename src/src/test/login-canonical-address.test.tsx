import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, fireEvent, waitFor } from "@testing-library/react";
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
 * One canonical address per organisation (owner decision 2026-10-08).
 *
 * An organisation with a verified custom domain, or a subdomain while those are
 * served, signs in there. A sign-in for it on the apex is answered 409
 * `canonical-address` with the URL, before the password is checked, and the
 * form has to go there rather than show the 409 as an error.
 */
const originalLocation = window.location;
const assign = vi.fn();

function loginAnswers(status: number, body: Record<string, unknown>) {
  server.use(
    http.post("*/auth/login", () => HttpResponse.json(body, { status })),
  );
}

function submit() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const { container } = render(
    <QueryClientProvider client={client}>
      <LoginForm />
    </QueryClientProvider>,
  );

  const email = container.querySelector("#email") as HTMLInputElement;
  const password = container.querySelector("#password") as HTMLInputElement;
  fireEvent.change(email, { target: { value: "admin@acme.et" } });
  fireEvent.change(password, { target: { value: "correct-horse" } });
  fireEvent.submit(container.querySelector("form") as HTMLFormElement);

  return container;
}

beforeEach(() => {
  vi.clearAllMocks();
  localStorage.clear();
  localStorage.setItem("locale", "en");
  localStorage.setItem("tenant", "acme");
  Object.defineProperty(window, "location", {
    configurable: true,
    writable: true,
    value: {
      href: "https://ethr.et/login",
      origin: "https://ethr.et",
      protocol: "https:",
      host: "ethr.et",
      hostname: "ethr.et",
      port: "",
      pathname: "/login",
      search: "",
      hash: "",
      assign,
    },
  });

  server.use(
    http.get(
      "*/sanctum/csrf-cookie",
      () => new HttpResponse(null, { status: 204 }),
    ),
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

describe("signing in to an organisation that has its own address", () => {
  it("goes to the canonical address the server names", async () => {
    loginAnswers(409, {
      type: "https://ethr.et/errors/canonical-address",
      status: 409,
      detail: "Your organisation signs in at its own address.",
      canonical_url: "https://hr.acme.com/login",
    });

    submit();

    await waitFor(() =>
      expect(assign).toHaveBeenCalledWith("https://hr.acme.com/login"),
    );
    expect(push).not.toHaveBeenCalled();
  });

  it("never follows a canonical_url that is not http(s)", async () => {
    loginAnswers(409, {
      type: "https://ethr.et/errors/canonical-address",
      status: 409,
      detail: "Your organisation signs in at its own address.",
      canonical_url: "javascript:alert(1)",
    });

    const container = submit();

    await waitFor(() =>
      expect(container.textContent).toContain(
        "Your organisation signs in at its own address.",
      ),
    );
    expect(assign).not.toHaveBeenCalled();
  });
});
