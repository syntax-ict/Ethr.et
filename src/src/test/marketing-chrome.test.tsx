import { describe, it, expect } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { TooltipProvider } from "@/components/ui/tooltip";
import { MarketingHeader } from "@/components/layouts/marketing-header";
import { MarketingFooter } from "@/components/layouts/marketing-footer";

/**
 * The header and footer every public page carries.
 *
 * Both used to make claims they could not keep: the header offered six
 * languages when two have dictionaries, and the footer advertised a Company
 * section whose three links pointed at "#". Those are the same defect as the
 * landing page's invented metrics — something stated on a public page that
 * is not true — in control and link form.
 */
function renderChrome(ui: React.ReactElement) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={queryClient}>
      <TooltipProvider>{ui}</TooltipProvider>
    </QueryClientProvider>,
  );
}

describe("Marketing header", () => {
  it("offers only the languages that are actually translated", async () => {
    renderChrome(<MarketingHeader />);

    // The header had its own copy of the locale list and mapped every entry to
    // a live setLocale button — so choosing Oromo, Tigrinya, Somali or Sidama
    // swapped the dictionary for an empty one and rendered the entire page as
    // raw translation keys. It now uses the shared LanguageSwitcher, which
    // disables those and says why.
    await userEvent.click(
      screen.getAllByRole("button", { name: /change language/i })[0],
    );

    const menu = await screen.findByRole("menu");
    const enabled = within(menu)
      .getAllByRole("menuitem")
      .filter((item) => item.getAttribute("aria-disabled") !== "true");

    expect(enabled.map((e) => e.textContent)).toEqual(
      expect.arrayContaining([
        expect.stringContaining("English"),
        expect.stringContaining("አማርኛ"),
      ]),
    );
    expect(enabled).toHaveLength(2);
  });

  it("tells assistive technology whether the mobile menu is open", async () => {
    renderChrome(<MarketingHeader />);

    // Both aria-expanded and aria-controls were missing, so the button
    // announced nothing about the panel it owns and gave no way to know it was
    // already open — the Menu/X icon swap is a purely visual signal. The label
    // also read "Open menu" while the menu was open.
    const toggle = screen.getByRole("button", { name: /open menu/i });
    expect(toggle).toHaveAttribute("aria-expanded", "false");
    expect(toggle).toHaveAttribute("aria-controls");

    await userEvent.click(toggle);

    const opened = screen.getByRole("button", { name: /close menu/i });
    expect(opened).toHaveAttribute("aria-expanded", "true");
    expect(
      document.getElementById(opened.getAttribute("aria-controls") ?? ""),
    ).not.toBeNull();
  });
});

describe("Marketing footer", () => {
  it("links nowhere it cannot actually go", () => {
    renderChrome(<MarketingFooter />);

    // About, Blog and Careers pointed at "#" under a "Company" heading, which
    // tells a visitor those pages exist. None of them do. A dead link is not a
    // placeholder — it is a claim, and this is the assertion that stops one
    // coming back.
    const dead = screen
      .getAllByRole("link")
      .filter((a) => (a.getAttribute("href") ?? "").startsWith("#"));

    expect(dead).toHaveLength(0);
    expect(screen.queryByText("Company")).not.toBeInTheDocument();
  });

  it("shows no social links until an operator has given a URL", () => {
    renderChrome(<MarketingFooter />);

    // site-content starts empty, and an icon linking to nothing is the same
    // defect one layer prettier.
    expect(screen.queryByText("LinkedIn")).not.toBeInTheDocument();
    expect(screen.queryByText("Facebook")).not.toBeInTheDocument();
  });
});
