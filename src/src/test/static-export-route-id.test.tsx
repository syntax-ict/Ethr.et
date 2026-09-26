import { afterEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { act } from "react";
import { hydrateRoot } from "react-dom/client";
import { renderToString } from "react-dom/server";

import { useRouteId } from "@/lib/hooks/useRouteId";
import {
  routeIdFromPathname,
  staticExportIdParams,
  STATIC_EXPORT_ROUTE_ID,
} from "@/lib/static-export";

let pathname = "/";

vi.mock("next/navigation", () => ({
  usePathname: () => pathname,
}));

function Subject({ routeId }: { routeId: string }) {
  const id = useRouteId(routeId);

  return <span data-testid="id">{id === null ? "(null)" : id}</span>;
}

function idFor(routeId: string, at: string) {
  pathname = at;
  const view = render(<Subject routeId={routeId} />);
  const id = screen.getByTestId("id").textContent;
  // Unmounted so a test may call this more than once; two mounted Subjects make
  // getByTestId ambiguous rather than wrong, which is a confusing way to fail.
  view.unmount();

  return id;
}

/**
 * How an `[id]` route survives `output: "export"`.
 *
 * The mechanism is a sentinel: one shell is built under
 * `STATIC_EXPORT_ROUTE_ID`, and `.htaccess` hands that shell to every id, so the
 * real id is in the URL and nowhere else. Measured against Next 16.3.5 on
 * 2026-09-26 (`docs/audit/BASELINE.md` §19) — `useParams()` returns the
 * sentinel on such a shell, `usePathname()` returns the real path.
 *
 * What these assertions protect is the *no-op* direction as much as the export
 * one: under `output: "standalone"` the param is a real id and this hook must
 * return it untouched, because that is every deployment ETHR has today.
 */
describe("useRouteId", () => {
  it("returns a real route param unchanged, ignoring the path", () => {
    // Standalone, dev and the rest of the test suite take this branch. The
    // pathname is deliberately a different id: nothing should be read from it.
    expect(
      idFor("01J8ZQ9K0000000000000000AA", "/employees/somewhere-else"),
    ).toBe("01J8ZQ9K0000000000000000AA");
  });

  it("reads the id out of the URL when it is serving the exported shell", () => {
    expect(
      idFor(STATIC_EXPORT_ROUTE_ID, "/employees/01J8ZQ9K0000000000000000BB"),
    ).toBe("01J8ZQ9K0000000000000000BB");
  });

  it("resolves the id on every route that has an exported shell", () => {
    // All four are named in DEPLOYMENT.md §5 step 4. A fifth would need its own
    // rewrite in .htaccess, so the list is not incidental.
    expect(idFor(STATIC_EXPORT_ROUTE_ID, "/payroll/RUN1")).toBe("RUN1");
    expect(idFor(STATIC_EXPORT_ROUTE_ID, "/devices/DEV1")).toBe("DEV1");
    expect(idFor(STATIC_EXPORT_ROUTE_ID, "/admin/tenants/TEN1")).toBe("TEN1");
  });
});

/**
 * The hydration half, driven through a real server render and a real hydrate.
 *
 * This is not a duplicate of the tests above — those exercise the resolved
 * value, this one exercises *when* it becomes available. The shell's HTML is
 * rendered for the sentinel path and hydrated on the real one, which is exactly
 * the divergence a static export creates, and the failure it protects against is
 * specific: deriving the id during the first client render instead of after it
 * raises React hydration error #418 (measured 2026-09-26). That version passes
 * every assertion above and is still wrong.
 */
describe("useRouteId under a real hydration", () => {
  const errors: string[] = [];
  let spy: ReturnType<typeof vi.spyOn>;

  afterEach(() => {
    spy?.mockRestore();
    errors.length = 0;
  });

  it("paints the sentinel's shell, then swaps in the real id without a mismatch", async () => {
    spy = vi
      .spyOn(console, "error")
      .mockImplementation(
        (...args: unknown[]) => void errors.push(String(args[0])),
      );

    // The shell was built under the sentinel, so that is the path it rendered on.
    pathname = "/employees/" + STATIC_EXPORT_ROUTE_ID;
    const shell = renderToString(<Subject routeId={STATIC_EXPORT_ROUTE_ID} />);

    // The exported HTML must be the loading state, not somebody's record.
    expect(shell).toContain("(null)");
    expect(shell).not.toContain("01J8ZQ9K");

    // The browser is on a real id. Same HTML, different URL — the static-export case.
    pathname = "/employees/01J8ZQ9K0000000000000000CC";
    const host = document.createElement("div");
    host.innerHTML = shell;
    document.body.appendChild(host);

    await act(async () => {
      hydrateRoot(host, <Subject routeId={STATIC_EXPORT_ROUTE_ID} />);
    });

    expect(host.textContent).toBe("01J8ZQ9K0000000000000000CC");
    expect(errors.filter((e) => /hydrat|did not match|418/i.test(e))).toEqual(
      [],
    );

    host.remove();
  });
});

describe("routeIdFromPathname", () => {
  it("returns null rather than an empty string when there is no id", () => {
    // An empty string would be a truthy-looking `""` at the call site and would
    // fetch `/employees/`. Callers check for null.
    expect(routeIdFromPathname(null)).toBeNull();
    expect(routeIdFromPathname("/")).toBeNull();
    expect(routeIdFromPathname("")).toBeNull();
  });

  it("ignores a trailing slash, a query string and a fragment", () => {
    expect(routeIdFromPathname("/employees/ABC/")).toBe("ABC");
    expect(routeIdFromPathname("/employees/ABC?tab=leave")).toBe("ABC");
    expect(routeIdFromPathname("/employees/ABC#notes")).toBe("ABC");
  });

  it("falls back to the raw segment instead of throwing on a bad escape", () => {
    // decodeURIComponent("%zz") is a URIError. A crashed render is a worse
    // answer than an id the API will reject.
    expect(routeIdFromPathname("/employees/%zz")).toBe("%zz");
  });
});

describe("staticExportIdParams", () => {
  it("returns exactly one shell, not none", () => {
    // `[]` is what §5 step 4 prescribed, and it is why that step did not work:
    // an empty list is a valid answer to "which pages exist", and it means none.
    expect(staticExportIdParams()).toEqual([{ id: STATIC_EXPORT_ROUTE_ID }]);
  });

  it("uses a sentinel no ULID can collide with", () => {
    // public_id is char(26) Crockford base32 — see the users table migration.
    expect(STATIC_EXPORT_ROUTE_ID).not.toMatch(/^[0-9A-HJKMNP-TV-Z]{26}$/);
  });
});
