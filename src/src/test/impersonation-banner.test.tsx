import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { registerLocale } from "@/lib/i18n/translations";
import amTranslations from "@/lib/i18n/locales/am.json";
import { ImpersonationBanner } from "@/components/shared/impersonation-banner";

/**
 * The impersonation banner is the only thing on screen telling a super admin
 * that they are acting as somebody else. If it does not render, every action
 * they take looks — to them — like their own. It was at zero coverage.
 *
 * The exit path matters just as much as the banner. It has to put the admin's
 * own tenant back before navigating, and it has to prefer the server's answer
 * to whatever this browser stashed on the way in, because the stash is the part
 * an attacker or a stale tab could have got wrong.
 */
const originalLocation = window.location;

beforeEach(() => {
  localStorage.clear();
  // `DEFAULT_LOCALE` is "am" — Amharic is the product default, not the
  // fallback. Clearing localStorage drops the "en" that setup.ts sets, so
  // without this the English assertions below would be read in Amharic.
  localStorage.setItem("locale", "en");
  // jsdom refuses a real navigation, so `location` is replaced with a plain
  // object for the duration — otherwise the assignment logs "Not implemented"
  // and the destination, which is the interesting part, cannot be read back.
  //
  // It has to carry a real origin, not just `href: ""`. `apiClient`'s baseURL is
  // the relative "/api/v1", and resolving that needs a base URL — a stub
  // without one made every request fail, which looked exactly like the server
  // rejecting the exit.
  Object.defineProperty(window, "location", {
    configurable: true,
    writable: true,
    value: {
      href: "http://localhost:3000/",
      origin: "http://localhost:3000",
      protocol: "http:",
      host: "localhost:3000",
      hostname: "localhost",
      port: "3000",
      pathname: "/",
      search: "",
      hash: "",
      assign: vi.fn(),
      replace: vi.fn(),
    },
  });
});

afterEach(() => {
  Object.defineProperty(window, "location", {
    configurable: true,
    writable: true,
    value: originalLocation,
  });
});

describe("<ImpersonationBanner>", () => {
  it("stays out of the way when nobody is impersonating", () => {
    const { container } = render(<ImpersonationBanner />);

    expect(container).toBeEmptyDOMElement();
  });

  it("warns, in the reader's language, that actions are logged", () => {
    // Asserting the English would prove nothing — it reads the same whether the
    // string is translated or hardcoded. Amharic is where a hardcoded literal
    // cannot pass, and a warning nobody can read is not a warning.
    registerLocale("am", amTranslations);
    localStorage.setItem("locale", "am");
    localStorage.setItem("impersonating", "true");

    render(<ImpersonationBanner />);

    expect(
      screen.getByText(amTranslations["impersonation.banner"]),
    ).toBeInTheDocument();
  });

  it("restores the tenant the server names, not the one this browser stashed", async () => {
    // The stash is written by the browser on the way in and can be stale or
    // tampered with; the server knows which session it actually restored.
    localStorage.setItem("impersonating", "true");
    localStorage.setItem("original_tenant", "stale-stash");

    server.use(
      http.post("*/admin/exit-impersonation", () =>
        HttpResponse.json({ session_restored: true, tenant: "acme-platform" }),
      ),
    );

    render(<ImpersonationBanner />);
    fireEvent.click(
      screen.getByRole("button", { name: /Exit Impersonation/i }),
    );

    await waitFor(() =>
      expect(localStorage.getItem("tenant")).toBe("acme-platform"),
    );

    // Both impersonation markers must go, or the next page load still believes
    // it is impersonating.
    expect(localStorage.getItem("impersonating")).toBeNull();
    expect(localStorage.getItem("original_tenant")).toBeNull();
    expect(window.location.href).toBe("/admin");
  });

  it("falls back to the stashed tenant when the server does not name one", async () => {
    localStorage.setItem("impersonating", "true");
    localStorage.setItem("original_tenant", "fallback-tenant");

    server.use(
      http.post("*/admin/exit-impersonation", () =>
        HttpResponse.json({ session_restored: true }),
      ),
    );

    render(<ImpersonationBanner />);
    fireEvent.click(
      screen.getByRole("button", { name: /Exit Impersonation/i }),
    );

    await waitFor(() =>
      expect(localStorage.getItem("tenant")).toBe("fallback-tenant"),
    );
    expect(window.location.href).toBe("/admin");
  });

  it("sends the admin to login when there is no identity left to restore", async () => {
    // An expired session, or an account that is no longer a super admin. There
    // is nothing to go back to, and leaving them on an impersonated session
    // would be the one genuinely unsafe outcome.
    localStorage.setItem("impersonating", "true");

    server.use(
      http.post(
        "*/admin/exit-impersonation",
        () => new HttpResponse(null, { status: 401 }),
      ),
      http.post(
        "*/auth/refresh",
        () => new HttpResponse(null, { status: 401 }),
      ),
    );

    render(<ImpersonationBanner />);
    fireEvent.click(
      screen.getByRole("button", { name: /Exit Impersonation/i }),
    );

    await waitFor(() => expect(window.location.href).toBe("/login"));

    // The flag is cleared even on failure: a banner that cannot be dismissed on
    // a session that cannot be exited leaves the admin stuck.
    expect(localStorage.getItem("impersonating")).toBeNull();
  });
});
