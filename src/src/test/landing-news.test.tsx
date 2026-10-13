import { describe, it, expect } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { LandingNews } from "@/features/auth/components/landing-news";
import type { LandingFeed } from "@/features/auth/landing-feed";

/**
 * The news under an organisation's sign-in form. The server decides what is
 * public; the page must ask the right organisation (X-Tenant on the shared
 * landing), render text as text, and render nothing when there is nothing.
 */
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
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <LandingNews request={{ mode: "entry", slug: "acme" }} />
    </QueryClientProvider>,
  );
}

describe("<LandingNews>", () => {
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

  it("renders nothing when the organisation published nothing", async () => {
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

    const { container } = renderNews();
    await new Promise((r) => setTimeout(r, 50));

    expect(asked).toBe(true);
    expect(container).toBeEmptyDOMElement();
  });
});
