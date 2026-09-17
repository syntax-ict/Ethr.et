import { describe, it, expect, beforeEach, afterEach } from "vitest";
import { renderHook, waitFor, act } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { useCurrentUser, useLogout } from "@/features/auth/api";

/**
 * Logging out has to end the session on this device whatever the server says.
 *
 * The app holds **one** QueryClient, created once in `app/providers.tsx` and
 * never replaced, so everything fetched during a session stays in memory until
 * something clears it or the page is reloaded. `useLogout` does both — clears
 * the cache and hard-navigates — but only in `onSuccess`.
 *
 * That leaves the failure path doing nothing at all: no navigation, no clear,
 * and neither call site passes an `onError`. The user clicks Log Out, the
 * request fails, and they are left on a populated dashboard believing they have
 * logged out. In a product that advertises itself as offline-first, a failed
 * request is not an edge case.
 *
 * Signing out is a decision about this device. The server call is how we ask it
 * to revoke the token as well, and it is worth attempting — but it cannot be
 * the thing that decides whether the local session ends.
 */
const originalLocation = window.location;

function stubLocation() {
  Object.defineProperty(window, "location", {
    configurable: true,
    writable: true,
    value: {
      href: "http://localhost:3000/",
      origin: "http://localhost:3000",
      protocol: "http:",
      host: "localhost:3000",
      hostname: "localhost",
      port: "3000",
      pathname: "/",
      search: "",
      hash: "",
    },
  });
}

function makeClient() {
  return new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
}

function mockMe() {
  server.use(
    http.get("*/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "01HZME", name: "Abebe", email: "abebe@acme.et" },
        permissions: ["employee.create"],
        tenant: null,
      }),
    ),
  );
}

beforeEach(() => {
  stubLocation();
});

afterEach(() => {
  Object.defineProperty(window, "location", {
    configurable: true,
    writable: true,
    value: originalLocation,
  });
});

describe("logging out", () => {
  it("clears everything cached and leaves for the login screen", async () => {
    mockMe();
    server.use(
      http.post("*/auth/logout", () => HttpResponse.json({ message: "ok" })),
    );

    const client = makeClient();
    const { result } = renderHook(
      () => ({ me: useCurrentUser(), logout: useLogout() }),
      {
        wrapper: ({ children }) => (
          <QueryClientProvider client={client}>{children}</QueryClientProvider>
        ),
      },
    );

    await waitFor(() => expect(result.current.me.data).toBeDefined());
    expect(client.getQueryData(["auth", "me"])).toBeDefined();

    await act(async () => {
      await result.current.logout.mutateAsync();
    });

    expect(client.getQueryData(["auth", "me"])).toBeUndefined();
    expect(window.location.href).toBe("/login");
  });

  it("still ends the session on this device when the server call fails", async () => {
    // Offline, or the API is down, or the token has already expired. The
    // previous behaviour was to do nothing whatsoever — the person is left
    // looking at their own data, on a shared machine, believing otherwise.
    mockMe();
    server.use(
      http.post("*/auth/logout", () => new HttpResponse(null, { status: 500 })),
    );

    const client = makeClient();
    const { result } = renderHook(
      () => ({ me: useCurrentUser(), logout: useLogout() }),
      {
        wrapper: ({ children }) => (
          <QueryClientProvider client={client}>{children}</QueryClientProvider>
        ),
      },
    );

    await waitFor(() => expect(result.current.me.data).toBeDefined());
    expect(client.getQueryData(["auth", "me"])).toBeDefined();

    await act(async () => {
      await result.current.logout.mutateAsync().catch(() => {});
    });

    expect(client.getQueryData(["auth", "me"])).toBeUndefined();
    expect(window.location.href).toBe("/login");
  });
});
