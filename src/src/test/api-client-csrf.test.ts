import { beforeEach, describe, expect, it } from "vitest";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { apiClient } from "@/api/client";
import { authenticateKiosk } from "@/features/kiosk/api";

/**
 * Sanctum answers a state-changing request that carries no XSRF cookie with a
 * 419 (measured on the rehearsal for /kiosk/authenticate and /contact).
 * Screens got the cookie as a side effect of an earlier request, or not at
 * all: the password reset page did not, and every activation and reset from an
 * email failed (2026-10-09). The client now fetches the cookie itself before a
 * write when it is missing. The kiosk's token check is the case driven here.
 */
let calls: string[] = [];
let xsrfSent: string | null = null;

function clearXsrfCookie() {
  document.cookie =
    "XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/";
}

beforeEach(() => {
  calls = [];
  xsrfSent = null;
  clearXsrfCookie();
  server.use(
    http.get("*/sanctum/csrf-cookie", () => {
      calls.push("csrf");
      document.cookie = "XSRF-TOKEN=from-sanctum; path=/";
      return new HttpResponse(null, { status: 204 });
    }),
    http.post("*/api/v1/kiosk/authenticate", ({ request }) => {
      calls.push("post");
      xsrfSent = request.headers.get("X-XSRF-TOKEN");
      return HttpResponse.json({
        session: { branch: null },
        tenant: { name: "Acme", subdomain: "acme", logo_path: null },
        settings: { pin_required: false, auto_reset_seconds: 4 },
      });
    }),
    http.get("*/api/v1/ping", () => {
      calls.push("get");
      return HttpResponse.json({});
    }),
  );
});

describe("apiClient and the XSRF cookie", () => {
  it("fetches the cookie before the first write, and sends it", async () => {
    await authenticateKiosk("kiosk-token");

    expect(calls).toEqual(["csrf", "post"]);
    expect(xsrfSent).toBe("from-sanctum");
  });

  it("fetches it once, not before every write", async () => {
    await authenticateKiosk("kiosk-token");
    await authenticateKiosk("kiosk-token");

    expect(calls).toEqual(["csrf", "post", "post"]);
  });

  it("leaves reads alone", async () => {
    await apiClient.get("/ping");

    expect(calls).toEqual(["get"]);
  });
});
