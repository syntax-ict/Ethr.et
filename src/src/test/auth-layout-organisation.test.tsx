import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { AuthLayoutClient } from "@/app/(auth)/auth-layout-client";
import { panelBackground } from "@/lib/auth/landing-background";
import type {
  TenantLanding,
  useAuthHostContext,
} from "@/lib/auth/use-auth-host-context";

/**
 * An organisation's sign-in page (ethr.et/{slug}) in its own words: the
 * headline, message, help contacts and background its admin set, and its
 * language for a first visit. The values arrive filtered by the server; this
 * pins what the page does with them.
 */
type HostCtx = ReturnType<typeof useAuthHostContext>;

const hostContextValue = vi.fn<() => HostCtx>();
vi.mock("@/lib/auth/use-auth-host-context", () => ({
  useAuthHostContext: () => hostContextValue(),
}));

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), back: vi.fn() }),
  usePathname: () => "/acme",
}));

const landing: TenantLanding = {
  headline: "Welcome to Acme",
  message: "Check in when you arrive at the plant.",
  support_email: "hr@acme.et",
  support_phone: "+251 11 555 0100",
  background: "brand",
  background_image_url: null,
};

const organisation: HostCtx = {
  context: "tenant",
  tenantSlug: "acme",
  tenantName: "Acme Manufacturing",
  tenantLogoPath: null,
  tenantBrand: {
    logo_url: null,
    primary_color: "#0f766e",
    secondary_color: "#14b8a6",
    accent_color: null,
  },
  tenantLanding: landing,
  tenantSignIn: { sso: false, otp: false },
  tenantDefaultLocale: "en",
  onLanding: true,
  carryOrganisation: null,
  tenantRequest: null,
  tenantContextLoading: false,
  tenantLookupError: null,
  tenantFieldVisible: false,
};

function renderLayout() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={queryClient}>
      <AuthLayoutClient>
        <p>form</p>
      </AuthLayoutClient>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  hostContextValue.mockReset();
  localStorage.clear();
  localStorage.setItem("locale", "en");
});

describe("organisation sign-in page", () => {
  it("shows the organisation's headline, message and help contacts", () => {
    hostContextValue.mockReturnValue(organisation);

    renderLayout();

    expect(
      screen.getByRole("heading", { name: "Welcome to Acme" }),
    ).toBeInTheDocument();
    expect(
      screen.getByText("Check in when you arrive at the plant."),
    ).toBeInTheDocument();
    // Desktop panel and the phone header both carry the contacts.
    const mail = screen.getAllByRole("link", { name: /hr@acme\.et/ });
    expect(mail[0]).toHaveAttribute("href", "mailto:hr@acme.et");
    const phone = screen.getAllByRole("link", { name: /\+251 11 555 0100/ });
    expect(phone[0]).toHaveAttribute("href", "tel:+251115550100");
  });

  it("falls back to its name and the standard welcome", () => {
    hostContextValue.mockReturnValue({
      ...organisation,
      tenantLanding: {
        ...landing,
        headline: null,
        message: null,
        support_email: null,
        support_phone: null,
      },
    });

    renderLayout();

    expect(
      screen.getByRole("heading", { name: "Acme Manufacturing" }),
    ).toBeInTheDocument();
    expect(
      screen.getByText("Your workspace for attendance, leave and pay."),
    ).toBeInTheDocument();
    expect(screen.queryByText(/trouble signing in/i)).not.toBeInTheDocument();
  });

  it("opens a first visit in the organisation's language, and keeps a choice already made", () => {
    localStorage.clear();
    hostContextValue.mockReturnValue({
      ...organisation,
      tenantDefaultLocale: "am",
    });

    renderLayout();
    expect(localStorage.getItem("locale")).toBe("am");

    localStorage.setItem("locale", "en");
    renderLayout();
    expect(localStorage.getItem("locale")).toBe("en");
  });
});

describe("panel background", () => {
  it("is the brand colour by default", () => {
    expect(panelBackground(landing, "#14b8a6")).toBeUndefined();
  });

  it("runs into the secondary colour for a gradient", () => {
    const style = panelBackground(
      { ...landing, background: "gradient" },
      "#14b8a6",
    );
    expect(String(style?.backgroundImage)).toContain("#14b8a6");
  });

  it("quotes the photo so its URL cannot escape url()", () => {
    const style = panelBackground(
      {
        ...landing,
        background: "image",
        background_image_url: 'https://cdn.acme.et/a.jpg"),red',
      },
      null,
    );
    expect(String(style?.backgroundImage)).toContain(
      'url("https://cdn.acme.et/a.jpg\\"),red")',
    );
  });

  it("ignores an image background with no image", () => {
    expect(
      panelBackground({ ...landing, background: "image" }, null),
    ).toBeUndefined();
  });
});
