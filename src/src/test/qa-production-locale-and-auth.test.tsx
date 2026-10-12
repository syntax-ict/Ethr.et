import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, fireEvent, waitFor } from "@testing-library/react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { apiClient } from "@/api/client";
import { RouteLocaleProvider } from "@/lib/i18n/route-locale";
import { DEFAULT_LOCALE } from "@/lib/i18n/config";
import RegisterPage from "@/app/(auth)/register/page";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), back: vi.fn(), replace: vi.fn() }),
}));

/** Production QA 2026-10-11 — the language and session follow-ups. */

beforeEach(() => {
  localStorage.clear();
});

describe("P8: the marketing site's language carries into the app", () => {
  it("remembers the language of the /en page being read", () => {
    render(
      <RouteLocaleProvider locale="en">
        <p>Start Free Trial</p>
      </RouteLocaleProvider>,
    );

    expect(localStorage.getItem("locale")).toBe("en");
  });

  it("ignores a route locale the app cannot render", () => {
    render(
      <RouteLocaleProvider locale="xx">
        <p>…</p>
      </RouteLocaleProvider>,
    );

    expect(localStorage.getItem("locale")).toBeNull();
  });
});

describe("P9: API errors come back in the language the screen is in", () => {
  it("asks for the UI's default language when none is stored", async () => {
    let sent: string | null = null;
    server.use(
      http.get("*/api/v1/ping", ({ request }) => {
        sent = request.headers.get("Accept-Language");
        return HttpResponse.json({});
      }),
    );

    await apiClient.get("/ping");

    // Was "en" while the screens fell back to Amharic.
    expect(sent).toBe(DEFAULT_LOCALE);
  });

  it("asks for the stored language once one is chosen", async () => {
    localStorage.setItem("locale", "en");
    let sent: string | null = null;
    server.use(
      http.get("*/api/v1/ping", ({ request }) => {
        sent = request.headers.get("Accept-Language");
        return HttpResponse.json({});
      }),
    );

    await apiClient.get("/ping");

    expect(sent).toBe("en");
  });
});

describe("one session refresh for requests that fail together", () => {
  it("refreshes once, then retries each request", async () => {
    let refreshes = 0;
    const attempts: Record<string, number> = { a: 0, b: 0, c: 0 };
    server.use(
      http.post("*/api/v1/auth/refresh", async () => {
        refreshes += 1;
        await new Promise((resolve) => setTimeout(resolve, 20));
        return HttpResponse.json({ access_token: "x" });
      }),
      http.get("*/api/v1/probe/:name", ({ params }) => {
        const name = String(params.name);
        attempts[name] += 1;
        return attempts[name] === 1
          ? HttpResponse.json({}, { status: 401 })
          : HttpResponse.json({ ok: name });
      }),
    );

    const results = await Promise.all(
      ["a", "b", "c"].map((name) => apiClient.get(`/probe/${name}`)),
    );

    expect(refreshes).toBe(1);
    expect(results.map((r) => r.data.ok)).toEqual(["a", "b", "c"]);
  });
});

describe("sign-up checks the subdomain once typing pauses", () => {
  function field(container: HTMLElement, id: string) {
    return container.querySelector(`#${id}`) as HTMLInputElement;
  }

  it("asks about the final name only, not every keystroke", async () => {
    const asked: string[] = [];
    server.use(
      http.get("*/api/v1/register/check-subdomain", ({ request }) => {
        asked.push(new URL(request.url).searchParams.get("subdomain") ?? "");
        return HttpResponse.json({ available: true });
      }),
    );

    const { container } = render(<RegisterPage />);
    for (const value of ["QA A", "QA Au", "QA Aud", "QA Audi", "QA Audit"]) {
      fireEvent.change(field(container, "org_name"), { target: { value } });
    }

    await waitFor(() => expect(asked).toEqual(["qa-audit"]));
    // And nothing more arrives after the pause.
    await new Promise((resolve) => setTimeout(resolve, 700));
    expect(asked).toEqual(["qa-audit"]);
  });
});

describe("the 404 page", () => {
  it("has a title", async () => {
    const { default: NotFound } = await import("@/app/not-found");
    render(<NotFound />);

    // React 19 hoists a rendered <title> into <head>.
    await waitFor(() => expect(document.title).toMatch(/\| ETHR$/));
  });
});
