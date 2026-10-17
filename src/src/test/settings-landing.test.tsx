import { describe, it, expect, vi } from "vitest";
import {
  render,
  screen,
  fireEvent,
  waitFor,
  within,
} from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { LandingCard } from "@/features/settings/components/landing-card";
import type {
  TenantAddress,
  TenantLandingSettings,
} from "@/features/settings/api";
import type { TenantBrand } from "@/lib/auth/use-auth-host-context";

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
 * The admin's side of the organisation portal: what they type is what the
 * page shows (live, in the preview), what is sent is clean, and the link
 * they share is the organisation's own address when it has one.
 */
const landing: TenantLandingSettings = {
  headline: null,
  headline_am: null,
  message: null,
  message_am: null,
  support_email: null,
  support_phone: null,
  background: "brand",
  about: null,
  about_am: null,
  founded_year: null,
  sector: null,
  staff_band: null,
  show_branch_count: false,
  address: null,
  address_am: null,
  hours: null,
  hours_am: null,
  latitude: null,
  longitude: null,
  show_branches: false,
  links: {
    website: null,
    linkedin: null,
    facebook: null,
    x: null,
    telegram: null,
    youtube: null,
    privacy: null,
    terms: null,
  },
  faqs: [],
  services: [],
  sections: {
    about: true,
    services: true,
    announcements: false,
    vacancies: false,
    holidays: false,
    faq: true,
    contact: true,
  },
  order: [
    "about",
    "services",
    "announcements",
    "vacancies",
    "holidays",
    "faq",
    "contact",
  ],
  listed: false,
};

const brand: TenantBrand = {
  logo_url: null,
  logo_dark_url: null,
  mark_url: null,
  cover: null,
  primary_color: "#0f766e",
  secondary_color: "#14b8a6",
  accent_color: null,
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

const emptyFeed = {
  announcements: [],
  vacancies: [],
  holidays: [],
  branches: [],
  branch_count: null,
};

function renderCard(
  props: Partial<React.ComponentProps<typeof LandingCard>> = {},
) {
  server.use(
    http.get("*/auth/tenant-landing/feed", () => HttpResponse.json(emptyFeed)),
  );
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <LandingCard
        landing={landing}
        address={pathAddress}
        organisationName="Acme Manufacturing"
        brand={brand}
        {...props}
      />
    </QueryClientProvider>,
  );
}

const preview = () => screen.getByTestId("portal-preview");

describe("<LandingCard>", () => {
  it("previews what is typed, falling back to the organisation's name", () => {
    renderCard();

    const heading = () => preview().querySelector("h1")!;
    expect(heading()).toHaveTextContent("Acme Manufacturing");

    fireEvent.change(screen.getByLabelText("Headline (English)"), {
      target: { value: "Welcome to the plant" },
    });
    expect(heading()).toHaveTextContent("Welcome to the plant");
  });

  it("sends trimmed values, nulls for blanks, numbers as numbers and every switch", async () => {
    const user = userEvent.setup();
    let body: Record<string, unknown> | null = null;
    server.use(
      http.put("*/settings/landing", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ ...landing, ...body });
      }),
    );

    renderCard();
    fireEvent.change(screen.getByLabelText("Headline (English)"), {
      target: { value: "  Welcome  " },
    });
    await user.click(screen.getByRole("tab", { name: "About" }));
    fireEvent.change(screen.getByLabelText("Founded in"), {
      target: { value: "1998" },
    });
    await user.click(screen.getByRole("tab", { name: "Contact" }));
    fireEvent.change(screen.getByLabelText("HR help e-mail"), {
      target: { value: "hr@acme.et" },
    });
    fireEvent.change(screen.getByLabelText("Latitude"), {
      target: { value: "8.54" },
    });
    fireEvent.change(screen.getByLabelText("Longitude"), {
      target: { value: "39.27" },
    });
    await user.click(screen.getByRole("tab", { name: "Links" }));
    fireEvent.change(screen.getByLabelText("Telegram"), {
      target: { value: "https://t.me/acme" },
    });
    fireEvent.click(screen.getByRole("button", { name: /save sign-in page/i }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toMatchObject({
      headline: "Welcome",
      headline_am: null,
      message: null,
      support_email: "hr@acme.et",
      support_phone: null,
      background: "brand",
      founded_year: 1998,
      sector: null,
      latitude: 8.54,
      longitude: 39.27,
      show_branches: false,
      links: { telegram: "https://t.me/acme", website: null },
      sections: landing.sections,
      order: landing.order,
      listed: false,
    });
  });

  it("reorders sections, switches them and the listing, and previews the result", async () => {
    const user = userEvent.setup();
    let body: Record<string, unknown> | null = null;
    server.use(
      http.put("*/settings/landing", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ ...landing, ...body });
      }),
    );

    renderCard({
      landing: { ...landing, about: "We make pumps.", address: "Adama" },
    });
    await user.click(screen.getByRole("tab", { name: "Sections" }));
    for (let i = 0; i < 6; i++) {
      fireEvent.click(screen.getByRole("button", { name: "Move Contact up" }));
    }
    fireEvent.click(
      screen.getByRole("switch", { name: "List this page in search engines" }),
    );

    await waitFor(() => {
      const ids = Array.from(preview().querySelectorAll("main section")).map(
        (s) => s.id,
      );
      expect(ids).toEqual(["contact", "about"]);
    });

    fireEvent.click(screen.getByRole("switch", { name: "About us" }));
    await waitFor(() => expect(preview().querySelector("#about")).toBeNull());

    fireEvent.click(screen.getByRole("button", { name: /save sign-in page/i }));
    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toMatchObject({
      order: [
        "contact",
        "about",
        "services",
        "announcements",
        "vacancies",
        "holidays",
        "faq",
      ],
      sections: { ...landing.sections, about: false },
      listed: true,
    });
  });

  it("writes, reorders and removes questions, previews them, and sends only the filled ones", async () => {
    const user = userEvent.setup();
    let body: Record<string, unknown> | null = null;
    server.use(
      http.put("*/settings/landing", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ ...landing, ...body });
      }),
    );

    renderCard();
    await user.click(screen.getByRole("tab", { name: "Questions" }));
    expect(screen.getByText("No questions yet.")).toBeInTheDocument();

    for (let i = 0; i < 3; i++) {
      await user.click(screen.getByRole("button", { name: "Add a question" }));
    }
    fireEvent.change(screen.getByLabelText("Question 1 (English)"), {
      target: { value: "  When is payday?  " },
    });
    fireEvent.change(screen.getByLabelText("Answer 1 (English)"), {
      target: { value: "The 28th." },
    });
    fireEvent.change(screen.getByLabelText("Question 2 (አማርኛ)"), {
      target: { value: "የስራ ሰዓት?" },
    });
    fireEvent.change(screen.getByLabelText("Answer 2 (አማርኛ)"), {
      target: { value: "ከ 2:30 እስከ 11:30።" },
    });
    // The second goes first; the third is left blank and is not sent.
    fireEvent.click(screen.getByRole("button", { name: "Move Question 2 up" }));

    await waitFor(() =>
      expect(
        within(preview()).getByText("Frequently asked questions"),
      ).toBeInTheDocument(),
    );
    expect(within(preview()).getByText("When is payday?")).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: /save sign-in page/i }));
    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toMatchObject({
      faqs: [
        {
          question: null,
          question_am: "የስራ ሰዓት?",
          answer: null,
          answer_am: "ከ 2:30 እስከ 11:30።",
        },
        {
          question: "When is payday?",
          question_am: null,
          answer: "The 28th.",
          answer_am: null,
        },
      ],
    });

    fireEvent.click(screen.getByRole("button", { name: "Remove Question 1" }));
    expect(screen.queryByDisplayValue("የስራ ሰዓት?")).toBeNull();
    expect(screen.getByDisplayValue("When is payday?")).toBeInTheDocument();
  });

  it("writes the services offered, previews them as cards, and sends them named", async () => {
    const user = userEvent.setup();
    let body: Record<string, unknown> | null = null;
    server.use(
      http.put("*/settings/landing", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ ...landing, ...body });
      }),
    );

    renderCard();
    await user.click(screen.getByRole("tab", { name: "Services" }));
    expect(screen.getByText("No services yet.")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Add a service" }));
    await user.click(screen.getByRole("button", { name: "Add a service" }));
    fireEvent.change(screen.getByLabelText("Service 1 (English)"), {
      target: { value: "Pump repair" },
    });
    fireEvent.change(screen.getByLabelText("Description 1 (English)"), {
      target: { value: "On site within 48 hours." },
    });

    await waitFor(() =>
      expect(within(preview()).getByText("Our services")).toBeInTheDocument(),
    );
    expect(
      within(preview()).getByRole("heading", { level: 3, name: "Pump repair" }),
    ).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: /save sign-in page/i }));
    await waitFor(() => expect(body).not.toBeNull());
    // The second, left blank, is not sent.
    expect(body).toMatchObject({
      services: [
        {
          title: "Pump repair",
          title_am: null,
          description: "On site within 48 hours.",
          description_am: null,
        },
      ],
    });
  });

  it("stops adding at twelve questions", async () => {
    const user = userEvent.setup();
    renderCard({
      landing: {
        ...landing,
        faqs: Array.from({ length: 12 }, (_, i) => ({
          question: `Q${i}`,
          question_am: null,
          answer: "A",
          answer_am: null,
        })),
      },
    });
    await user.click(screen.getByRole("tab", { name: "Questions" }));

    expect(
      screen.getByRole("button", { name: "Twelve questions is the most" }),
    ).toBeDisabled();
  });

  it("shows the server's refusal under the field it names, nested links included", async () => {
    const user = userEvent.setup();
    server.use(
      http.put("*/settings/landing", () =>
        HttpResponse.json(
          {
            message: "Invalid",
            errors: {
              "links.linkedin": ["This link must be on linkedin.com."],
            },
          },
          { status: 422 },
        ),
      ),
    );

    renderCard();
    await user.click(screen.getByRole("tab", { name: "Links" }));
    fireEvent.change(screen.getByLabelText("LinkedIn"), {
      target: { value: "https://evil.example/linkedin" },
    });
    fireEvent.click(screen.getByRole("button", { name: /save sign-in page/i }));

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "This link must be on linkedin.com.",
    );
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

  it("previews the uploaded cover as the hero picture and lets the photo be saved", () => {
    renderCard({
      brand: {
        ...brand,
        cover: {
          url: "/api/v1/organisation-assets/T/cover/abc",
          srcset: "/api/v1/organisation-assets/T/cover/abc 1920w",
          width: 1920,
          height: 960,
          focal_x: 0.3,
          focal_y: 0.6,
        },
      },
    });
    fireEvent.click(screen.getByRole("radio", { name: "Photo" }));

    const hero = preview().querySelector(
      '[data-testid="hero-cover"]',
    ) as HTMLImageElement;
    expect(hero.getAttribute("srcset")).toContain("cover/abc 1920w");
    expect(hero.style.objectPosition).toBe("30% 60%");
    expect(screen.getByText("Your cover photo is in use")).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: /save sign-in page/i }),
    ).toBeEnabled();
  });

  it("previews at phone or desktop width, light or dark, in English or Amharic", async () => {
    renderCard({ landing: { ...landing, headline_am: "እንኳን ደህና መጡ" } });

    expect(preview()).toHaveAttribute("data-width", "phone");
    fireEvent.click(screen.getByRole("radio", { name: "Desktop" }));
    expect(preview()).toHaveAttribute("data-width", "desktop");

    fireEvent.click(screen.getByRole("radio", { name: "Dark" }));
    expect(preview()).toHaveAttribute("data-theme", "dark");
    expect(preview().querySelector(".dark")).not.toBeNull();

    fireEvent.click(screen.getByRole("radio", { name: "አማርኛ" }));
    await waitFor(() =>
      expect(preview().querySelector("h1")).toHaveTextContent("እንኳን ደህና መጡ"),
    );
    // The reader's own language is untouched by the preview.
    expect(document.documentElement.lang).not.toBe("am");
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
        mode: "custom_domain",
        effective_mode: "custom_domain",
        canonical_url: "https://hr.acme.et",
      } as TenantAddress,
    });
    expect(screen.getByTestId("landing-link")).toHaveTextContent(
      "https://hr.acme.et/login",
    );
  });
});
