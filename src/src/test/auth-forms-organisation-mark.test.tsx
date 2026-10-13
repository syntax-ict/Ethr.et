import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { ForgotForm } from "@/app/(auth)/login/forgot/forgot-form";
import { ResetPasswordForm } from "@/app/(auth)/login/reset/reset-form";
import { OtpForm } from "@/app/(auth)/login/otp/otp-form";
import type { useAuthHostContext } from "@/lib/auth/use-auth-host-context";

/**
 * Seen live on 2026-10-13: mersa-city.ethr.et's sign-in page carried the
 * organisation's mark, and one click on "Forgot password?" swapped it for
 * ETHR's "E" above the same organisation's name. The forgot and reset pages
 * now show the organisation's mark wherever the page is the organisation's.
 */
type HostCtx = ReturnType<typeof useAuthHostContext>;

const hostContextValue = vi.fn<() => HostCtx>();
vi.mock("@/lib/auth/use-auth-host-context", () => ({
  useAuthHostContext: () => hostContextValue(),
}));

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), back: vi.fn() }),
  useSearchParams: () =>
    new URLSearchParams("token=t&email=a%40b.c&tenant=mersa-city"),
}));

const generic: HostCtx = {
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

const organisation: HostCtx = {
  ...generic,
  context: "tenant",
  tenantSlug: "mersa-city",
  tenantName: "Mersa City",
  tenantFieldVisible: false,
};

function renderWithQuery(ui: React.ReactElement) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>{ui}</QueryClientProvider>,
  );
}

beforeEach(() => {
  hostContextValue.mockReset();
  localStorage.setItem("locale", "en");
});

describe.each([
  ["forgot password", () => <ForgotForm />],
  ["reset password", () => <ResetPasswordForm />],
])("the %s page", (_name, page) => {
  it("shows the organisation's mark on its address", () => {
    hostContextValue.mockReturnValue(organisation);

    renderWithQuery(page());

    expect(screen.getAllByText("M").length).toBeGreaterThan(0);
    expect(screen.queryByText("E")).not.toBeInTheDocument();
  });

  it("keeps ETHR's mark where no organisation is named", () => {
    hostContextValue.mockReturnValue(generic);

    renderWithQuery(page());

    expect(screen.getByText("E")).toBeInTheDocument();
  });
});

const LOGO = "/api/v1/organisation-logos/01HXYZ/0123456789abcdef";

// The organisation card under the title showed a building icon on these
// pages while the sign-in page's card showed the uploaded logo (seen live on
// syntax.ethr.et, 2026-10-13).
// Forgot shows the logo twice (the mark above the title and the card); the
// text-code page once (its card).
describe.each([
  ["forgot password", () => <ForgotForm />, 2],
  ["text-code sign-in", () => <OtpForm />, 1],
])("the %s page's organisation card", (_name, page, logos) => {
  it("shows the organisation's logo", () => {
    hostContextValue.mockReturnValue({
      ...organisation,
      tenantBrand: {
        logo_url: LOGO,
        primary_color: null,
        accent_color: null,
      } as HostCtx["tenantBrand"],
    });

    const { container } = renderWithQuery(page());

    const srcs = [...container.querySelectorAll("img")].map((i) =>
      i.getAttribute("src"),
    );
    expect(srcs.filter((src) => src === LOGO)).toHaveLength(logos);
  });
});
