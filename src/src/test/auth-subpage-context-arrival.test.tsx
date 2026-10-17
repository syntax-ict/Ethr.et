import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { AuthLayoutClient } from "@/app/(auth)/auth-layout-client";
import { ResetPasswordForm } from "@/app/(auth)/login/reset/reset-form";
import { ForgotForm } from "@/app/(auth)/login/forgot/forgot-form";
import type { useAuthHostContext } from "@/lib/auth/use-auth-host-context";

/**
 * A reset link (`/login/reset?...&tenant=acme`) or a forgot-password page
 * reached from the shared sign-in (`/login/forgot?org=acme`) names the
 * organisation in its query. The page draws before the organisation's context
 * arrives, and a person can type in that window. When the context arrived the
 * layout used to swap its two-panel shell for the organisation portal, which
 * mounted the form again and emptied what had been typed: Playwright run
 * 38951069888 on PR #286 found "New password" empty and "Confirm password"
 * kept, because only the first was filled before the swap.
 */
type HostCtx = ReturnType<typeof useAuthHostContext>;

const hostContextValue = vi.fn<() => HostCtx>();
vi.mock("@/lib/auth/use-auth-host-context", () => ({
  useAuthHostContext: () => hostContextValue(),
}));

const location = { pathname: "/login/reset", search: "" };
vi.mock("next/navigation", () => ({
  usePathname: () => location.pathname,
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), back: vi.fn() }),
  useSearchParams: () => new URLSearchParams(location.search),
}));

// The production apex: the build cannot classify the host, the address names
// the organisation, and its context is still on the way.
const arriving: HostCtx = {
  context: "unknown",
  tenantSlug: "acme",
  tenantName: null,
  tenantLogoPath: null,
  tenantBrand: null,
  tenantLanding: null,
  tenantSignIn: null,
  tenantDefaultLocale: null,
  onLanding: true,
  carryOrganisation: null,
  tenantRequest: null,
  tenantContextLoading: true,
  tenantLookupError: null,
  tenantFieldVisible: false,
};

const arrived: HostCtx = {
  ...arriving,
  context: "tenant",
  tenantName: "Acme Manufacturing",
  tenantBrand: {
    logo_url: null,
    primary_color: "#0f766e",
    accent_color: null,
  },
  tenantLanding: {
    headline: "Welcome to Acme",
    message: null,
    support_email: null,
    support_phone: null,
    background: "brand",
  },
  tenantDefaultLocale: "am",
  carryOrganisation: "acme",
  tenantRequest: { mode: "entry", slug: "acme" },
  tenantContextLoading: false,
};

const queryClient = () =>
  new QueryClient({ defaultOptions: { queries: { retry: false } } });

function page(form: React.ReactNode, client: QueryClient) {
  return (
    <QueryClientProvider client={client}>
      <AuthLayoutClient>{form}</AuthLayoutClient>
    </QueryClientProvider>
  );
}

beforeEach(() => {
  hostContextValue.mockReset();
  localStorage.clear();
  localStorage.setItem("locale", "en");
});

describe("an auth page that names its organisation in the query", () => {
  it("keeps the new password typed before the organisation's context arrives", async () => {
    location.pathname = "/login/reset";
    location.search = `token=${"0".repeat(64)}&email=abebe%40acme.et&tenant=acme`;
    window.history.replaceState({}, "", `/login/reset?${location.search}`);
    const client = queryClient();
    hostContextValue.mockReturnValue(arriving);

    const { rerender } = render(page(<ResetPasswordForm />, client));
    await screen.findByRole("heading", { name: "Set a new password" });
    await userEvent.type(
      screen.getByLabelText(/New password/),
      "Correct-Horse-Battery-9",
    );

    hostContextValue.mockReturnValue(arrived);
    rerender(page(<ResetPasswordForm />, client));

    expect(screen.getByLabelText(/New password/)).toHaveValue(
      "Correct-Horse-Battery-9",
    );
    // The page is now the organisation's, so the context really did arrive.
    expect(screen.getAllByText("Acme Manufacturing").length).toBeGreaterThan(0);
  });

  it("keeps the email typed on forgot-password reached from the shared sign-in", async () => {
    location.pathname = "/login/forgot";
    location.search = "org=acme";
    window.history.replaceState({}, "", "/login/forgot?org=acme");
    const client = queryClient();
    hostContextValue.mockReturnValue(arriving);

    const { rerender } = render(page(<ForgotForm />, client));
    await userEvent.type(screen.getByLabelText(/Email/), "abebe@acme.et");

    hostContextValue.mockReturnValue(arrived);
    rerender(page(<ForgotForm />, client));

    expect(screen.getByLabelText(/Email/)).toHaveValue("abebe@acme.et");
  });
});
