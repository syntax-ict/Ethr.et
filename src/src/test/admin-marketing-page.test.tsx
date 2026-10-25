import { describe, expect, it, vi } from "vitest";
import {
  fireEvent,
  render,
  screen,
  waitFor,
  within,
} from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import AdminMarketingPage from "@/app/(dashboard)/admin/marketing/page";
import { marketingFixture } from "./fixtures/marketing-content";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const SPEC: Record<
  string,
  {
    fields: string[];
    max_items: number;
    images: boolean;
    section_image: boolean;
  }
> = {
  hero: { fields: [], max_items: 0, images: false, section_image: true },
  problems: {
    fields: ["icon", "title", "body", "detail", "link_path"],
    max_items: 6,
    images: true,
    section_image: false,
  },
  capabilities: {
    fields: ["icon", "title", "body", "detail", "link_path"],
    max_items: 5,
    images: true,
    section_image: false,
  },
  how_it_works: {
    fields: ["title", "body"],
    max_items: 4,
    images: false,
    section_image: false,
  },
  industries: {
    fields: ["icon", "title"],
    max_items: 12,
    images: false,
    section_image: false,
  },
  trust: {
    fields: ["icon", "title", "body"],
    max_items: 6,
    images: false,
    section_image: false,
  },
  cta: { fields: ["title"], max_items: 3, images: false, section_image: false },
};

/** The admin payload: the public fixture plus the flags the console edits. */
function adminContent() {
  const sections = marketingFixture().sections.map((section, i) => ({
    ...section,
    public_id: `SEC${i}`,
    sort_order: i + 1,
    is_published: true,
    spec: SPEC[section.kind],
    items: section.items.map((item, j) => ({
      ...item,
      sort_order: j + 1,
      is_published: true,
    })),
  }));
  return { sections, icons: ["clock", "wallet", "users"] };
}

function serve(options: { rejectSection?: boolean } = {}) {
  const sent: { method: string; url: string; body: unknown }[] = [];
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "U1", name: "Platform", role: "super_admin" },
        tenant: null,
        permissions: [],
      }),
    ),
    http.get("*/api/v1/admin/marketing", () =>
      HttpResponse.json({ data: adminContent() }),
    ),
    http.put("*/api/v1/admin/marketing/sections/:kind", async ({ request }) => {
      const body = (await request.json()) as Record<string, unknown>;
      sent.push({ method: "PUT", url: request.url, body });
      if (options.rejectSection) {
        return HttpResponse.json(
          {
            type: "validation_error",
            title: "Validation Failed",
            status: 422,
            detail: "The given data was invalid.",
            errors: {
              heading: [
                "This text makes a claim the public site may not make.",
              ],
            },
          },
          { status: 422 },
        );
      }
      return HttpResponse.json({
        data: { ...adminContent().sections[1], ...body },
      });
    }),
    http.put(
      "*/api/v1/admin/marketing/sections/:kind/order",
      async ({ request }) => {
        const body = (await request.json()) as Record<string, unknown>;
        sent.push({ method: "PUT", url: request.url, body });
        return HttpResponse.json({ data: adminContent().sections[3] });
      },
    ),
    http.put("*/api/v1/admin/marketing/items/:id", async ({ request }) => {
      const body = (await request.json()) as Record<string, unknown>;
      sent.push({ method: "PUT", url: request.url, body });
      return HttpResponse.json({
        data: { ...adminContent().sections[1].items[0], ...body },
      });
    }),
  );
  return sent;
}

/**
 * The control a label names, found through the label's `for`: testing-library's
 * `getByLabelText` took 49 seconds over this page of thirty editors in jsdom,
 * because it computes every candidate's accessible name.
 */
function field(scope: HTMLElement, label: string): HTMLElement | null {
  const el = within(scope).queryByText(label, { selector: "label" });
  if (!el) return null;
  const id = (el as HTMLLabelElement).htmlFor;
  return id ? (scope.querySelector(`#${CSS.escape(id)}`) as HTMLElement) : null;
}

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <AdminMarketingPage />
    </QueryClientProvider>,
  );
}

describe("the landing page editor", () => {
  it("lists every section with its rows, and a link to the live page", async () => {
    serve();
    renderPage();

    expect(await screen.findByText("Opening")).toBeInTheDocument();
    expect(screen.getByText("Problems and what ETHR does")).toBeInTheDocument();
    expect(screen.getByText("6 of 6 rows")).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: /view live page/i }),
    ).toHaveAttribute("href", "/en");
  });

  it("offers each section only the fields its kind uses", async () => {
    serve();
    renderPage();
    await screen.findByText("Opening");

    // The hero holds no rows, so there is nothing to add and no row list.
    const hero = screen.getByTestId("marketing-section-hero");
    expect(within(hero).queryByText("Add row")).toBeNull();
    expect(within(hero).getByText("Opening picture")).toBeInTheDocument();

    // Industries rows have a title and an icon, nothing to write under them.
    const industries = screen.getByTestId("marketing-section-industries");
    const government = within(industries)
      .getByText(/Government/)
      .closest("li") as HTMLElement;
    expect(field(government, "Text")).toBeNull();
    expect(field(government, "Icon")).toBeInTheDocument();

    // A problem row has text, detail, a link and a picture.
    const paper = screen
      .getByText(/Attendance on paper/)
      .closest("li") as HTMLElement;
    expect(field(paper, "Text")).toBeInTheDocument();
    expect(field(paper, "Detail")).toBeInTheDocument();
    expect(field(paper, "Link")).toBeInTheDocument();
    expect(within(paper).getByText("Picture for this row")).toBeInTheDocument();
  });

  it("saves a section's heading and visibility", async () => {
    const sent = serve();
    renderPage();
    await screen.findByText("Opening");

    const problems = screen.getByTestId("marketing-section-problems");
    const heading = field(problems, "Heading")!;
    fireEvent.change(heading, { target: { value: "What goes wrong today" } });
    fireEvent.click(within(problems).getByText("Save section"));

    const saves = () =>
      sent.filter((s) => s.url.includes("/sections/problems"));
    await waitFor(() => expect(saves()).toHaveLength(1));
    expect(saves()[0].body).toMatchObject({
      heading: "What goes wrong today",
      is_published: true,
    });
  });

  it("shows a refused claim beside its field", async () => {
    serve({ rejectSection: true });
    renderPage();
    await screen.findByText("Opening");

    const trust = screen.getByTestId("marketing-section-trust");
    fireEvent.click(within(trust).getByText("Save section"));

    expect(await within(trust).findByRole("alert")).toHaveTextContent(
      "This text makes a claim the public site may not make.",
    );
  });

  it("moves a row down by sending the whole new order", async () => {
    const sent = serve();
    renderPage();
    await screen.findByText("Opening");

    const register = screen
      .getByText(/Create your account/)
      .closest("li") as HTMLElement;
    fireEvent.click(register.querySelector('button[aria-label="Move down"]')!);

    const orders = () => sent.filter((s) => s.url.includes("/order"));
    await waitFor(() => expect(orders()).toHaveLength(1));
    expect(orders()[0].url).toContain(
      "/admin/marketing/sections/how_it_works/order",
    );
    const order = (orders()[0].body as { order: string[] }).order;
    expect(order).toHaveLength(3);
    // register and configure swapped; go_live stays last.
    const items = adminContent().sections[3].items;
    expect(order).toEqual([
      items[1].public_id,
      items[0].public_id,
      items[2].public_id,
    ]);
  });

  it("saves a row with only the fields its kind uses", async () => {
    const sent = serve();
    renderPage();
    await screen.findByText("Opening");

    const government = screen
      .getByText(/Government/)
      .closest("li") as HTMLElement;
    fireEvent.click(within(government).getByText("Save"));

    const saves = () => sent.filter((s) => s.url.includes("/items/"));
    await waitFor(() => expect(saves()).toHaveLength(1));
    expect(Object.keys(saves()[0].body as object)).toEqual(
      expect.arrayContaining(["title", "title_am", "icon", "is_published"]),
    );
    expect(saves()[0].body).not.toHaveProperty("body");
    expect(saves()[0].body).not.toHaveProperty("detail");
    expect(saves()[0].body).not.toHaveProperty("link_path");
  });
});
