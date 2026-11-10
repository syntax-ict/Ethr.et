import { describe, it, expect, afterEach } from "vitest";
import { act, renderHook, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { hydrateRoot } from "react-dom/client";
import { renderToString } from "react-dom/server";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { useSiteContent } from "@/features/marketing/site-content";

/**
 * `GET /site-content` ran on every page load of the signed-in app, for a
 * payload the backend itself caches for five minutes (dashboard review,
 * 2026-11-03, PR 11). The last answer is kept in localStorage: a copy younger
 * than that stands in and no request is made; an older one is only a
 * placeholder while the real answer comes.
 */
const KEY = "ethr.site-content";

function serve(name: string) {
  const hits: string[] = [];
  server.use(
    http.get("*/api/v1/site-content", ({ request }) => {
      hits.push(request.url);
      return HttpResponse.json({ data: { product_mark_label: name } });
    }),
  );
  return hits;
}

function renderContent() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return renderHook(() => useSiteContent(), { wrapper });
}

afterEach(() => {
  localStorage.removeItem(KEY);
});

describe("site content across page loads", () => {
  it("uses a stored copy younger than five minutes and asks for nothing", async () => {
    localStorage.setItem(
      KEY,
      JSON.stringify({
        data: { product_mark_label: "stored" },
        at: Date.now() - 60_000,
      }),
    );
    const hits = serve("fresh");
    const { result } = renderContent();

    expect(result.current.product_mark_label).toBe("stored");
    await new Promise((r) => setTimeout(r, 150));
    expect(hits).toEqual([]);
  });

  it("refetches past a stale copy, and stores the new answer", async () => {
    localStorage.setItem(
      KEY,
      JSON.stringify({
        data: { product_mark_label: "stale" },
        at: Date.now() - 10 * 60_000,
      }),
    );
    const hits = serve("fresh");
    const { result } = renderContent();

    await waitFor(() =>
      expect(result.current.product_mark_label).toBe("fresh"),
    );
    expect(hits).toHaveLength(1);
    expect(
      JSON.parse(localStorage.getItem(KEY) ?? "{}").data.product_mark_label,
    ).toBe("fresh");
  });

  it("fetches when nothing is stored, and stores it", async () => {
    const hits = serve("fresh");
    const { result } = renderContent();

    await waitFor(() =>
      expect(result.current.product_mark_label).toBe("fresh"),
    );
    expect(hits).toHaveLength(1);
    expect(localStorage.getItem(KEY)).toContain("fresh");
  });
});

/**
 * The public pages are prerendered with no site content, because the build has
 * no browser storage. A returning visitor does have a stored copy, and reading
 * it during the first client render put text on the page that the HTML did not
 * hold: React error 418 on every marketing page (seen on the live site,
 * 2026-11-10). The stored copy now waits until hydration is over.
 */
function Tagline() {
  const site = useSiteContent();
  return <p>{site.tagline ?? "the built-in tagline"}</p>;
}

function page() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return (
    <QueryClientProvider client={client}>
      <Tagline />
    </QueryClientProvider>
  );
}

async function hydrate(html: string) {
  const container = document.createElement("div");
  container.innerHTML = html;
  document.body.append(container);
  const mismatches: unknown[] = [];
  let root: ReturnType<typeof hydrateRoot> | undefined;
  await act(async () => {
    root = hydrateRoot(container, page(), {
      onRecoverableError: (error) => mismatches.push(error),
    });
  });
  return {
    container,
    mismatches,
    done: () => {
      root?.unmount();
      container.remove();
    },
  };
}

describe("site content on a prerendered page", () => {
  it("hydrates without a mismatch when a stored copy exists, then shows it without a request", async () => {
    const html = renderToString(page());
    expect(html).toContain("the built-in tagline");

    localStorage.setItem(
      KEY,
      JSON.stringify({
        data: { tagline: "set in the console" },
        at: Date.now() - 60_000,
      }),
    );
    const hits = serve("fresh");
    const { container, mismatches, done } = await hydrate(html);

    expect(mismatches).toEqual([]);
    await waitFor(() =>
      expect(container.textContent).toBe("set in the console"),
    );
    await new Promise((r) => setTimeout(r, 150));
    expect(hits).toEqual([]);
    done();
  });

  it("still fetches after hydration when nothing is stored", async () => {
    const html = renderToString(page());
    const hits = serve("fresh");
    const { mismatches, done } = await hydrate(html);

    await waitFor(() => expect(hits).toHaveLength(1));
    expect(mismatches).toEqual([]);
    done();
  });
});
