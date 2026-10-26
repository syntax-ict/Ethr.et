import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import {
  EMPTY_SITE_CONTENT,
  type SiteContent,
} from "@/features/marketing/site-content";
import { TooltipProvider } from "@/components/ui/tooltip";
import { AppLayoutProvider } from "@/components/layouts/layout-context";
import { AppSidebar } from "@/components/layouts/app-sidebar";
import { SITE_URL } from "@/lib/site-url";

vi.mock("next/navigation", () => ({
  usePathname: () => "/dashboard",
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), prefetch: vi.fn() }),
}));

/**
 * The sidebar footer (2026-10-25) is the product mark and one icon. It used
 * to be two labelled rows with keyboard chips, "Command Palette Ctrl+K" and
 * "Collapse Ctrl+B", which read as developer furniture at the foot of an HR
 * tool. The shortcuts still work; the shortcuts sheet lists them.
 */
/**
 * The mark is the platform's: whether it shows and what it says arrive on the
 * public site-content payload, set in the platform console. The default
 * payload is the column defaults, which is the mark on with its own wording.
 */
function serveSiteContent(overrides: Partial<SiteContent> = {}) {
  server.use(
    http.get("*/api/v1/site-content", () =>
      HttpResponse.json({ data: { ...EMPTY_SITE_CONTENT, ...overrides } }),
    ),
  );
}

function renderSidebar() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <TooltipProvider>
        <AppLayoutProvider>
          <AppSidebar />
        </AppLayoutProvider>
      </TooltipProvider>
    </QueryClientProvider>,
  );
}

// layout-context.tsx's key; not exported, and one string is not worth widening
// that module's surface for.
const SIDEBAR_STORAGE_KEY = "ethr.sidebar.collapsed";

// Located by element rather than by its English name, so the Amharic case
// below can use it too.
function footer(): HTMLElement {
  const aside = screen.getByRole("complementary");
  return aside.lastElementChild as HTMLElement;
}

describe("sidebar footer", () => {
  beforeEach(() => {
    // The collapse test persists the rail, which every later test would
    // otherwise start on.
    localStorage.removeItem(SIDEBAR_STORAGE_KEY);
  });

  it("signs the column with Powered by ETHR, linking to the public site", async () => {
    serveSiteContent();
    renderSidebar();
    const mark = await within(footer()).findByRole("link", {
      name: /powered by ethr/i,
    });
    expect(mark).toHaveAttribute("href", SITE_URL);
    expect(mark).toHaveAttribute("target", "_blank");
    expect(mark).toHaveAttribute("rel", expect.stringContaining("noopener"));
  });

  it("shows no keyboard chips and no palette trigger", async () => {
    serveSiteContent();
    renderSidebar();
    await within(footer()).findByRole("link", { name: /powered by ethr/i });
    const foot = footer();
    expect(foot.querySelector("kbd")).toBeNull();
    expect(foot.textContent).not.toMatch(/ctrl|⌘|command palette/i);
    expect(
      within(foot).queryByRole("button", { name: /palette|quick actions/i }),
    ).toBeNull();
  });

  it("collapses to a rail that keeps the mark and names the expand control", async () => {
    const user = userEvent.setup();
    serveSiteContent();
    renderSidebar();
    await within(footer()).findByRole("link", { name: /powered by ethr/i });
    await user.click(
      within(footer()).getByRole("button", { name: /^collapse sidebar$/i }),
    );
    const rail = footer();
    expect(
      within(rail).getByRole("link", { name: /powered by ethr/i }),
    ).toBeInTheDocument();
    expect(
      within(rail).getByRole("button", { name: /^expand sidebar$/i }),
    ).toBeInTheDocument();
    expect(rail.querySelector("kbd")).toBeNull();
  });

  it("carries the ETHR logo tile, not a generic icon", async () => {
    serveSiteContent();
    renderSidebar();
    const mark = await within(footer()).findByRole("link", {
      name: /powered by ethr/i,
    });
    expect(mark.textContent).toContain("E");
    expect(mark.querySelector("svg")).toBeNull();
  });

  it("uses the platform logo the public site shows, when one is set", async () => {
    serveSiteContent({ logo_url: "https://cdn.ethr.et/logo.png" });
    renderSidebar();
    const mark = await within(footer()).findByRole("link", {
      name: /powered by ethr/i,
    });
    await waitFor(() =>
      expect(mark.querySelector("img")).toHaveAttribute(
        "src",
        "https://cdn.ethr.et/logo.png",
      ),
    );
  });

  it("disappears when the platform admin turns it off, leaving the toggle", async () => {
    let served = false;
    server.use(
      http.get("*/api/v1/site-content", () => {
        served = true;
        return HttpResponse.json({
          data: { ...EMPTY_SITE_CONTENT, product_mark_enabled: false },
        });
      }),
    );
    renderSidebar();
    await waitFor(() => expect(served).toBe(true));
    // Give the query a turn to settle before asserting absence.
    await within(footer()).findByRole("button", {
      name: /^collapse sidebar$/i,
    });
    await waitFor(() =>
      expect(screen.queryByTestId("product-mark")).toBeNull(),
    );
    expect(
      within(footer()).queryByRole("link", { name: /powered by/i }),
    ).toBeNull();
  });

  it("is not drawn before the platform's answer arrives", () => {
    // Drawing the default first would flash the mark into every page of a
    // platform whose operator turned it off.
    server.use(http.get("*/api/v1/site-content", () => new Promise(() => {})));
    renderSidebar();
    expect(screen.queryByTestId("product-mark")).toBeNull();
    expect(
      within(footer()).getByRole("button", { name: /^collapse sidebar$/i }),
    ).toBeInTheDocument();
  });

  it("falls back to the mark when the platform's answer fails", async () => {
    server.use(
      http.get("*/api/v1/site-content", () =>
        HttpResponse.json({ message: "down" }, { status: 503 }),
      ),
    );
    renderSidebar();
    expect(
      await within(footer()).findByRole("link", { name: /powered by ethr/i }),
    ).toHaveAttribute("href", SITE_URL);
  });

  it("says and links what the platform admin set, on the rail too", async () => {
    const user = userEvent.setup();
    serveSiteContent({
      product_mark_label: "Built on ETHR",
      product_mark_url: "https://www.ethr.et/features",
    });
    renderSidebar();
    const mark = await within(footer()).findByRole("link", {
      name: "Built on ETHR",
    });
    expect(mark).toHaveAttribute("href", "https://www.ethr.et/features");

    await user.click(
      within(footer()).getByRole("button", { name: /^collapse sidebar$/i }),
    );
    expect(
      within(footer()).getByRole("link", { name: "Built on ETHR" }),
    ).toHaveAttribute("href", "https://www.ethr.et/features");
  });

  it("reads the Amharic wording to an Amharic reader", async () => {
    localStorage.setItem("locale", "am");
    serveSiteContent({
      product_mark_label: "Built on ETHR",
      product_mark_label_am: "በETHR የተሰራ",
    });
    renderSidebar();
    expect(
      await within(footer()).findByRole("link", { name: "በETHR የተሰራ" }),
    ).toBeInTheDocument();
  });
});
