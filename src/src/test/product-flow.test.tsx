import { describe, it, expect, beforeEach, afterEach } from "vitest";
import { render, screen, within } from "@testing-library/react";
import { ProductFlow } from "@/components/marketing/product-flow";

/**
 * The hero illustration is animated, and its animation is suppressed entirely
 * for anyone with `prefers-reduced-motion` — `globals.css` collapses every
 * animation to 0.01ms. So the thing worth pinning is not the motion. It is that
 * the motion carries no meaning the text does not also carry, because for a
 * meaningful share of visitors the motion simply never plays.
 *
 * The Amharic case exists for a more specific reason. `DEFAULT_LOCALE` is "am"
 * and `am.json` is a static import, so the server prerenders this component in
 * Amharic — yet `src/test/setup.ts` registers only `en` and pins
 * `localStorage.locale = "en"`, and `e2e/ux-audit.spec.ts` pins `'en'` too. No
 * test in this repository had ever rendered a marketing surface in the language
 * the server actually emits, which is how a hardcoded English string here would
 * reach production unnoticed. This one does.
 */
describe("ProductFlow (hero illustration)", () => {
  afterEach(() => {
    localStorage.setItem("locale", "en");
  });

  describe("in English", () => {
    beforeEach(() => {
      localStorage.setItem("locale", "en");
    });

    it("states each step in text, not only in motion", () => {
      render(<ProductFlow />);

      // The three product beats. If these only existed as animation, a
      // reduced-motion visitor would get an empty box.
      expect(
        screen.getByRole("heading", { name: "Check in, anywhere" }),
      ).toBeInTheDocument();
      expect(
        screen.getByRole("heading", { name: "Keeps working offline" }),
      ).toBeInTheDocument();
      expect(
        screen.getByRole("heading", { name: "Payroll under Ethiopian law" }),
      ).toBeInTheDocument();

      // The offline behaviour is the differentiator and it animates away into a
      // "Synced" pill. It must survive in prose, since that pill ends hidden.
      expect(
        screen.getByText(/records queue on the device/i),
      ).toBeInTheDocument();
    });

    it("exposes the sequence as a three-item list, with the scaffolding hidden", () => {
      render(<ProductFlow />);

      const list = screen.getByRole("list");
      // Connectors and glyph cells are `aria-hidden`, so assistive tech sees
      // exactly the three steps — not the two connectors between them.
      expect(within(list).getAllByRole("listitem")).toHaveLength(3);
    });
  });

  describe("in Amharic (the locale the server renders by default)", () => {
    beforeEach(() => {
      localStorage.setItem("locale", "am");
    });

    it("renders every label in Amharic", () => {
      render(<ProductFlow />);

      expect(
        screen.getByRole("heading", { name: "የትም ቦታ ይግቡ" }),
      ).toBeInTheDocument();
      expect(
        screen.getByRole("heading", { name: "ከመስመር ውጭ መስራቱን ይቀጥላል" }),
      ).toBeInTheDocument();
      expect(
        screen.getByRole("heading", { name: "ደመወዝ በኢትዮጵያ ሕግ መሠረት" }),
      ).toBeInTheDocument();
    });

    it("reuses the payroll and attendance vocabulary rather than coining its own", () => {
      render(<ProductFlow />);

      // These come from `payroll.*` and `attendance.*` keys that the dashboard
      // already uses. Asserting them here is what stops the marketing surface
      // drifting into a second, inconsistent Amharic vocabulary.
      expect(screen.getByText("ጠቅላላ")).toBeInTheDocument(); // payroll.gross
      expect(screen.getByText("የገቢ ግብር")).toBeInTheDocument(); // income_tax
      expect(screen.getByText("ተጣሪ")).toBeInTheDocument(); // payroll.net
      expect(screen.getByText("ተመሳስሏል")).toBeInTheDocument(); // synced
    });
  });
});
