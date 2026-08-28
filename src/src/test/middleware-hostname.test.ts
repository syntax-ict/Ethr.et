import { beforeAll, describe, expect, it, vi } from "vitest";

/**
 * The hostname redirects in `middleware.ts`.
 *
 * `ROOT_DOMAIN` is read at module scope, so the env var has to be in place
 * before the import — hence the dynamic import inside `beforeAll` rather than a
 * top-level one.
 */
const ROOT_DOMAIN = "localhost";

let middleware: (typeof import("@/middleware"))["middleware"];
let NextRequest: (typeof import("next/server"))["NextRequest"];

beforeAll(async () => {
  vi.stubEnv("NEXT_PUBLIC_ROOT_DOMAIN", ROOT_DOMAIN);
  ({ NextRequest } = await import("next/server"));
  ({ middleware } = await import("@/middleware"));
});

/** A request as it arrives behind a proxy: the Host header is what the browser used. */
function requestFor(host: string, pathname: string) {
  return new NextRequest(`http://internal-container:3000${pathname}`, {
    headers: { host },
  });
}

describe("hostname middleware", () => {
  it("sends /admin on a tenant host to the platform host", () => {
    const response = middleware(requestFor("demo.localhost:3000", "/admin"));
    const location = new URL(response.headers.get("location")!);

    expect(location.hostname).toBe(`admin.${ROOT_DOMAIN}`);
    expect(location.pathname).toBe("/admin");
  });

  it("keeps the port the browser actually used", () => {
    // Assigning `url.host` clears the port. Behind nginx that is correct — the
    // browser is on 443 and the container's :3000 must not leak into the
    // redirect. But with the hostname model switched on locally the console is
    // at `admin.localhost:3000`, and clearing it sent the browser to
    // `admin.localhost:80`, where nothing is listening.
    const response = middleware(requestFor("demo.localhost:3000", "/admin"));

    expect(response.headers.get("location")).toBe(
      "http://admin.localhost:3000/admin",
    );
  });

  it("adds no port when the browser used none", () => {
    // The production shape. The internal request URL still carries :3000 and
    // must not be read as the browser's port.
    const response = middleware(requestFor("demo.localhost", "/admin"));

    expect(response.headers.get("location")).toBe(
      "http://admin.localhost/admin",
    );
  });

  it("sends a tenant route on the platform host to the console", () => {
    const response = middleware(
      requestFor("admin.localhost:3000", "/dashboard"),
    );

    expect(new URL(response.headers.get("location")!).pathname).toBe("/admin");
  });

  it("leaves the login page alone on every host", () => {
    // The platform host serves /login itself — that is where a super admin
    // signs in. Redirecting it would be a loop.
    for (const host of [
      "admin.localhost:3000",
      "demo.localhost:3000",
      "localhost:3000",
    ]) {
      expect(
        middleware(requestFor(host, "/login")).headers.get("location"),
      ).toBeNull();
    }
  });
});
