import { beforeEach, afterEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";

/**
 * Which sound the kiosk plays for which result. The sound itself is
 * kiosk-sound.test.tsx; this is the page choosing the cue.
 */
const play = vi.fn();
const modes: string[] = [];

vi.mock("@/features/kiosk/kiosk-sound", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/features/kiosk/kiosk-sound")>()),
  useKioskSound: (mode: string) => {
    modes.push(mode);
    return play;
  },
}));

const { default: KioskPage } = await import("@/app/kiosk/page");

const punched = (overrides: Record<string, unknown> = {}) =>
  HttpResponse.json(
    {
      public_id: "REC1",
      punch: "check_in",
      check_in: "2026-11-01T05:30:00Z",
      was_duplicate: false,
      employee_name: "Abebe Kebede",
      employee: {
        name: "Abebe Kebede",
        name_am: null,
        position: null,
        photo_url: null,
      },
      ...overrides,
    },
    { status: 201 },
  );

beforeEach(() => {
  play.mockReset();
  modes.length = 0;
  localStorage.setItem("locale", "en");
  localStorage.setItem("kiosk_token", "kiosk-token");
  server.use(
    http.post("*/kiosk/authenticate", () =>
      HttpResponse.json({
        session: { branch: { name: "Head Office" } },
        tenant: { name: "Acme", subdomain: "acme", logo_path: null },
        settings: {
          pin_required: false,
          auto_reset_seconds: 4,
          sound: "voice",
        },
      }),
    ),
  );
});

afterEach(() => {
  localStorage.removeItem("kiosk_token");
});

async function punch() {
  render(<KioskPage />);
  await userEvent.type(
    await screen.findByRole("textbox", { name: /employee code/i }),
    "EMP-0001",
  );
  await userEvent.click(screen.getByRole("button", { name: /^record$/i }));
}

describe("kiosk sound cues", () => {
  it("follows the organisation's setting", async () => {
    server.use(http.post("*/kiosk/check-in", () => punched()));
    await punch();
    await screen.findByText("Welcome, Abebe Kebede");
    expect(modes.at(-1)).toBe("voice");
  });

  it("plays done for a check-in and reports a 'Not you?'", async () => {
    server.use(
      http.post("*/kiosk/check-in", () => punched()),
      http.post("*/kiosk/punches/:id/not-me", () =>
        HttpResponse.json({ status: "reported" }, { status: 202 }),
      ),
    );
    await punch();
    await screen.findByText("Welcome, Abebe Kebede");
    expect(play).toHaveBeenLastCalledWith("check_in");

    await userEvent.click(screen.getByRole("button", { name: /not you/i }));
    await vi.waitFor(() => expect(play).toHaveBeenLastCalledWith("reported"));
  });

  it("plays the plain tone for a punch that was already recorded", async () => {
    server.use(
      http.post("*/kiosk/check-in", () =>
        punched({ was_duplicate: true, punch: "check_out" }),
      ),
    );
    await punch();
    await screen.findByText("Goodbye, Abebe Kebede");
    expect(play).toHaveBeenLastCalledWith("duplicate");
  });

  it("tells a lock from a mistake", async () => {
    server.use(
      http.post("*/kiosk/check-in", () =>
        HttpResponse.json(
          { status: 429, detail: "Too many wrong PINs." },
          { status: 429 },
        ),
      ),
    );
    await punch();
    await screen.findByText("Too many wrong PINs.");
    expect(play).toHaveBeenLastCalledWith("locked");
  });

  it("plays the refusal for anything else", async () => {
    server.use(
      http.post("*/kiosk/check-in", () =>
        HttpResponse.json(
          { status: 404, detail: "Employee not found." },
          { status: 404 },
        ),
      ),
    );
    await punch();
    await screen.findByText("Employee not found.");
    expect(play).toHaveBeenLastCalledWith("error");
  });
});
