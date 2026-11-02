import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import KioskPage from "@/app/kiosk/page";
import { EXIT_HOLD_MS } from "@/features/kiosk/kiosk-lock";

/**
 * The lock screen asks for the admin PIN set when the kiosk was registered.
 * Until 2026-10-09 it accepted any four digits and never asked the server
 * (redundancy audit R1), so anyone at a shared terminal could take it out of
 * service.
 */
let sentPins: string[] = [];

beforeEach(() => {
  sentPins = [];
  localStorage.setItem("locale", "en");
  localStorage.setItem("kiosk_token", "kiosk-token");
  server.use(
    http.post("*/kiosk/authenticate", () =>
      HttpResponse.json({
        session: { branch: { name: "Head Office" } },
        tenant: { name: "Acme", subdomain: "acme", logo_path: null },
        settings: { pin_required: false, auto_reset_seconds: 4 },
      }),
    ),
    http.post("*/kiosk/exit", async ({ request }) => {
      const body = (await request.json()) as { admin_pin: string };
      sentPins.push(body.admin_pin);
      if (body.admin_pin === "4821")
        return new HttpResponse(null, { status: 204 });
      return HttpResponse.json(
        {
          detail: "The given data was invalid.",
          errors: { admin_pin: ["Incorrect admin PIN."] },
        },
        { status: 422 },
      );
    }),
  );
});

afterEach(() => {
  localStorage.removeItem("kiosk_token");
});

/**
 * The way out is a three-second hold on the organisation's name (2026-11-01).
 * A visible Exit button was one tap from the PIN screen for anyone in the queue.
 */
async function openLockScreen() {
  render(<KioskPage />);
  const name = await screen.findByRole("button", {
    name: /hold for 3 seconds to exit/i,
  });
  vi.useFakeTimers({ shouldAdvanceTime: true });
  fireEvent.pointerDown(name);
  await act(async () => {
    vi.advanceTimersByTime(EXIT_HOLD_MS);
  });
  vi.useRealTimers();
  return screen.findByPlaceholderText("Admin PIN");
}

describe("leaving kiosk mode", () => {
  it("stays in kiosk mode on a wrong PIN, and says why", async () => {
    await userEvent.type(await openLockScreen(), "0000");
    await userEvent.click(screen.getByRole("button", { name: /Exit Kiosk/ }));

    expect(await screen.findByText("Incorrect admin PIN.")).toBeInTheDocument();
    expect(sentPins).toEqual(["0000"]);
    expect(localStorage.getItem("kiosk_token")).toBe("kiosk-token");
    expect(screen.queryByText("Kiosk Setup")).not.toBeInTheDocument();
  });

  it("leaves with the registered PIN", async () => {
    await userEvent.type(await openLockScreen(), "4821");
    await userEvent.click(screen.getByRole("button", { name: /Exit Kiosk/ }));

    expect(await screen.findByText("Kiosk Setup")).toBeInTheDocument();
    expect(sentPins).toEqual(["4821"]);
    expect(localStorage.getItem("kiosk_token")).toBeNull();
  });

  it("ignores a tap on the name: only a full hold opens the PIN screen", async () => {
    render(<KioskPage />);
    const name = await screen.findByRole("button", {
      name: /hold for 3 seconds to exit/i,
    });
    vi.useFakeTimers({ shouldAdvanceTime: true });
    fireEvent.pointerDown(name);
    await act(async () => {
      vi.advanceTimersByTime(EXIT_HOLD_MS - 500);
    });
    fireEvent.pointerUp(name);
    await act(async () => {
      vi.advanceTimersByTime(EXIT_HOLD_MS);
    });
    vi.useRealTimers();

    expect(screen.queryByPlaceholderText("Admin PIN")).not.toBeInTheDocument();
  });
});
