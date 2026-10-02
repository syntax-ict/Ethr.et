import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { renderToStaticMarkup } from "react-dom/server";
import GlobalError from "@/app/global-error";
import am from "@/lib/i18n/locales/am.json";
import en from "@/lib/i18n/locales/en.json";

/**
 * The last-resort error screen was English only (audit N38). It replaces the
 * root layout, so it cannot read the page's locale, and it deliberately loads
 * no i18n module. Its text is therefore static and bilingual — and copied from
 * existing translations, which these tests pin it to, so a change to either
 * side is noticed rather than silently diverging.
 */
describe("<GlobalError>", () => {
  const error = Object.assign(new Error("boom"), { digest: "abc123" });

  // It renders its own <html>/<body>, which cannot mount inside a test
  // container; render the markup for content and the button for behaviour.
  const markup = () =>
    renderToStaticMarkup(<GlobalError error={error} reset={() => {}} />);

  it("says it in English and Amharic, using the app's own translations", () => {
    const html = markup();
    expect(html).toContain(en["error.title"]);
    expect(html).toContain(am["error.title"]);
    expect(html).toContain(en["common.try_again"]);
    expect(html).toContain(am["common.try_again"]);
  });

  it("marks the Amharic text as Amharic", () => {
    const html = markup();
    expect(html).toContain(`<p lang="am"`);
    expect(html).toContain(`<span lang="am">${am["common.try_again"]}</span>`);
  });

  it("still shows the error id and retries on click", () => {
    expect(markup()).toContain("abc123");

    const reset = vi.fn();
    vi.spyOn(console, "error").mockImplementation(() => {});
    render(<GlobalError error={error} reset={reset} />);
    screen.getByRole("button").click();
    expect(reset).toHaveBeenCalledOnce();
  });
});
