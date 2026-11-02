import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import KioskPage from "@/app/kiosk/page";
import { fullCode } from "@/features/kiosk/kiosk-code";

/**
 * Typing the number alone at the kiosk: the pad has only digits, and the
 * screen shows the full code the number stands for before Record.
 */
const EMP = { prefix: "EMP-", width: 4, suffix: "" };

describe("fullCode", () => {
  it("pads the number into the organisation format", () => {
    expect(fullCode("12", EMP)).toBe("EMP-0012");
    expect(fullCode("0012", EMP)).toBe("EMP-0012");
    expect(fullCode("7", { prefix: "MIN-", width: 4, suffix: "/A" })).toBe(
      "MIN-0007/A",
    );
  });

  it("leaves anything but a bare number alone", () => {
    expect(fullCode("EMP-0012", EMP)).toBeNull();
    expect(fullCode("0", EMP)).toBeNull();
    expect(fullCode("123456789", EMP)).toBeNull();
    expect(fullCode("12", null)).toBeNull();
  });
});

describe("the kiosk code field", () => {
  let sent: string | null = null;

  beforeEach(() => {
    sent = null;
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
            sound: "off",
            code_format: EMP,
          },
        }),
      ),
      http.post("*/kiosk/check-in", async ({ request }) => {
        sent = ((await request.json()) as { employee_code: string })
          .employee_code;
        return HttpResponse.json(
          {
            public_id: "REC1",
            punch: "check_in",
            check_in: "2026-11-02T05:30:00Z",
            was_duplicate: false,
            employee_name: "Abebe Kebede",
            employee: {
              name: "Abebe Kebede",
              name_am: null,
              position: null,
              photo_url: null,
            },
          },
          { status: 201 },
        );
      }),
    );
  });

  afterEach(() => {
    localStorage.removeItem("kiosk_token");
  });

  it("says how to type the number, then shows the full code it stands for", async () => {
    render(<KioskPage />);
    expect(
      await screen.findByText("Type only your number: 12 for EMP-0012"),
    ).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: "1" }));
    await userEvent.click(screen.getByRole("button", { name: "2" }));

    expect(screen.getByText("Your code: EMP-0012")).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: /^record$/i }));
    expect(
      await screen.findByText("Welcome, Abebe Kebede"),
    ).toBeInTheDocument();
    // The server resolves the number, so a terminal with an old format
    // still punches the right person.
    expect(sent).toBe("12");
  });

  it("keeps the tablet keyboard closed until ABC is pressed", async () => {
    render(<KioskPage />);
    const field = await screen.findByRole("textbox", {
      name: /employee code/i,
    });
    expect(field).toHaveAttribute("inputmode", "none");

    await userEvent.click(
      screen.getByRole("button", { name: /code with letters/i }),
    );

    expect(field).toHaveAttribute("inputmode", "text");
    expect(
      screen.queryByRole("button", { name: /code with letters/i }),
    ).not.toBeInTheDocument();
  });
});
