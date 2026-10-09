import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { HostProvider } from "@/lib/auth/host-provider";
import { useAuthHostContext } from "@/lib/auth/use-auth-host-context";

/**
 * A single-host build (no NEXT_PUBLIC_ROOT_DOMAIN, which is how production
 * builds) serving an organisation's verified custom domain. The build cannot
 * tell `hr.acme.com` from the apex, but the server can, from the host alone.
 *
 * Found in the browser on 2026-10-08: the sign-in page on `hr.acme.localhost`
 * read "Sign in to ETHR" and asked for the organisation in a field, on that
 * organisation's own domain. The hook never asked the server in that context.
 */
function Probe() {
  const ctx = useAuthHostContext();
  return (
    <div>
      <span data-testid="context">{ctx.context}</span>
      <span data-testid="slug">{ctx.tenantSlug ?? "-"}</span>
      <span data-testid="name">{ctx.tenantName ?? "-"}</span>
      <span data-testid="field">{String(ctx.tenantFieldVisible)}</span>
    </div>
  );
}

function renderOn(host: string) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <HostProvider host={host}>
        <Probe />
      </HostProvider>
    </QueryClientProvider>,
  );
}

const seenTenantHeaders: Array<string | null> = [];

beforeEach(() => {
  seenTenantHeaders.length = 0;
  localStorage.clear();
});

afterEach(() => localStorage.clear());

describe("the auth host context on a host the build cannot classify", () => {
  it("names the organisation the server resolves from the host, and hides the field", async () => {
    server.use(
      http.get("*/auth/tenant-context", ({ request }) => {
        seenTenantHeaders.push(request.headers.get("X-Tenant"));
        return HttpResponse.json({
          tenant: { name: "Acme Ltd", subdomain: "acme", logo_path: null },
        });
      }),
    );

    renderOn("hr.acme.localhost:8081");

    await waitFor(() =>
      expect(screen.getByTestId("context")).toHaveTextContent("tenant"),
    );
    expect(screen.getByTestId("slug")).toHaveTextContent("acme");
    expect(screen.getByTestId("name")).toHaveTextContent("Acme Ltd");
    expect(screen.getByTestId("field")).toHaveTextContent("false");
  });

  it("asks without X-Tenant, so a remembered organisation cannot answer for the host", async () => {
    localStorage.setItem("tenant", "habru");
    server.use(
      http.get("*/auth/tenant-context", ({ request }) => {
        seenTenantHeaders.push(request.headers.get("X-Tenant"));
        return HttpResponse.json({ tenant: null });
      }),
    );

    renderOn("ethr.localhost:8081");

    await waitFor(() => expect(seenTenantHeaders.length).toBeGreaterThan(0));
    expect(seenTenantHeaders).toEqual([null]);
    // The apex: nothing resolves from the host, the field stays.
    expect(screen.getByTestId("context")).toHaveTextContent("unknown");
    expect(screen.getByTestId("field")).toHaveTextContent("true");
  });
});
