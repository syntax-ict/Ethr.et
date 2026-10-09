import { describe, it, expect } from "vitest";
import { shouldRegisterServiceWorker } from "@/lib/hooks/useServiceWorker";

describe("shouldRegisterServiceWorker", () => {
  it("never registers under next dev, tenant subdomains included", () => {
    // demo.localhost under `next dev` kept a cache-first worker that served
    // the first build of every chunk and hid each later edit.
    expect(shouldRegisterServiceWorker("demo.localhost", "development")).toBe(
      false,
    );
    expect(shouldRegisterServiceWorker("localhost", "development")).toBe(false);
  });

  it("still registers for a production build on a tenant subdomain", () => {
    // The offline E2E specs run a production build at demo.localhost.
    expect(shouldRegisterServiceWorker("demo.localhost", "production")).toBe(
      true,
    );
    expect(shouldRegisterServiceWorker("acme.ethr.et", "production")).toBe(
      true,
    );
  });

  it("keeps bare loopback hosts unregistered, as before", () => {
    for (const host of ["localhost", "127.0.0.1", "0.0.0.0"]) {
      expect(shouldRegisterServiceWorker(host, "production")).toBe(false);
    }
  });
});
