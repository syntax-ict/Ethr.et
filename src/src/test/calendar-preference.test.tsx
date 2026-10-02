import { describe, it, expect, beforeEach } from "vitest";
import { act, render, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { CalendarProvider, useCalendar } from "@/lib/calendar/calendar-context";
import { CalendarPreferenceSync } from "@/components/shared/calendar-preference-sync";

/**
 * Audit N33: `/auth/me` returns the calendar in force — the user's own choice,
 * else the organisation's — and nothing on the frontend read it. Every browser
 * showed whatever its own `localStorage` last held, so a choice made on the
 * profile page, or by the organisation, never reached the screen.
 */

function Shown() {
  const { calendar } = useCalendar();
  return <output aria-label="calendar in use">{calendar}</output>;
}

function me(calendar: string | undefined) {
  return http.get("*/api/v1/auth/me", () =>
    HttpResponse.json({
      user: {
        public_id: "U1",
        role: "employee",
        ...(calendar === undefined
          ? {}
          : { preferences: { locale: "en", theme: "system", calendar } }),
      },
      tenant: { public_id: "T1", name: "Abay" },
      permissions: [],
    }),
  );
}

function renderShell() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <CalendarProvider>
        <CalendarPreferenceSync />
        <Shown />
      </CalendarProvider>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  localStorage.setItem("ethr.calendar", "ethiopian");
});

describe("<CalendarPreferenceSync>", () => {
  it("applies the signed-in user's calendar over this browser's cache", async () => {
    server.use(me("gregorian"));
    renderShell();

    await waitFor(() =>
      expect(screen.getByLabelText("calendar in use")).toHaveTextContent(
        "gregorian",
      ),
    );
    // ...and the cache now agrees, for the next pre-auth paint.
    expect(localStorage.getItem("ethr.calendar")).toBe("gregorian");
  });

  it("shows a stored dual choice as Ethiopian, which no dual display replaces yet", async () => {
    localStorage.setItem("ethr.calendar", "gregorian");
    server.use(me("dual"));
    renderShell();

    await waitFor(() =>
      expect(screen.getByLabelText("calendar in use")).toHaveTextContent(
        "ethiopian",
      ),
    );
  });

  it("keeps the cached calendar while the server has not answered with one", async () => {
    localStorage.setItem("ethr.calendar", "gregorian");
    server.use(me(undefined));
    renderShell();

    // Give the query time to settle; nothing should move the display.
    await act(() => new Promise((r) => setTimeout(r, 100)));
    expect(screen.getByLabelText("calendar in use")).toHaveTextContent(
      "gregorian",
    );
  });
});
