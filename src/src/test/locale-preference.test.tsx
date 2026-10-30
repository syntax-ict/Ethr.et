import { describe, it, expect, beforeEach } from "vitest";
import { render, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { LocalePreferenceSync } from "@/components/shared/locale-preference-sync";

/**
 * Review 2026-10-30, D1: the language a user chose was stored on the user and
 * never applied on another device. Only a chosen language is applied: the
 * stored `locale` defaults to "en", which would otherwise turn every Amharic
 * organisation English.
 */

function me(preferences: Record<string, unknown>) {
  return http.get("*/api/v1/auth/me", () =>
    HttpResponse.json({
      user: {
        public_id: "U1",
        role: "employee",
        preferences: { theme: "system", calendar: "ethiopian", ...preferences },
      },
      tenant: { public_id: "T1", name: "Abay" },
      permissions: [],
    }),
  );
}

async function renderSync() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  render(
    <QueryClientProvider client={client}>
      <LocalePreferenceSync />
    </QueryClientProvider>,
  );
  await waitFor(() => expect(client.getQueryData(["auth", "me"])).toBeTruthy());
}

beforeEach(() => {
  localStorage.setItem("locale", "am");
});

describe("<LocalePreferenceSync>", () => {
  it("applies the language the user chose over this browser's", async () => {
    server.use(me({ locale: "en", locale_chosen: true }));
    await renderSync();

    await waitFor(() => expect(localStorage.getItem("locale")).toBe("en"));
    expect(document.documentElement.lang).toBe("en");
  });

  it("leaves the browser's language when the user never chose one", async () => {
    server.use(me({ locale: "en", locale_chosen: false }));
    await renderSync();

    expect(localStorage.getItem("locale")).toBe("am");
  });

  it("leaves the browser's language when the server does not say", async () => {
    // A server from before the marker sends no `locale_chosen`.
    server.use(me({ locale: "en" }));
    await renderSync();

    expect(localStorage.getItem("locale")).toBe("am");
  });

  it("ignores a chosen language the app cannot show yet", async () => {
    server.use(me({ locale: "om", locale_chosen: true }));
    await renderSync();

    expect(localStorage.getItem("locale")).toBe("am");
  });
});
