import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import type { ReactNode } from "react";
import { server } from "./msw/server";
import type { useAuthHostContext } from "@/lib/auth/use-auth-host-context";
import type { LandingNewsPage } from "@/features/auth/landing-feed";
import { NewsListing } from "@/features/news/components/news-listing";
import { NewsArticle } from "@/features/news/components/news-article";
import { paragraphs, segments } from "@/features/news/news-format";

/**
 * The organisation's public news pages (2026-10-16): one shared template for
 * every organisation, drawn from its own data. These pin what a reader sees
 * in each state, that every request names the right organisation, that the
 * filters reach the API, and that a story's text can never become markup.
 */
type HostCtx = ReturnType<typeof useAuthHostContext>;
const hostContextValue = vi.fn<() => HostCtx>();
vi.mock("@/lib/auth/use-auth-host-context", () => ({
  useAuthHostContext: () => hostContextValue(),
}));
vi.mock("next-themes", () => ({
  useTheme: () => ({ resolvedTheme: "light" }),
}));
vi.mock("next/navigation", () => ({ usePathname: () => "/news/A1" }));
vi.mock("@/lib/hooks/useHydrated", () => ({ useHydrated: () => true }));

function organisation(slug: string, name: string): HostCtx {
  return {
    context: "tenant",
    tenantSlug: slug,
    tenantName: name,
    tenantLogoPath: null,
    tenantBrand: null,
    tenantLanding: { background: "brand" },
    tenantSignIn: null,
    tenantDefaultLocale: null,
    onLanding: true,
    carryOrganisation: slug,
    tenantRequest: { mode: "entry", slug },
    tenantContextLoading: false,
    tenantLookupError: null,
    tenantFieldVisible: false,
  } as HostCtx;
}

const story = (
  id: string,
  title: string,
  extra: Partial<LandingNewsPage["data"][number]> = {},
) => ({
  public_id: id,
  title,
  excerpt: `${title} in short.`,
  category: null,
  featured: false,
  priority: "normal",
  published_at: "2026-10-12T08:00:00Z",
  ...extra,
});

function newsPage(
  data: ReturnType<typeof story>[],
  meta: Partial<LandingNewsPage["meta"]> = {},
  categories: string[] = [],
): LandingNewsPage {
  return {
    data,
    meta: {
      current_page: 1,
      last_page: 1,
      per_page: 9,
      total: data.length,
      ...meta,
    },
    categories,
  };
}

function renderWith(ui: ReactNode) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>{ui}</QueryClientProvider>,
  );
}

beforeEach(() => {
  window.history.replaceState(null, "", "/news?org=acme");
  hostContextValue.mockReturnValue(organisation("acme", "Acme Manufacturing"));
});
afterEach(() => vi.clearAllMocks());

describe("<NewsListing>", () => {
  it("asks the organisation in the address, leads with the featured story and links each one", async () => {
    let tenant: string | null = null;
    server.use(
      http.get("*/auth/tenant-landing/news", ({ request }) => {
        tenant = request.headers.get("X-Tenant");
        return HttpResponse.json(
          newsPage(
            [
              story("A1", "Open day", { featured: true, category: "event" }),
              story("A2", "Fees due"),
            ],
            {},
            ["event"],
          ),
        );
      }),
    );
    renderWith(<NewsListing />);

    const lead = await screen.findByRole("link", { name: /open day/i });
    expect(tenant).toBe("acme");
    expect(lead).toHaveAttribute("href", "/news/A1?org=acme");
    expect(screen.getByRole("link", { name: /fees due/i })).toHaveAttribute(
      "href",
      "/news/A2?org=acme",
    );
    expect(
      screen.getByRole("heading", { level: 1, name: "News" }),
    ).toBeInTheDocument();
    // The category filter offers what the organisation published under.
    expect(
      screen.getByRole("button", { name: "Event", pressed: false }),
    ).toBeInTheDocument();
  });

  it("sends the search and the category to the API and keeps them in the address", async () => {
    const asked: string[] = [];
    server.use(
      http.get("*/auth/tenant-landing/news", ({ request }) => {
        asked.push(new URL(request.url).search);
        return HttpResponse.json(
          newsPage(
            [story("A1", "Holiday schedule", { category: "notice" })],
            {},
            ["notice"],
          ),
        );
      }),
    );
    const user = userEvent.setup();
    renderWith(<NewsListing />);

    await screen.findByText("Holiday schedule");
    await user.type(
      screen.getByRole("searchbox", { name: /search news/i }),
      "holiday",
    );
    await user.click(screen.getByRole("button", { name: /^search$/i }));
    await user.click(screen.getByRole("button", { name: "Notice" }));

    await waitFor(() =>
      expect(asked.at(-1)).toBe("?q=holiday&category=notice"),
    );
    expect(window.location.search).toContain("q=holiday");
    expect(window.location.search).toContain("category=notice");
    expect(window.location.search).toContain("org=acme");
  });

  it("pages through the news", async () => {
    const asked: string[] = [];
    server.use(
      http.get("*/auth/tenant-landing/news", ({ request }) => {
        asked.push(new URL(request.url).search);
        return HttpResponse.json(
          newsPage([story("A1", "Story one")], { last_page: 3, total: 20 }),
        );
      }),
    );
    const user = userEvent.setup();
    renderWith(<NewsListing />);

    expect(await screen.findByText("Page 1 of 3")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /previous/i })).toBeDisabled();
    await user.click(screen.getByRole("button", { name: /next/i }));

    await waitFor(() => expect(asked.at(-1)).toBe("?page=2"));
  });

  it("says when there is no news yet, and when nothing matches", async () => {
    server.use(
      http.get("*/auth/tenant-landing/news", () =>
        HttpResponse.json(newsPage([])),
      ),
    );
    renderWith(<NewsListing />);
    expect(await screen.findByText("No news yet")).toBeInTheDocument();
  });

  it("says the organisation publishes no news here, with the way home", async () => {
    server.use(
      http.get(
        "*/auth/tenant-landing/news",
        () => new HttpResponse(null, { status: 404 }),
      ),
    );
    renderWith(<NewsListing />);

    expect(await screen.findByText("No news here")).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: /back to the organisation's page/i }),
    ).toHaveAttribute("href", "/acme");
  });

  it("offers to try again when the news could not be loaded", async () => {
    let calls = 0;
    server.use(
      http.get("*/auth/tenant-landing/news", () => {
        calls += 1;
        return calls === 1
          ? new HttpResponse(null, { status: 500 })
          : HttpResponse.json(newsPage([story("A1", "Back again")]));
      }),
    );
    const user = userEvent.setup();
    renderWith(<NewsListing />);

    await user.click(await screen.findByRole("button", { name: /try again/i }));
    expect(await screen.findByText("Back again")).toBeInTheDocument();
  });

  it("draws a second organisation's news from the same page, with its own name and links", async () => {
    hostContextValue.mockReturnValue(organisation("habru", "Habru Coffee"));
    window.history.replaceState(null, "", "/news?org=habru");
    server.use(
      http.get("*/auth/tenant-landing/news", ({ request }) =>
        HttpResponse.json(
          request.headers.get("X-Tenant") === "habru"
            ? newsPage([story("H1", "Harvest begins")])
            : newsPage([]),
        ),
      ),
    );
    renderWith(<NewsListing />);

    expect(
      await screen.findByRole("link", { name: /harvest begins/i }),
    ).toHaveAttribute("href", "/news/H1?org=habru");
    expect(
      screen.getByText("News and announcements from Habru Coffee."),
    ).toBeInTheDocument();
  });
});

describe("<NewsArticle>", () => {
  it("reads the story with its paragraphs and live https links, and lists related news", async () => {
    server.use(
      http.get("*/auth/tenant-landing/announcements/A1", () =>
        HttpResponse.json({
          ...story("A1", "Open day", { category: "event" }),
          body: "Come and see the plant.\n\nDetails at https://acme.example/open-day. <b>Not bold</b>",
          related: [story("A2", "Fees due")],
        }),
      ),
    );
    renderWith(<NewsArticle routeId="__id__" />);

    expect(
      await screen.findByRole("heading", { level: 1, name: "Open day" }),
    ).toBeInTheDocument();
    const link = screen.getByRole("link", { name: /acme\.example\/open-day/ });
    expect(link).toHaveAttribute("href", "https://acme.example/open-day");
    expect(link).toHaveAttribute("rel", "noopener noreferrer nofollow ugc");
    // Markup in a story is text.
    expect(screen.getByText(/<b>Not bold<\/b>/)).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /fees due/i })).toHaveAttribute(
      "href",
      "/news/A2?org=acme",
    );
    expect(screen.getByRole("link", { name: /back to news/i })).toHaveAttribute(
      "href",
      "/news?org=acme",
    );
  });

  it("says a story that is not public is not available", async () => {
    server.use(
      http.get(
        "*/auth/tenant-landing/announcements/A1",
        () => new HttpResponse(null, { status: 404 }),
      ),
    );
    renderWith(<NewsArticle routeId="__id__" />);

    expect(
      await screen.findByText("This story is not available"),
    ).toBeInTheDocument();
  });
});

describe("story text", () => {
  it("splits paragraphs on blank lines", () => {
    expect(paragraphs("One.\r\n\r\nTwo\nlines.\n\n\nThree.")).toEqual([
      "One.",
      "Two\nlines.",
      "Three.",
    ]);
  });

  it("finds https links and leaves trailing punctuation and plain http outside them", () => {
    expect(segments("See https://a.example/x. Or http://b.example.")).toEqual([
      { kind: "text", text: "See " },
      { kind: "link", href: "https://a.example/x" },
      { kind: "text", text: ". Or http://b.example." },
    ]);
  });
});
