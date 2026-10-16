import { afterEach, describe, it, expect } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { OrganisationPortal } from "@/features/auth/components/organisation-portal";
import type {
  PortalSection,
  useAuthHostContext,
} from "@/lib/auth/use-auth-host-context";
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
      category: "notice",
      featured: false,
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
  branches: [],
  branch_count: null,
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

  // Since 2026-10-16 a story opens on its own page, which can be linked and
  // shared, and the organisation travels with it on the shared host.
  it("links each story to its own page and to all news, keeping the organisation", async () => {
    server.use(
      http.get("*/auth/tenant-landing/feed", () => HttpResponse.json(feed)),
    );

    renderNews();

    expect(
      await screen.findByRole("link", { name: /plant reopens monday/i }),
    ).toHaveAttribute("href", "/news/a1?org=acme");
    expect(screen.getByRole("link", { name: /^all news$/i })).toHaveAttribute(
      "href",
      "/news?org=acme",
    );
    // The header carries the News link too.
    expect(screen.getByRole("link", { name: /^news$/i })).toHaveAttribute(
      "href",
      "/news?org=acme",
    );
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

/**
 * The portal's content sections (PR 3 of 4): the organisation's own words
 * in the viewer's language, the about and contact sections, the order it
 * chose, and the footer's links.
 */
const contentLanding = {
  headline: "Welcome to Acme",
  headline_am: "እንኳን ወደ አክሜ በደህና መጡ",
  message: "Check in when you arrive.",
  message_am: null,
  support_email: "hr@acme.et",
  support_phone: "+251 11 555 0100",
  background: "brand" as const,
  about: "Acme makes pumps in Adama.",
  about_am: "አክሜ በአዳማ ፓምፖችን ይሠራል።",
  founded_year: 1998,
  sector: "manufacturing",
  staff_band: "201-500",
  show_branch_count: true,
  address: "Industrial zone, Adama",
  address_am: null,
  hours: "Mon to Sat, 08:00 to 17:00",
  hours_am: null,
  latitude: 8.54,
  longitude: 39.27,
  show_branches: true,
  links: {
    website: "https://acme.et",
    linkedin: "https://www.linkedin.com/company/acme",
    facebook: null,
    x: null,
    telegram: "https://t.me/acme",
    youtube: null,
    privacy: "https://acme.et/privacy",
    terms: null,
  },
  sections: {
    about: true,
    announcements: true,
    vacancies: true,
    holidays: false,
    contact: true,
  },
  order: [
    "contact",
    "about",
    "announcements",
    "vacancies",
    "holidays",
  ] as PortalSection[],
  listed: true,
};

function renderContent(
  landing: HostCtx["tenantLanding"] = contentLanding,
  feedOverrides: Partial<LandingFeed> = {},
) {
  hostContextValue.mockReturnValue({
    ...organisationContext(),
    tenantLanding: landing,
  });
  server.use(
    http.get("*/auth/tenant-landing/feed", () =>
      HttpResponse.json({
        ...feed,
        branches: [
          {
            public_id: "b1",
            name: "Adama plant",
            name_am: "አዳማ",
            city: "Adama",
            address: "Industrial zone",
            phone: "+251 22 111 0000",
            latitude: 8.54,
            longitude: 39.27,
          },
        ],
        branch_count: 3,
        ...feedOverrides,
      }),
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

describe("<OrganisationPortal> content", () => {
  afterEach(() => {
    localStorage.removeItem("locale");
    window.dispatchEvent(new Event("locale-changed"));
  });

  it("draws the about and contact sections, in the organisation's order", async () => {
    renderContent();

    expect(
      await screen.findByText("Acme makes pumps in Adama."),
    ).toBeInTheDocument();
    expect(screen.getByText("Founded 1998")).toBeInTheDocument();
    expect(screen.getByText("Manufacturing")).toBeInTheDocument();
    expect(screen.getByText("201-500 employees")).toBeInTheDocument();
    expect(screen.getByText("3 branches")).toBeInTheDocument();
    expect(
      screen.getAllByText("Industrial zone, Adama").length,
    ).toBeGreaterThan(0);
    expect(screen.getByText("Mon to Sat, 08:00 to 17:00")).toBeInTheDocument();
    expect(screen.getByText("Adama plant")).toBeInTheDocument();
    expect(
      screen.getAllByRole("link", { name: "Open in Maps" })[0],
    ).toHaveAttribute("href", expect.stringContaining("8.54,39.27"));

    // Contact first, as the organisation ordered it.
    const ids = Array.from(document.querySelectorAll("main section")).map(
      (s) => s.id,
    );
    expect(ids).toEqual(["contact", "about", "news", "careers"]);
  });

  it("hides a section that is switched off, and one with nothing to show", async () => {
    renderContent(
      {
        ...contentLanding,
        sections: { ...contentLanding.sections, contact: false },
        about: null,
        about_am: null,
        founded_year: null,
        sector: null,
        staff_band: null,
        show_branch_count: false,
      },
      { branch_count: null },
    );

    expect(await screen.findByText("Plant reopens Monday")).toBeInTheDocument();
    expect(document.querySelector("#contact")).toBeNull();
    expect(document.querySelector("#about")).toBeNull();
  });

  it("links the organisation's networks and policies in the footer, with ETHR's policy when none is set", async () => {
    renderContent();
    await screen.findByText("Acme makes pumps in Adama.");

    const footer = document.querySelector("footer")!;
    const hrefs = Array.from(footer.querySelectorAll("a")).map((a) => [
      a.textContent,
      a.getAttribute("href"),
    ]);
    expect(hrefs).toEqual(
      expect.arrayContaining([
        ["Website", "https://acme.et"],
        ["LinkedIn", "https://www.linkedin.com/company/acme"],
        ["Telegram", "https://t.me/acme"],
        ["Privacy", "https://acme.et/privacy"],
        ["Terms", "/en/terms"],
      ]),
    );
    expect(footer.textContent).not.toContain("Facebook");
  });

  it("shows the Amharic words when the viewer reads Amharic, and falls back to English", async () => {
    localStorage.setItem("locale", "am");
    window.dispatchEvent(new Event("locale-changed"));
    renderContent();

    expect(await screen.findByRole("heading", { level: 1 })).toHaveTextContent(
      "እንኳን ወደ አክሜ በደህና መጡ",
    );
    expect(await screen.findByText("አክሜ በአዳማ ፓምፖችን ይሠራል።")).toBeInTheDocument();
    // No Amharic message was written: the English one stands in.
    expect(screen.getByText("Check in when you arrive.")).toBeInTheDocument();
  });
});

describe("<OrganisationPortal> branch count", () => {
  // Found on the live portal, 2026-10-15: one place read "1 branches".
  it("says one branch in the singular", async () => {
    renderContent(contentLanding, { branch_count: 1 });
    expect(await screen.findByText("1 branch")).toBeInTheDocument();
    expect(screen.queryByText("1 branches")).not.toBeInTheDocument();
  });
});
