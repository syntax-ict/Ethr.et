import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { authenticateKiosk, kioskPunch } from "@/features/kiosk/api";

/**
 * The kiosk terminal has no user session. A 401 from its endpoints means
 * "invalid token" or "wrong PIN" and must reach the kiosk screen. The client
 * treated it as an expired login: it tried /auth/refresh, which always fails
 * there, and sent the shared terminal to /login (audit N48).
 */
const originalLocation = window.location;

beforeEach(() => {
  // jsdom refuses real navigation; a plain object lets the test read where the
  // client tried to send the browser. It needs a real origin, because the
  // client's baseURL is relative.
  Object.defineProperty(window, "location", {
    configurable: true,
    writable: true,
    value: {
      href: "http://localhost:3000/kiosk",
      origin: "http://localhost:3000",
      protocol: "http:",
      host: "localhost:3000",
      hostname: "localhost",
      port: "3000",
      pathname: "/kiosk",
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

function refuseEverything() {
  let refreshes = 0;
  const problem = {
    type: "https://ethr.et/errors/unauthorized",
    title: "Unauthorized",
    status: 401,
    detail: "Invalid kiosk token.",
  };
  server.use(
    http.post("*/api/v1/kiosk/authenticate", () =>
      HttpResponse.json(problem, { status: 401 }),
    ),
    http.post("*/api/v1/kiosk/check-in", () =>
      HttpResponse.json(problem, { status: 401 }),
    ),
    http.post("*/auth/refresh", () => {
      refreshes += 1;
      return new HttpResponse(null, { status: 401 });
    }),
  );
  return () => refreshes;
}

describe("kiosk calls answered 401", () => {
  it("hand an invalid token back to the kiosk screen", async () => {
    const refreshes = refuseEverything();

    await expect(authenticateKiosk("not-a-token")).rejects.toMatchObject({
      response: { status: 401, data: { detail: "Invalid kiosk token." } },
    });
    expect(refreshes()).toBe(0);
    expect(window.location.href).toBe("http://localhost:3000/kiosk");
  });

  it("hand a refused punch back too, so a wrong PIN stays on the kiosk", async () => {
    const refreshes = refuseEverything();

    await expect(
      kioskPunch("token", {
        employee_code: "1001",
        type: "check_in",
        idempotency_key: "k-1",
      }),
    ).rejects.toMatchObject({ response: { status: 401 } });
    expect(refreshes()).toBe(0);
    expect(window.location.href).toBe("http://localhost:3000/kiosk");
  });
});
