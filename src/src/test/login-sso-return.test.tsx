import { afterEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { SsoReturn } from "@/app/(auth)/login/sso/sso-return";

const replace = vi.fn();
vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace, push: vi.fn(), back: vi.fn() }),
  usePathname: () => "/login/sso",
}));

function landAt(query: string) {
  window.history.replaceState({}, "", `/login/sso?${query}`);
  return render(<SsoReturn />);
}

function serveMe(status: number) {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      status === 200
        ? HttpResponse.json({ user: { public_id: "U1" }, permissions: [] })
        : HttpResponse.json({ type: "x", status }, { status }),
    ),
  );
}

// The SAML callback answered the identity provider's form POST with JSON,
// leaving an SSO user on a raw JSON page. It now redirects here (audit N60).
describe("returning from single sign-on", () => {
  afterEach(() => {
    replace.mockReset();
    localStorage.clear();
    sessionStorage.clear();
    window.history.replaceState({}, "", "/");
  });

  it("explains a refusal and offers the way back", async () => {
    landAt("org=acme&error=no_account");

    expect(await screen.findByRole("alert")).toHaveTextContent(
      /no ETHR account/i,
    );
    expect(
      screen.getByRole("link", { name: /back to sign in/i }),
    ).toHaveAttribute("href", "/login");
    expect(localStorage.getItem("tenant")).toBeNull();
  });

  it("remembers the organisation and continues where sign-in was headed", async () => {
    serveMe(200);
    landAt("org=acme&next=%2Fpayroll%2Fpayslips");

    await waitFor(() =>
      expect(replace).toHaveBeenCalledWith("/payroll/payslips"),
    );
    expect(localStorage.getItem("tenant")).toBe("acme");
  });

  it("hands a user who owes a second factor to the MFA step", async () => {
    landAt("org=acme&mfa=1&next=%2Fdashboard");

    await waitFor(() => expect(replace).toHaveBeenCalledWith("/login/mfa"));
    expect(sessionStorage.getItem("mfa_pending")).toBe("true");
  });

  it("forgets an organisation the session does not belong to", async () => {
    // A crafted link naming another organisation: the API refuses the
    // mismatch, and this browser must not keep sending it in X-Tenant.
    serveMe(403);
    landAt("org=someone-else&next=%2Fdashboard");

    expect(await screen.findByRole("alert")).toBeInTheDocument();
    expect(localStorage.getItem("tenant")).toBeNull();
    expect(replace).not.toHaveBeenCalled();
  });

  it("goes only to a path on this site", async () => {
    serveMe(200);
    landAt("org=acme&next=%2F%2Fevil.example");

    await waitFor(() => expect(replace).toHaveBeenCalledWith("/dashboard"));
  });
});
