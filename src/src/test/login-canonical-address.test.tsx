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

    await waitFor(() => expect(assign).toHaveBeenCalledTimes(1));
    const target = new URL(assign.mock.calls[0][0] as string);
    expect(target.origin + target.pathname).toBe("https://hr.acme.com/login");
    // The email and language ride in the fragment, which no server sees —
    // never in the query string (production QA 2026-10-11, P6).
    expect(target.search).toBe("");
    const fragment = new URLSearchParams(target.hash.slice(1));
    expect(fragment.get("handoff")).toBe("1");
    expect(fragment.get("email")).toBe("admin@acme.et");
    expect(fragment.get("lang")).toBe("en");
    expect(target.toString()).not.toContain("correct-horse");
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

/**
 * The other side of the hand-off (production QA 2026-10-11, P6). The owner
 * landed on a blank form in the app's default language, with no word of why,
 * and reasonably took it for a completed sign-in.
 */
describe("arriving at the canonical address from the apex", () => {
  function arrive(hash: string) {
    window.location.hash = hash;
    const replaceState = vi.spyOn(window.history, "replaceState");
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    const view = render(
      <QueryClientProvider client={client}>
        <LoginForm />
      </QueryClientProvider>,
    );
    return { ...view, replaceState };
  }

  it("keeps the email, says why, and clears the fragment", async () => {
    const { container, findByRole, replaceState } = arrive(
      "#handoff=1&email=admin%40acme.et&lang=en",
    );

    const status = await findByRole("status");
    expect(status.textContent).toMatch(/signs in at this address/);
    expect((container.querySelector("#email") as HTMLInputElement).value).toBe(
      "admin@acme.et",
    );
    expect(replaceState).toHaveBeenCalled();
    expect(replaceState.mock.calls.at(-1)?.[2]).toBe("/login");
  });

  it("carries the language across", async () => {
    localStorage.setItem("locale", "am");
    const { findByRole } = arrive("#handoff=1&email=admin%40acme.et&lang=en");

    await findByRole("status");
    expect(localStorage.getItem("locale")).toBe("en");
  });

  it("ignores a fragment that is not a hand-off", () => {
    const { queryByRole, container } = arrive("#section-2");

    expect(queryByRole("status")).toBeNull();
    expect((container.querySelector("#email") as HTMLInputElement).value).toBe(
      "",
    );
  });

  it("does not put something that is not an email into the field", async () => {
    const { container, findByRole } = arrive(
      "#handoff=1&email=%3Cscript%3E&lang=en",
    );

    await findByRole("status");
    expect((container.querySelector("#email") as HTMLInputElement).value).toBe(
      "",
    );
  });
});
