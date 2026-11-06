import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import KioskPage from "@/app/kiosk/page";

/**
 * The kiosk after 2026-11-01: it shows who a punch went to (name, job title,
 * face) and lets them say it was not them; it offers self-service behind the
 * person's own PIN; and it holds the screen against stray touches.
 */
beforeEach(() => {
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
  );
});

afterEach(() => {
  localStorage.removeItem("kiosk_token");
});

async function punch(code = "EMP-0001") {
  render(<KioskPage />);
  await userEvent.type(
    await screen.findByRole("textbox", { name: /employee code/i }),
    code,
  );
  await userEvent.click(screen.getByRole("button", { name: /^record$/i }));
}

describe("who a punch went to", () => {
  it("shows the person's name and job title, and reports a punch that was not theirs", async () => {
    let reported: { url: string; punch: string } | null = null;
    server.use(
      http.post("*/kiosk/check-in", () =>
        HttpResponse.json(
          {
            public_id: "REC1",
            punch: "check_in",
            check_in: "2026-11-01T05:30:00Z",
            was_duplicate: false,
            employee_name: "Abebe Kebede",
            employee: {
              name: "Abebe Kebede",
              name_am: "አበበ ከበደ",
              position: "Machine operator",
              photo_url: null,
            },
          },
          { status: 201 },
        ),
      ),
      http.post("*/kiosk/punches/:id/not-me", async ({ request }) => {
        reported = {
          url: request.url,
          punch: ((await request.json()) as { punch: string }).punch,
        };
        return HttpResponse.json({ status: "reported" }, { status: 202 });
      }),
    );

    await punch();

    expect(
      await screen.findByText("Welcome, Abebe Kebede"),
    ).toBeInTheDocument();
    expect(screen.getByText("Machine operator")).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: /not you/i }));

    expect(
      await screen.findByText(/HR will check this punch/),
    ).toBeInTheDocument();
    expect(reported).toEqual({
      url: expect.stringContaining("/kiosk/punches/REC1/not-me"),
      punch: "check_in",
    });
  });
});

describe("self-service at the kiosk", () => {
  it("shows a person their week and leave after their code and PIN, and signs them out on Done", async () => {
    let ended = false;
    server.use(
      http.post("*/kiosk/self-service", async ({ request }) => {
        const body = (await request.json()) as {
          employee_code: string;
          pin: string;
        };
        if (body.employee_code !== "EMP-0001" || body.pin !== "4321") {
          return HttpResponse.json(
            { detail: "That code and PIN do not match." },
            { status: 401 },
          );
        }
        return HttpResponse.json({
          pass: "pass-1",
          expires_at: new Date(Date.now() + 180_000).toISOString(),
          employee: {
            name: "Abebe Kebede",
            name_am: null,
            position: "Machine operator",
            photo_url: null,
          },
        });
      }),
      http.get("*/kiosk/self-service", ({ request }) => {
        if (request.headers.get("X-Kiosk-Pass") !== "pass-1") {
          return HttpResponse.json({}, { status: 401 });
        }
        return HttpResponse.json({
          employee: {
            name: "Abebe Kebede",
            name_am: null,
            position: "Machine operator",
            photo_url: null,
          },
          today: "2026-10-28",
          days: [
            {
              date: "2026-10-27",
              check_in: "2026-10-27T05:00:00Z",
              check_out: "2026-10-27T14:00:00Z",
              status: "present",
              worked_minutes: 540,
            },
          ],
          leave_balances: [
            {
              leave_type: "Annual leave",
              leave_type_am: null,
              entitled_days: 16,
              used_days: 3,
              pending_days: 1,
              remaining_days: 12,
            },
          ],
        });
      }),
      http.delete("*/kiosk/self-service", () => {
        ended = true;
        return new HttpResponse(null, { status: 204 });
      }),
    );

    render(<KioskPage />);
    await userEvent.click(
      await screen.findByRole("button", { name: /my attendance and leave/i }),
    );
    await userEvent.type(
      screen.getByRole("textbox", { name: /employee code/i }),
      "EMP-0001",
    );
    await userEvent.click(screen.getByRole("button", { name: /enter pin/i }));
    for (const d of ["4", "3", "2", "1"]) {
      await userEvent.click(screen.getByRole("button", { name: d }));
    }
    await userEvent.click(screen.getByRole("button", { name: /^open$/i }));

    expect(await screen.findByText("My last 7 days")).toBeInTheDocument();
    expect(screen.getByText("9 h 0 min")).toBeInTheDocument();
    expect(screen.getByText("12 days left")).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: /done/i }));

    expect(
      await screen.findByRole("button", { name: /^record$/i }),
    ).toBeInTheDocument();
    await vi.waitFor(() => expect(ended).toBe(true));
  });

  it("says so when the code and PIN do not match, and shows nothing", async () => {
    server.use(
      http.post("*/kiosk/self-service", () =>
        HttpResponse.json(
          { detail: "That code and PIN do not match." },
          { status: 401 },
        ),
      ),
    );

    render(<KioskPage />);
    await userEvent.click(
      await screen.findByRole("button", { name: /my attendance and leave/i }),
    );
    await userEvent.type(
      screen.getByRole("textbox", { name: /employee code/i }),
      "EMP-0001",
    );
    await userEvent.click(screen.getByRole("button", { name: /enter pin/i }));
    for (const d of ["0", "0", "0", "0"]) {
      await userEvent.click(screen.getByRole("button", { name: d }));
    }
    await userEvent.click(screen.getByRole("button", { name: /^open$/i }));

    expect(
      await screen.findByText("That code and PIN do not match."),
    ).toBeInTheDocument();
    expect(screen.queryByText("My last 7 days")).not.toBeInTheDocument();
  });
});

describe("locked kiosk mode", () => {
  it("swallows the long-press menu and the reload shortcut", async () => {
    render(<KioskPage />);
    await screen.findByRole("button", { name: /^record$/i });

    // The lock attaches in an effect, which React may run after the punch
    // screen is already on the page: waiting for the button alone raced it
    // and lost on a loaded CI runner. Wait for the lock itself.
    await waitFor(() =>
      expect(fireEvent.contextMenu(document.body)).toBe(false),
    );
    expect(fireEvent.keyDown(document.body, { key: "r", ctrlKey: true })).toBe(
      false,
    );
    expect(fireEvent.keyDown(document.body, { key: "a" })).toBe(true);
  });
});
