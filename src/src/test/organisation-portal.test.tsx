import { afterEach, describe, it, expect } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { OrganisationPortal } from "@/features/auth/components/organisation-portal";
import type { useAuthHostContext } from "@/lib/auth/use-auth-host-context";
import { vi } from "vitest";
import type { LandingFeed } from "@/features/auth/landing-feed";

/**
 * The organisation portal around the sign-in form: news, open positions and
 * holidays under the hero. The server decides what is public; the page must
 * ask the right organisation (X-Tenant on the shared landing), render text as
 * text, and render no section when there is nothing.
 */
type HostCtx = ReturnType<typeof useAuthHostContext>;

const hostContextValue = vi.fn<() => HostCtx>();
vi.mock("@/lib/auth/use-auth-host-context", () => ({
  useAuthHostContext: () => hostContextValue(),
}));
const theme = { resolvedTheme: "light" as string };
vi.mock("next-themes", () => ({
  useTheme: () => theme,
}));

function organisationContext(): HostCtx {
  return {
    context: "tenant",
    tenantSlug: "acme",
    tenantName: "Acme Manufacturing",
    tenantLogoPath: null,
    tenantBrand: null,
    tenantLanding: {
      headline: "Welcome to Acme",
      message: null,
      support_email: "hr@acme.et",
      support_phone: null,
      background: "brand",
    },
    tenantSignIn: null,
    tenantDefaultLocale: null,
    onLanding: true,
    carryOrganisation: "acme",
    tenantRequest: { mode: "entry", slug: "acme" },
    tenantContextLoading: false,
    tenantLookupError: null,
    tenantFieldVisible: false,
  };
}
const feed: LandingFeed = {
  announcements: [
    {
      public_id: "a1",
      title: "Plant reopens Monday",
      excerpt: "All shifts resume at 06:00.",
      priority: "urgent",
      published_at: "2026-10-12T08:00:00Z",
    },
  ],
  vacancies: [
    {
      public_id: "v1",
      title: "Machine operator",
      employment_type: "full_time",
      location: "Adama",
      department: "Production",
      branch: null,
      positions: 2,
      published_at: "2026-10-10T08:00:00Z",
      closes_on: "2026-10-30",
      apply_email: "jobs@acme.et",
      apply_url: null,
    },
  ],
  holidays: [],
};

function renderNews() {
  hostContextValue.mockReturnValue(organisationContext());
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <OrganisationPortal>
        <p>the sign-in form</p>
      </OrganisationPortal>
    </QueryClientProvider>,
  );
}

describe("<OrganisationPortal>", () => {
  it("asks for the organisation in the address and shows its news and openings", async () => {
    let tenantHeader: string | null = null;
    server.use(
      http.get("*/auth/tenant-landing/feed", ({ request }) => {
        tenantHeader = request.headers.get("X-Tenant");
        return HttpResponse.json(feed);
      }),
    );

    renderNews();

    expect(await screen.findByText("Plant reopens Monday")).toBeInTheDocument();
    expect(tenantHeader).toBe("acme");
    expect(screen.getByText("Urgent")).toBeInTheDocument();
    expect(screen.getByText("Machine operator")).toBeInTheDocument();
    expect(
      screen.getByText("Full-time · Production · Adama"),
    ).toBeInTheDocument();
  });

  it("opens a vacancy in full with a way to apply", async () => {
    server.use(
      http.get("*/auth/tenant-landing/feed", () => HttpResponse.json(feed)),
      http.get("*/auth/tenant-landing/vacancies/v1", () =>
        HttpResponse.json({
          ...feed.vacancies[0],
          description: "Run the CNC line.",
          requirements: "Two years experience.",
        }),
      ),
    );

    renderNews();
    fireEvent.click(
      await screen.findByRole("button", { name: /machine operator/i }),
    );

    expect(await screen.findByText("Run the CNC line.")).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: /apply by e-mail/i }),
    ).toHaveAttribute("href", "mailto:jobs@acme.et?subject=Machine%20operator");
  });

  it("renders markup as text, never as HTML", async () => {
    server.use(
      http.get("*/auth/tenant-landing/feed", () =>
        HttpResponse.json({
          ...feed,
          announcements: [
            { ...feed.announcements[0], title: "<img src=x onerror=alert(1)>" },
          ],
        }),
      ),
    );

    const { container } = renderNews();

    expect(
      await screen.findByText("<img src=x onerror=alert(1)>"),
    ).toBeInTheDocument();
    expect(container.querySelector("img")).toBeNull();
  });

  it("keeps the hero, header and footer around the form", async () => {
    server.use(
      http.get("*/auth/tenant-landing/feed", () => HttpResponse.json(feed)),
    );

    renderNews();

    // The hero headline, the sign-in card, and the header link back to it.
    expect(
      await screen.findByRole("heading", { name: "Welcome to Acme" }),
    ).toBeInTheDocument();
    expect(screen.getByText("the sign-in form")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /sign in/i })).toHaveAttribute(
      "href",
      "#sign-in",
    );
    expect(screen.getByText(/powered by ethr/i)).toBeInTheDocument();
  });

  it("renders no sections when the organisation published nothing", async () => {
    let asked = false;
    server.use(
      http.get("*/auth/tenant-landing/feed", () => {
        asked = true;
        return HttpResponse.json({
          announcements: [],
          vacancies: [],
          holidays: [],
        });
      }),
    );

    renderNews();
    await new Promise((r) => setTimeout(r, 80));

    expect(asked).toBe(true);
    expect(screen.queryByText(/latest news/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/open positions/i)).not.toBeInTheDocument();
    // The form is still there: an empty portal is still the sign-in page.
    expect(screen.getByText("the sign-in form")).toBeInTheDocument();
  });

  it("opens an announcement in full", async () => {
    server.use(
      http.get("*/auth/tenant-landing/feed", () => HttpResponse.json(feed)),
      http.get("*/auth/tenant-landing/announcements/a1", () =>
        HttpResponse.json({
          public_id: "a1",
          title: "Plant reopens Monday",
          body: "All shifts resume at 06:00. Gate B stays closed.",
          priority: "urgent",
          published_at: "2026-10-12T08:00:00Z",
        }),
      ),
    );

    renderNews();
    fireEvent.click(
      await screen.findByRole("button", { name: /plant reopens monday/i }),
    );

    expect(await screen.findByText(/gate b stays closed/i)).toBeInTheDocument();
  });
});

/**
 * The organisation's uploaded images on the portal (PR 2 of 4): the cover as
 * the hero's picture, the mark as the page's icon, and the dark-background
 * logo once the visitor is in dark mode.
 */
const brand = {
  logo_url: "/api/v1/organisation-logos/T/l0g0",
  logo_dark_url: "/api/v1/organisation-assets/T/logo-dark/d4rk",
  mark_url: "/api/v1/organisation-assets/T/mark/m4rk",
  cover: {
    url: "/api/v1/organisation-assets/T/cover/c0v3r",
    srcset:
      "/api/v1/organisation-assets/T/cover-640/c0v3r 640w, /api/v1/organisation-assets/T/cover/c0v3r 1920w",
    width: 1920,
    height: 960,
    focal_x: 0.25,
    focal_y: 0.75,
  },
  primary_color: "#0f766e",
  secondary_color: null,
  accent_color: null,
};

function renderBranded(
  overrides: Partial<HostCtx> = {},
  background: HostCtx["tenantLanding"] extends infer L
    ? L extends { background: infer B }
      ? B
      : never
    : never = "image",
) {
  const base = organisationContext();
  hostContextValue.mockReturnValue({
    ...base,
    tenantBrand: brand,
    tenantLanding: { ...base.tenantLanding!, background },
    ...overrides,
  });
  server.use(
    http.get("*/auth/tenant-landing/feed", () =>
      HttpResponse.json({ announcements: [], vacancies: [], holidays: [] }),
    ),
  );
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <OrganisationPortal>
        <p>the sign-in form</p>
      </OrganisationPortal>
    </QueryClientProvider>,
  );
}

describe("<OrganisationPortal> brand images", () => {
  afterEach(() => {
    theme.resolvedTheme = "light";
    document.head.innerHTML = "";
  });

  it("draws the cover as the hero's picture, at the screen's width, around its focal point", () => {
    renderBranded();

    const img = screen.getByTestId("hero-cover") as HTMLImageElement;
    expect(img.getAttribute("srcset")).toContain("cover-640/c0v3r 640w");
    expect(img.getAttribute("sizes")).toBe("100vw");
    expect(img.style.objectPosition).toBe("25% 75%");
    expect(img.getAttribute("alt")).toBe("");
  });

  it("draws no cover for the other backgrounds", () => {
    renderBranded({}, "gradient");
    expect(screen.queryByTestId("hero-cover")).not.toBeInTheDocument();
  });

  it("makes the mark the page's icon while the portal is open, and gives the old one back", () => {
    const existing = document.createElement("link");
    existing.rel = "icon";
    existing.href = "/favicon.ico";
    document.head.appendChild(existing);

    const { unmount } = renderBranded();
    expect(existing.getAttribute("href")).toBe(brand.mark_url);

    unmount();
    expect(existing.getAttribute("href")).toBe("/favicon.ico");
  });

  it("shows the dark-background logo in the header only in dark mode", () => {
    const { unmount } = renderBranded();
    const [header] = screen.getAllByRole("img", { name: "Acme Manufacturing" });
    expect(header.getAttribute("src")).toBe(brand.logo_url);
    unmount();

    theme.resolvedTheme = "dark";
    renderBranded();
    const [darkHeader] = screen.getAllByRole("img", {
      name: "Acme Manufacturing",
    });
    expect(darkHeader.getAttribute("src")).toBe(brand.logo_dark_url);
  });
});
