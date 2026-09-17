import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";

import { server } from "./msw/server";
import PublicPageSettings from "@/app/(dashboard)/settings/public-page/page";

/**
 * The tenant public page settings screen.
 *
 * The behaviour worth protecting here is not the form — it is that saving
 * content cannot publish the page. Publishing puts an organisation's name,
 * address and branding on the public internet, and it must be a deliberate act
 * every time, never a side effect of fixing a typo. Two tests below assert each
 * half of that, and they are the ones to keep if the rest is ever rewritten.
 */

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    role: "tenant_admin",
    level: 90,
    permissions: [],
    hasPermission: () => true,
    isAtLeast: () => true,
    hasRole: () => true,
    isSuperAdmin: false,
    isTenantAdmin: true,
    isHrAdmin: true,
    can: { manageSettings: true },
  }),
}));

const emptyPage = {
  url: "https://habru.ethr.et",
  is_published: false,
  is_indexable: true,
  headline: null,
  description: null,
  contact_email: null,
  contact_phone: null,
  address_line: null,
  city: null,
  region: null,
  website_url: null,
  social_links: {},
  meta_description: null,
  has_hero_image: false,
  has_public_logo: true,
  published_at: null,
};

function mockPublicPage(overrides: Record<string, unknown> = {}) {
  server.use(
    http.get("*/settings/public-page", () =>
      HttpResponse.json({ public_page: { ...emptyPage, ...overrides } }),
    ),
  );
}

function renderScreen() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });

  return render(
    <QueryClientProvider client={client}>
      <PublicPageSettings />
    </QueryClientProvider>,
  );
}

beforeEach(() => mockPublicPage());

describe("<PublicPageSettings>", () => {
  it("shows a skeleton while the settings are loading", () => {
    server.use(
      http.get(
        "*/settings/public-page",
        () => new Promise(() => {}), // never resolves
      ),
    );

    const { container } = renderScreen();

    // QueryBoundary's loading state — convention 13 wants all four handled.
    expect(container.querySelector(".animate-pulse")).toBeTruthy();
  });

  it("offers a retry when the settings fail to load", async () => {
    server.use(
      http.get("*/settings/public-page", () =>
        HttpResponse.json({ message: "boom" }, { status: 500 }),
      ),
    );

    renderScreen();

    expect(
      await screen.findByRole("button", { name: /try again/i }),
    ).toBeInTheDocument();
  });

  it("renders an empty form for a tenant that has never configured a page", async () => {
    renderScreen();

    expect(await screen.findByLabelText("Headline")).toHaveValue("");
    expect(screen.getByText("https://habru.ethr.et")).toBeInTheDocument();
  });

  it("seeds the form from the saved page", async () => {
    mockPublicPage({
      headline: "Weaving since 1974",
      contact_phone: "+251911223344",
      social_links: { telegram: "https://t.me/habru" },
    });

    renderScreen();

    expect(await screen.findByLabelText("Headline")).toHaveValue(
      "Weaving since 1974",
    );
    expect(screen.getByLabelText("Phone")).toHaveValue("+251911223344");
    expect(screen.getByLabelText("Telegram")).toHaveValue("https://t.me/habru");
  });

  // ── The two that matter ───────────────────────────────────────────────────

  it("does not publish the page when content is saved", async () => {
    let body: Record<string, unknown> | null = null;
    server.use(
      http.put("*/settings/public-page", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ message: "ok", public_page: emptyPage });
      }),
    );

    renderScreen();

    fireEvent.change(await screen.findByLabelText("Headline"), {
      target: { value: "Weaving since 1974" },
    });
    fireEvent.click(screen.getByRole("button", { name: /^save$/i }));

    await waitFor(() => expect(body).not.toBeNull());

    // The save payload must not carry `is_published` at all. Sending it as
    // `false` would also be wrong in the other direction — it would silently
    // unpublish a live page whenever someone edited a field.
    expect(body).toHaveProperty("headline", "Weaving since 1974");
    expect(body).not.toHaveProperty("is_published");
  });

  it("publishes only through the switch, and says what that means first", async () => {
    let body: Record<string, unknown> | null = null;
    server.use(
      http.put("*/settings/public-page", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({
          message: "ok",
          public_page: { ...emptyPage, is_published: true },
        });
      }),
    );

    renderScreen();

    // The consequence is spelled out before the switch is touched.
    expect(
      await screen.findByText(/visible to anyone on the internet/i),
    ).toBeInTheDocument();

    fireEvent.click(screen.getByRole("switch", { name: /publish this page/i }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toEqual({ is_published: true });
  });

  // ── Server-side validation is surfaced on the field ───────────────────────

  it("shows a rejected url against the field that caused it", async () => {
    server.use(
      http.put("*/settings/public-page", () =>
        HttpResponse.json(
          {
            type: "validation_error",
            title: "Validation Failed",
            status: 422,
            errors: {
              website_url: ["The website url must use http or https."],
            },
          },
          { status: 422 },
        ),
      ),
    );

    renderScreen();

    fireEvent.change(await screen.findByLabelText("Website"), {
      target: { value: "javascript:alert(1)" },
    });
    fireEvent.click(screen.getByRole("button", { name: /^save$/i }));

    // Not a toast: an error that is not tied to its input cannot be announced
    // to a screen reader as belonging to that input (WCAG 3.3.1).
    expect(
      await screen.findByText(/must use http or https/i),
    ).toBeInTheDocument();
  });

  // ── The legacy logo notice ────────────────────────────────────────────────

  it("explains why an externally linked logo will not appear publicly", async () => {
    mockPublicPage({ has_public_logo: false });

    renderScreen();

    // Without this, an administrator whose logo is a stored URL rather than an
    // uploaded file watches it fail to appear with no explanation anywhere.
    expect(
      await screen.findByText(
        /upload a logo file to show it on your public page/i,
      ),
    ).toBeInTheDocument();
  });

  it("does not show that notice once a real logo file is stored", async () => {
    mockPublicPage({ has_public_logo: true });

    renderScreen();

    await screen.findByLabelText("Headline");
    expect(
      screen.queryByText(/upload a logo file to show it/i),
    ).not.toBeInTheDocument();
  });

  // ── Amharic ───────────────────────────────────────────────────────────────

  it("renders an Amharic organisation name without dropping characters", async () => {
    mockPublicPage({ headline: "ከ1974 ጀምሮ ሽመና" });

    renderScreen();

    // Amharic is the default locale and renders longer than English for the
    // same meaning; a truncating container shows up here first.
    expect(await screen.findByLabelText("Headline")).toHaveValue(
      "ከ1974 ጀምሮ ሽመና",
    );
  });
});
