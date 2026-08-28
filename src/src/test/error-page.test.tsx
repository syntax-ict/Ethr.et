import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import ErrorPage from "@/app/error";

/**
 * The error page is localised, and the app's default locale is Amharic
 * (`DEFAULT_LOCALE = "am"`), whose dictionary is eagerly loaded. These
 * assertions therefore pin the locale to English first — without that they
 * were asserting English copy against an Amharic render, which is what the
 * hardcoded strings used to hide.
 */
beforeEach(() => {
  localStorage.setItem("locale", "en");
});

function renderErrorPage(digest?: string) {
  const error = Object.assign(new Error("Test error"), { digest }) as Error & {
    digest?: string;
  };
  const reset = vi.fn();
  render(<ErrorPage error={error} reset={reset} />);
  return { reset };
}

describe("Error page", () => {
  it("renders error message", () => {
    renderErrorPage();
    expect(screen.getByText("Something went wrong")).toBeInTheDocument();
  });

  it("shows error digest when available", () => {
    renderErrorPage("err_abc123");
    expect(screen.getByText(/err_abc123/)).toBeInTheDocument();
  });

  it("omits the reference line when there is no digest", () => {
    renderErrorPage();
    expect(screen.queryByText(/Reference/)).not.toBeInTheDocument();
  });

  it("calls reset on retry click", () => {
    const { reset } = renderErrorPage();
    fireEvent.click(screen.getByText("Try again"));
    expect(reset).toHaveBeenCalledOnce();
  });

  it("has a dashboard link", () => {
    renderErrorPage();
    const link = screen.getByText("Go to dashboard");
    expect(link.closest("a")).toHaveAttribute("href", "/dashboard");
  });

  it("renders in Amharic when that is the active locale", () => {
    localStorage.setItem("locale", "am");
    renderErrorPage();
    // Guards the i18n path itself: a regression to hardcoded English copy
    // would still pass every assertion above but fail here.
    expect(screen.getByText("የሆነ ችግር ተፈጥሯል")).toBeInTheDocument();
  });
});
