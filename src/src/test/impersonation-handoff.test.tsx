import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, renderHook, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { useImpersonateTenant } from "@/features/admin/api";
import { ClaimSession } from "@/app/(auth)/impersonate/claim/claim-session";

/**
 * Impersonation across the host boundary, browser half (audit N19).
 *
 * In production the console is on admin.ethr.et and the tenant on
 * {tenant}.ethr.et. The session cookie is host-only and local storage is per
 * origin, so the admin host can neither sign the browser in on the tenant host
 * nor tell that host's banner to show. The server hands back a `handoff_url`
 * carrying a single-use nonce; the browser must *follow* it, and the claim page
 * on the tenant host must exchange the nonce and set the banner flag there.
 * Before N19 nothing read `handoff_url`, and the claim page set no flag.
 */
const originalLocation = window.location;

function stubLocation(overrides: Partial<Location> = {}) {
  // jsdom refuses a real navigation, so `location` is a plain object for the
  // duration. It carries a real origin because `apiClient`'s relative baseURL
  // has to resolve against something.
  Object.defineProperty(window, "location", {
    configurable: true,
    writable: true,
    value: {
      href: "http://localhost:3000/",
      origin: "http://localhost:3000",
      protocol: "http:",
      host: "localhost:3000",
      hostname: "localhost",
      port: "3000",
      pathname: "/",
      search: "",
      hash: "",
      assign: vi.fn(),
      replace: vi.fn(),
      ...overrides,
    },
  });
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

function wrapper({ children }: { children: React.ReactNode }) {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

describe("starting an impersonation from the platform console", () => {
  it("follows the handoff URL to the tenant host, leaving this origin's storage alone", async () => {
    stubLocation({
      href: "https://admin.ethr.et/admin/tenants/01HZTENANT001",
      origin: "https://admin.ethr.et",
    });
    const handoff =
      "https://habru.ethr.et/impersonate/claim#nonce=0123456789abcdef";

    server.use(
      http.post("*/admin/tenants/:publicId/impersonate", () =>
        HttpResponse.json({
          tenant: "habru",
          expires_at: "2026-10-02T12:30:00Z",
          handoff_url: handoff,
        }),
      ),
    );

    const { result } = renderHook(() => useImpersonateTenant(), { wrapper });
    result.current.mutate({ publicId: "01HZTENANT001", code: "123456" });

    await waitFor(() =>
      expect(window.location.assign).toHaveBeenCalledWith(handoff),
    );

    // The banner flag belongs on the tenant host, which the claim page sets.
    // Written here it would put the warning on the platform console instead.
    expect(localStorage.getItem("impersonating")).toBeNull();
    expect(localStorage.getItem("tenant")).toBeNull();
  });

  it("keeps the single-host behaviour when no handoff is needed", async () => {
    stubLocation();
    localStorage.setItem("tenant", "platform");

    server.use(
      http.post("*/admin/tenants/:publicId/impersonate", () =>
        HttpResponse.json({
          token: "1|plain",
          tenant: "habru",
          expires_at: "2026-10-02T12:30:00Z",
        }),
      ),
    );

    const { result } = renderHook(() => useImpersonateTenant(), { wrapper });
    result.current.mutate({ publicId: "01HZTENANT001", code: "123456" });

    await waitFor(() => expect(window.location.href).toBe("/dashboard"));
    expect(window.location.assign).not.toHaveBeenCalled();
    expect(localStorage.getItem("impersonating")).toBe("true");
    expect(localStorage.getItem("tenant")).toBe("habru");
    expect(localStorage.getItem("original_tenant")).toBe("platform");
  });
});

describe("<ClaimSession> on the tenant host", () => {
  it("primes CSRF, claims the nonce, sets the banner flag here and lands on the dashboard", async () => {
    stubLocation({
      href: "https://habru.ethr.et/impersonate/claim#nonce=feedface",
      origin: "https://habru.ethr.et",
      host: "habru.ethr.et",
      hostname: "habru.ethr.et",
      protocol: "https:",
      port: "",
      pathname: "/impersonate/claim",
      hash: "#nonce=feedface",
    });

    const calls: string[] = [];
    let claimedNonce: unknown = null;

    server.use(
      http.get("*/sanctum/csrf-cookie", () => {
        calls.push("csrf");
        return new HttpResponse(null, { status: 204 });
      }),
      http.post("*/auth/session/claim", async ({ request }) => {
        calls.push("claim");
        claimedNonce = ((await request.json()) as { nonce?: unknown }).nonce;
        return HttpResponse.json({ status: "ok" });
      }),
    );

    render(<ClaimSession />);

    await waitFor(() =>
      expect(window.location.replace).toHaveBeenCalledWith("/dashboard"),
    );

    // The browser has never been to this host, so it holds no XSRF-TOKEN
    // cookie: without the priming GET first, Sanctum answers the claim 419.
    expect(calls).toEqual(["csrf", "claim"]);
    expect(claimedNonce).toBe("feedface");
    expect(localStorage.getItem("impersonating")).toBe("true");
  });

  it("sets no flag and says so when the nonce is refused", async () => {
    stubLocation({
      href: "https://habru.ethr.et/impersonate/claim#nonce=spent",
      origin: "https://habru.ethr.et",
      pathname: "/impersonate/claim",
      hash: "#nonce=spent",
    });

    server.use(
      http.get(
        "*/sanctum/csrf-cookie",
        () => new HttpResponse(null, { status: 204 }),
      ),
      http.post("*/auth/session/claim", () =>
        HttpResponse.json(
          {
            type: "https://ethr.et/errors/invalid-handoff",
            title: "Invalid Handoff",
            status: 422,
          },
          { status: 422 },
        ),
      ),
    );

    render(<ClaimSession />);

    expect(await screen.findByText("Sign-in link expired")).toBeInTheDocument();
    expect(localStorage.getItem("impersonating")).toBeNull();
    expect(window.location.replace).not.toHaveBeenCalled();
  });
});
