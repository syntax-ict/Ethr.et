import { describe, expect, it, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
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

function footer(): HTMLElement {
  const aside = screen.getByRole("complementary", { name: /main navigation/i });
  return aside.lastElementChild as HTMLElement;
}

describe("sidebar footer", () => {
  it("signs the column with Powered by ETHR, linking to the public site", () => {
    renderSidebar();
    const mark = within(footer()).getByRole("link", {
      name: /powered by ethr/i,
    });
    expect(mark).toHaveAttribute("href", SITE_URL);
    expect(mark).toHaveAttribute("target", "_blank");
    expect(mark).toHaveAttribute("rel", expect.stringContaining("noopener"));
  });

  it("shows no keyboard chips and no palette trigger", () => {
    renderSidebar();
    const foot = footer();
    expect(foot.querySelector("kbd")).toBeNull();
    expect(foot.textContent).not.toMatch(/ctrl|⌘|command palette/i);
    expect(
      within(foot).queryByRole("button", { name: /palette|quick actions/i }),
    ).toBeNull();
  });

  it("collapses to a rail that keeps the mark and names the expand control", async () => {
    const user = userEvent.setup();
    renderSidebar();
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
});
