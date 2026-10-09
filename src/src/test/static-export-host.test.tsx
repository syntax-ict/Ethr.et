import { afterEach, describe, expect, it, vi } from "vitest";
import { act } from "react";
import { hydrateRoot } from "react-dom/client";
import { renderToString } from "react-dom/server";

import { HostProvider, useHost } from "@/lib/auth/host-provider";

function Subject() {
  const host = useHost();

  return <span data-testid="host">{host === null ? "(null)" : host}</span>;
}

/**
 * `useHost()` on a build that had no request to read the host from.
 *
 * Under `output: "standalone"` the auth layout reads `headers()` and hands the
 * real hostname to `HostProvider`, so nothing here applies. Under
 * `output: "export"` there is no request when the HTML is built, the provider is
 * handed `null`, and the browser's own `window.location.host` is the only
 * source — which is the situation these tests pin.
 *
 * The trap, and the reason this file exists: `typeof window !== "undefined"` is
 * already true *during* hydration. A fallback guarded only by that produces the
 * real hostname on the first client render, against HTML built with `null`, and
 * React raises hydration error #418 — the very error `host-provider.tsx` was
 * written to remove. Measured 2026-09-26.
 */
describe("useHost when the provider has a host (every Node target)", () => {
  it("returns it, and never consults the browser", () => {
    const html = renderToString(
      <HostProvider host="habru.ethr.et">
        <Subject />
      </HostProvider>,
    );

    expect(html).toContain("habru.ethr.et");
  });
});

describe("useHost on a static export, where the provider has null", () => {
  const errors: string[] = [];
  let spy: ReturnType<typeof vi.spyOn>;

  afterEach(() => {
    spy?.mockRestore();
    errors.length = 0;
  });

  it("renders no host into the HTML, then adopts the browser's after hydration", async () => {
    spy = vi
      .spyOn(console, "error")
      .mockImplementation(
        (...args: unknown[]) => void errors.push(String(args[0])),
      );

    // What `next build` writes under `output: "export"`: no request, no host.
    const shell = renderToString(
      <HostProvider host={null}>
        <Subject />
      </HostProvider>,
    );

    expect(shell).toContain("(null)");

    // The browser is on a tenant host. Same HTML — one build serves every tenant.
    const host = document.createElement("div");
    host.innerHTML = shell;
    document.body.appendChild(host);

    await act(async () => {
      hydrateRoot(
        host,
        <HostProvider host={null}>
          <Subject />
        </HostProvider>,
      );
    });

    // jsdom's default location, whatever the suite is configured with.
    expect(host.textContent).toBe(window.location.host);
    expect(host.textContent).not.toBe("(null)");
    expect(errors.filter((e) => /hydrat|did not match|418/i.test(e))).toEqual(
      [],
    );

    host.remove();
  });
});
