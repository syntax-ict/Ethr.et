import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import KioskPage from "@/app/kiosk/page";

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

async function openLockScreen() {
  render(<KioskPage />);
  await userEvent.click(await screen.findByRole("button", { name: /^Exit$/ }));
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
});
