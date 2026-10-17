import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { LoginForm } from "@/app/(auth)/login/login-form";
import { initiateSso, requestOtp, verifyOtp } from "@/features/auth/sign-in";

vi.mock("next/navigation", () => ({
  usePathname: () => "/login",
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), back: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
}));

/**
 * The organisation typed on the shared sign-in page is the one signed in to.
 *
 * Reproduced 2026-10-17: a browser that had signed in to one organisation kept
 * its slug in localStorage, and the client sent it as `X-Tenant` on every
 * request. Typing another organisation changed only the body, the server read
 * the header first, and the correct password was refused. The stored slug
 * only changes after a successful sign-in, so the person was stuck.
 */
interface Seen {
  header: string | null;
  body: Record<string, unknown>;
}

let seen: Record<string, Seen>;

function recordAt(method: "get" | "post", path: string, reply: object) {
  return http[method](`*/api/v1${path}`, async ({ request }) => {
    seen[path] = {
      header: request.headers.get("X-Tenant"),
      body:
        method === "post"
          ? ((await request.json()) as Record<string, unknown>)
          : {},
    };
    return HttpResponse.json(reply);
  });
}

beforeEach(() => {
  seen = {};
  localStorage.setItem("tenant", "acme");
  server.use(
    http.get("*/api/v1/auth/tenant-context", () =>
      HttpResponse.json({ tenant: null }),
    ),
    recordAt("post", "/auth/login", { mfa_required: false }),
    recordAt("post", "/auth/otp/request", { message: "Sent" }),
    recordAt("post", "/auth/otp/verify", { mfa_required: false }),
    recordAt("get", "/sso/saml/habru/initiate", {
      redirect_url: "https://idp.example/sso",
    }),
  );
});

afterEach(() => {
  localStorage.clear();
  vi.unstubAllEnvs();
});

describe("signing in to an organisation other than the stored one", () => {
  it("names the typed organisation in both the header and the body", async () => {
    const user = userEvent.setup();
    render(
      <QueryClientProvider client={new QueryClient()}>
        <LoginForm />
      </QueryClientProvider>,
    );

    const field = await screen.findByLabelText(/organi[sz]ation subdomain/i);
    expect(field).toHaveValue("acme");

    await user.clear(field);
    await user.type(field, "habru");
    await user.type(screen.getByLabelText(/email/i), "abebe@example.com");
    await user.type(screen.getByLabelText(/^password$/i), "habru-password-1");
    await user.click(screen.getByRole("button", { name: /^sign in$/i }));

    await waitFor(() => expect(seen["/auth/login"]).toBeDefined());
    expect(seen["/auth/login"].body.tenant).toBe("habru");
    expect(seen["/auth/login"].header).toBe("habru");
    await waitFor(() => expect(localStorage.getItem("tenant")).toBe("habru"));
  });

  it("does the same for a sign-in code, asked for and verified", async () => {
    await requestOtp({ email: "abebe@example.com", tenant: "habru" });
    await verifyOtp({
      email: "abebe@example.com",
      code: "123456",
      tenant: "habru",
    });

    expect(seen["/auth/otp/request"].header).toBe("habru");
    expect(seen["/auth/otp/verify"].header).toBe("habru");
  });

  it("does the same for single sign-on", async () => {
    await initiateSso("habru");

    expect(seen["/sso/saml/habru/initiate"].header).toBe("habru");
  });

  it("sends no organisation when the field was cleared", async () => {
    await requestOtp({ email: "abebe@example.com", tenant: "" });

    expect(seen["/auth/otp/request"].header).toBeNull();
  });

  it("still sends nothing on an organisation's own host", async () => {
    vi.stubEnv("NEXT_PUBLIC_ROOT_DOMAIN", "ethr.et");
    const originalLocation = window.location;
    Object.defineProperty(window, "location", {
      value: new URL("https://woldia.ethr.et/login"),
      writable: true,
      configurable: true,
    });

    try {
      await requestOtp({ email: "abebe@example.com", tenant: "habru" });
    } finally {
      Object.defineProperty(window, "location", {
        value: originalLocation,
        writable: true,
        configurable: true,
      });
    }

    expect(seen["/auth/otp/request"].header).toBeNull();
  });
});
