import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import LoginPage from "@/app/(auth)/login/page";
import type { useAuthHostContext } from "@/lib/auth/use-auth-host-context";

/**
 * The login page renders differently per hostname context, and two of those
 * differences are security-relevant rather than cosmetic:
 *
 *   - the platform host (admin.ethr.et) must not offer tenant selection or the
 *     tenant-only sign-in methods (SSO/OTP) or the self-serve trial link;
 *   - a tenant host shows a read-only organization indicator, never an editable
 *     subdomain field the user could change to point at another tenant;
 *   - a host whose tenant can't be resolved shows a distinct not-found state,
 *     not an authentication form or an auth-failure message.
 *
 * The classification itself (which host is which) is covered by
 * host-context.test.ts; this pins the page's response to each classification.
 * The hook is mocked so each context can be asserted without a live host.
 */
type HostCtx = ReturnType<typeof useAuthHostContext>;

const hostContextValue = vi.fn<() => HostCtx>();
vi.mock("@/lib/auth/use-auth-host-context", () => ({
  useAuthHostContext: () => hostContextValue(),
}));

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), back: vi.fn() }),
}));

function renderLogin() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={queryClient}>
      <LoginPage />
    </QueryClientProvider>,
  );
}

const base: HostCtx = {
  context: "unknown",
  tenantSlug: null,
  tenantName: null,
  tenantLogoPath: null,
  tenantBrand: null,
  tenantLanding: null,
  tenantSignIn: null,
  tenantDefaultLocale: null,
  onLanding: false,
  carryOrganisation: null,
  tenantRequest: null,
  tenantContextLoading: false,
  tenantLookupError: null,
  tenantFieldVisible: true,
};

beforeEach(() => {
  hostContextValue.mockReset();
  localStorage.setItem("locale", "en");
});

describe("login page — hostname context", () => {
  it("platform host: platform heading, no tenant field, no tenant-only methods", () => {
    hostContextValue.mockReturnValue({
      ...base,
      context: "platform",
      tenantFieldVisible: false,
    });

    renderLogin();

    expect(
      screen.getByRole("heading", { name: /sign in to ethr platform/i }),
    ).toBeInTheDocument();
    expect(
      screen.queryByLabelText(/organization subdomain/i),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: /sign in with sso/i }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("link", { name: /text message code/i }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("link", { name: /start free trial/i }),
    ).not.toBeInTheDocument();
    // Password reset is per organisation; a super admin has none, so the
    // link could only fail here (production QA 2026-10-11, P14).
    expect(
      screen.queryByRole("link", { name: /forgot password/i }),
    ).not.toBeInTheDocument();
  });

  it("tenant host still offers a password reset", () => {
    hostContextValue.mockReturnValue({
      ...base,
      context: "tenant",
      tenantSlug: "acme",
      tenantName: "Acme",
      tenantFieldVisible: false,
    });

    renderLogin();

    expect(
      screen.getByRole("link", { name: /forgot password/i }),
    ).toBeInTheDocument();
  });

  it("tenant host: names the org in the heading and shows a read-only indicator, not an editable field", () => {
    hostContextValue.mockReturnValue({
      ...base,
      context: "tenant",
      tenantSlug: "acme",
      tenantName: "Acme Corporation",
      tenantFieldVisible: false,
    });

    renderLogin();

    expect(
      screen.getByRole("heading", { name: /sign in to acme corporation/i }),
    ).toBeInTheDocument();
    // The org is shown for context…
    expect(screen.getByText("acme.ethr.et")).toBeInTheDocument();
    // …but there is no editable subdomain field to retarget another tenant.
    expect(
      screen.queryByLabelText(/organization subdomain/i),
    ).not.toBeInTheDocument();
    // Tenant sign-in methods remain available.
    expect(
      screen.getByRole("button", { name: /sign in with sso/i }),
    ).toBeInTheDocument();
  });

  it("unresolvable tenant host: shows the not-found state instead of a form", () => {
    hostContextValue.mockReturnValue({
      ...base,
      context: "tenant",
      tenantSlug: "doesnotexist",
      tenantFieldVisible: false,
      tenantLookupError: "not_found",
    });

    renderLogin();

    expect(
      screen.getByRole("heading", { name: /organization not found/i }),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: /^sign in$/i }),
    ).not.toBeInTheDocument();
  });

  it("development/apex context: generic heading with the editable subdomain field", () => {
    hostContextValue.mockReturnValue(base);

    renderLogin();

    expect(
      screen.getByRole("heading", { name: /^sign in to ethr$/i }),
    ).toBeInTheDocument();
    expect(
      screen.getByLabelText(/organization subdomain/i),
    ).toBeInTheDocument();
  });

  // The shared landing, ethr.et/{slug}: the organisation is in the path.
  it("organisation landing: names it, offers a way out, and no trial", () => {
    hostContextValue.mockReturnValue({
      ...base,
      context: "tenant",
      tenantSlug: "acme",
      tenantName: "Acme Manufacturing",
      tenantFieldVisible: false,
      onLanding: true,
      tenantBrand: {
        logo_url: null,
        primary_color: "#0f766e",
        accent_color: null,
      },
    });

    renderLogin();

    expect(
      screen.getByRole("heading", { name: /sign in to acme manufacturing/i }),
    ).toBeInTheDocument();
    expect(
      screen.queryByLabelText(/organization subdomain/i),
    ).not.toBeInTheDocument();
    expect(screen.getByRole("link", { name: /not yours/i })).toHaveAttribute(
      "href",
      "/login",
    );
    expect(
      screen.queryByRole("link", { name: /start free trial/i }),
    ).not.toBeInTheDocument();
  });

  // An organisation's page offers only the ways in it actually has.
  it("organisation page: hides SSO and text-message codes it does not offer", () => {
    hostContextValue.mockReturnValue({
      ...base,
      context: "tenant",
      tenantSlug: "acme",
      tenantName: "Acme",
      tenantFieldVisible: false,
      tenantSignIn: { sso: false, otp: true },
    });

    renderLogin();

    expect(
      screen.queryByRole("button", { name: /sign in with sso/i }),
    ).not.toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: /text message code/i }),
    ).toBeInTheDocument();
  });

  it("organisation page with neither: no divider, password only", () => {
    hostContextValue.mockReturnValue({
      ...base,
      context: "tenant",
      tenantSlug: "acme",
      tenantName: "Acme",
      tenantFieldVisible: false,
      tenantSignIn: { sso: false, otp: false },
    });

    renderLogin();

    expect(screen.queryByText(/^or$/i)).not.toBeInTheDocument();
    expect(
      screen.queryByRole("link", { name: /text message code/i }),
    ).not.toBeInTheDocument();
  });
});
