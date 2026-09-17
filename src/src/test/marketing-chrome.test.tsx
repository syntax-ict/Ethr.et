import { describe, it, expect } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { TooltipProvider } from "@/components/ui/tooltip";
import { MarketingHeader } from "@/components/layouts/marketing-header";
import { MarketingFooter } from "@/components/layouts/marketing-footer";
import { RouteLocaleProvider } from "@/lib/i18n/route-locale";

/**
 * The header and footer every public page carries.
 *
 * Both used to make claims they could not keep: the header offered six
 * languages when two have dictionaries, and the footer advertised a Company
 * section whose three links pointed at "#". Those are the same defect as the
 * landing page's invented metrics — something stated on a public page that
 * is not true — in control and link form.
 */
function renderChrome(ui: React.ReactElement, locale?: string) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  const tree = locale ? (
    <RouteLocaleProvider locale={locale}>{ui}</RouteLocaleProvider>
  ) : (
    ui
  );

  return render(
    <QueryClientProvider client={queryClient}>
      <TooltipProvider>{tree}</TooltipProvider>
    </QueryClientProvider>,
  );
}

/** Every in-site link the chrome renders, by href. */
function hrefs() {
  return screen
    .getAllByRole("link")
    .map((a) => a.getAttribute("href") ?? "")
    .filter((href) => href.startsWith("/"));
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

describe("Marketing chrome inside a locale-prefixed route", () => {
  it("keeps every nav link in the language the reader is already in", () => {
    renderChrome(<MarketingHeader />, "en");

    // An unprefixed /pricing would send an English reader to the negotiating
    // redirector, which resolves from a cookie they may never have set — so a
    // single click could silently change the language.
    expect(hrefs()).toEqual(
      expect.arrayContaining([
        "/en",
        "/en/features",
        "/en/pricing",
        "/en/faq",
        "/en/contact",
      ]),
    );
  });

  it("leaves the auth links unprefixed", () => {
    renderChrome(<MarketingHeader />, "am");

    // /login and /register are not locale-prefixed routes; prefixing them would
    // produce a 404 at the exact moment of conversion.
    const links = hrefs();
    expect(links).toContain("/login");
    expect(links).toContain("/register");
    expect(links).not.toContain("/am/login");
  });

  it("prefixes the footer's product and legal columns too", () => {
    renderChrome(<MarketingFooter />, "am");

    expect(hrefs()).toEqual(
      expect.arrayContaining([
        "/am",
        "/am/features",
        "/am/pricing",
        "/am/privacy",
        "/am/terms",
      ]),
    );
  });

  it("labels the footer's first product link Features, not Product", () => {
    renderChrome(<MarketingFooter />, "en");

    // It shared `marketing.nav.product` with the header, whose en.json value is
    // "Product" — so the link sat directly under a heading of the same name,
    // and the fallback string hid it until the dictionary loaded.
    const products = screen.getAllByRole("link", { name: "Features" });
    expect(products[0]).toHaveAttribute("href", "/en/features");
  });

  it("leaves links alone outside the locale-prefixed tree", () => {
    // The same header renders on hosts and routes that have no language in the
    // URL; there the redirector is the right destination.
    renderChrome(<MarketingHeader />);
    expect(hrefs()).toEqual(expect.arrayContaining(["/", "/pricing"]));
  });
});
