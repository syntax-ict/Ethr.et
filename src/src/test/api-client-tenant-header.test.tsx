import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { apiClient } from "@/api/client";

/**
 * Which hosts carry the organisation in `X-Tenant`.
 *
 * With a root domain configured, the header used to be switched off
 * everywhere, on the assumption that every organisation then lived on its
 * subdomain. Since 2026-10-11 each organisation chooses its address, and one
 * that chose the shared address signs in on the apex — where nothing but this
 * header names it. So the header follows the host, not the configuration.
 */
const originalLocation = window.location;

function onHost(host: string) {
  Object.defineProperty(window, "location", {
    value: new URL(`https://${host}/dashboard`),
    writable: true,
    configurable: true,
  });
}

let seenHeader: string | null | undefined;

beforeEach(() => {
  seenHeader = undefined;
  localStorage.setItem("tenant", "habru");
  server.use(
    http.get("*/api/v1/auth/me", ({ request }) => {
      seenHeader = request.headers.get("X-Tenant");
      return HttpResponse.json({ user: null });
    }),
  );
});

afterEach(() => {
  Object.defineProperty(window, "location", {
    value: originalLocation,
    writable: true,
    configurable: true,
  });
  localStorage.removeItem("tenant");
  vi.unstubAllEnvs();
});

describe("X-Tenant with a root domain configured", () => {
  it("is sent on the apex and its www alias, where the hostname names no organisation", async () => {
    vi.stubEnv("NEXT_PUBLIC_ROOT_DOMAIN", "ethr.et");

    onHost("ethr.et");
    await apiClient.get("/auth/me");
    expect(seenHeader).toBe("habru");

    onHost("www.ethr.et");
    await apiClient.get("/auth/me");
    expect(seenHeader).toBe("habru");
  });

  it("is not sent on a tenant host or the platform host, where the hostname decides", async () => {
    vi.stubEnv("NEXT_PUBLIC_ROOT_DOMAIN", "ethr.et");

    onHost("woldia.ethr.et");
    await apiClient.get("/auth/me");
    expect(seenHeader).toBeNull();

    onHost("admin.ethr.et");
    await apiClient.get("/auth/me");
    expect(seenHeader).toBeNull();
  });

  it("is sent on any host when no root domain is configured (single-host install)", async () => {
    vi.stubEnv("NEXT_PUBLIC_ROOT_DOMAIN", "");

    onHost("localhost:3000");
    await apiClient.get("/auth/me");
    expect(seenHeader).toBe("habru");
  });
});
