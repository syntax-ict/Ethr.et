import { describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { LandingCard } from "@/features/settings/components/landing-card";
import type {
  TenantAddress,
  TenantLandingSettings,
} from "@/features/settings/api";

// jsdom has no canvas; the code's value is what matters here.
vi.mock("qrcode.react", () => ({
  QRCodeCanvas: ({ value }: { value: string }) => (
    <canvas data-testid="landing-qr" data-value={value} />
  ),
}));

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

/**
 * The admin's side of the organisation sign-in page: what they type is what
 * the page shows (live), what is sent is clean, and the link they share is the
 * organisation's own address when it has one.
 */
const landing: TenantLandingSettings = {
  headline: null,
  message: null,
  support_email: null,
  support_phone: null,
  background: "brand",
  sections: { announcements: false, vacancies: false, holidays: false },
};

const pathAddress = {
  mode: "path",
  effective_mode: "path",
  canonical_url: null,
  entry_url: "https://ethr.et/acme",
  subdomain: "acme",
  subdomain_url: null,
  subdomains_served: false,
  custom_domain: null,
  custom_domain_status: null,
  custom_domain_allowed: false,
  custom_domain_dns: null,
} as TenantAddress;

function renderCard(
  props: Partial<React.ComponentProps<typeof LandingCard>> = {},
) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <LandingCard
        landing={landing}
        address={pathAddress}
        organisationName="Acme Manufacturing"
        logoUrl={null}
        secondaryColor="#14b8a6"
        {...props}
      />
    </QueryClientProvider>,
  );
}

describe("<LandingCard>", () => {
  it("previews what is typed, falling back to the organisation's name", () => {
    renderCard();
    const preview = screen.getByTestId("landing-preview");

    expect(preview).toHaveTextContent("Acme Manufacturing");

    fireEvent.change(screen.getByLabelText("Headline"), {
      target: { value: "Welcome to Acme" },
    });
    expect(preview).toHaveTextContent("Welcome to Acme");
    expect(screen.getByText("15/80")).toBeInTheDocument();
  });

  it("sends trimmed values, nulls for blanks, and no photo unless chosen", async () => {
    let body: Record<string, unknown> | null = null;
    server.use(
      http.put("*/settings/landing", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ ...landing, ...body });
      }),
    );

    renderCard();
    fireEvent.change(screen.getByLabelText("Headline"), {
      target: { value: "  Welcome  " },
    });
    fireEvent.change(screen.getByLabelText("HR help e-mail"), {
      target: { value: "hr@acme.et" },
    });
    fireEvent.click(screen.getByRole("radio", { name: "Gradient" }));
    fireEvent.click(screen.getByRole("button", { name: /save sign-in page/i }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toEqual({
      headline: "Welcome",
      message: null,
      support_email: "hr@acme.et",
      support_phone: null,
      background: "gradient",
    });
  });

  it("offers the cover upload under the photo background, and holds the save until one exists", () => {
    renderCard();
    expect(screen.queryByLabelText("Cover photo file")).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole("radio", { name: "Photo" }));

    expect(screen.getByLabelText("Cover photo file")).toBeInTheDocument();
    expect(screen.getByRole("note")).toHaveTextContent(
      "Upload a cover photo to use the photo background.",
    );
    expect(
      screen.getByRole("button", { name: /save sign-in page/i }),
    ).toBeDisabled();
  });

  it("previews the uploaded cover around its focal point and lets the photo be saved", () => {
    renderCard({
      cover: {
        url: "/api/v1/organisation-assets/T/cover/abc",
        srcset: "/api/v1/organisation-assets/T/cover/abc 1920w",
        width: 1920,
        height: 960,
        focal_x: 0.3,
        focal_y: 0.6,
      },
    });
    fireEvent.click(screen.getByRole("radio", { name: "Photo" }));

    const preview = screen.getByTestId("landing-preview");
    expect(preview.style.backgroundImage).toContain(
      'url("/api/v1/organisation-assets/T/cover/abc")',
    );
    expect(preview.style.backgroundPosition).toBe("30% 60%");
    expect(screen.getByText("Your cover photo is in use")).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: /save sign-in page/i }),
    ).toBeEnabled();
  });

  it("offers the pattern background", () => {
    renderCard();
    fireEvent.click(screen.getByRole("radio", { name: "Pattern" }));
    expect(
      screen.getByTestId("landing-preview").style.backgroundImage,
    ).toContain("radial-gradient");
  });

  it("shares ethr.et/{slug} on the shared address", () => {
    renderCard();
    expect(screen.getByTestId("landing-link")).toHaveTextContent(
      "https://ethr.et/acme",
    );
    expect(screen.getByTestId("landing-qr")).toHaveAttribute(
      "data-value",
      "https://ethr.et/acme",
    );
  });

  it("shares the organisation's own address when it has one", () => {
    renderCard({
      address: {
        ...pathAddress,
        mode: "subdomain",
        effective_mode: "subdomain",
        canonical_url: "https://acme.ethr.et",
      } as TenantAddress,
    });
    expect(screen.getByTestId("landing-link")).toHaveTextContent(
      "https://acme.ethr.et/login",
    );
    expect(screen.getByRole("link", { name: /open/i })).toHaveAttribute(
      "href",
      "https://acme.ethr.et/login",
    );
  });
});
