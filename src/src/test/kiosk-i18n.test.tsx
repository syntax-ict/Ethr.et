import { afterEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import KioskPage from "@/app/kiosk/page";
import { SearchInput } from "@/components/shared/search-input";

/**
 * The kiosk page never called useT(): every string on it was hardcoded English,
 * and the i18n gate only inspects t() calls, so nothing flagged it. It is the
 * one screen shared by every employee at a site, including those who read only
 * Amharic.
 */
describe("kiosk page translations", () => {
  afterEach(() => {
    localStorage.setItem("locale", "en");
    localStorage.removeItem("kiosk_token");
  });

  it("renders the setup screen in Amharic", async () => {
    localStorage.setItem("locale", "am");
    render(<KioskPage />);

    expect(await screen.findByText("የኪዮስክ ማዋቀሪያ")).toBeInTheDocument();
    expect(
      screen.getByPlaceholderText("የኪዮስክ ቶከኑን እዚህ ይለጥፉ"),
    ).toBeInTheDocument();
    expect(screen.queryByText("Kiosk Setup")).not.toBeInTheDocument();
  });

  it("renders the setup screen in English", async () => {
    localStorage.setItem("locale", "en");
    render(<KioskPage />);

    expect(await screen.findByText("Kiosk Setup")).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: /Activate Kiosk/ }),
    ).toBeInTheDocument();
  });

  it("shows the invalid-token message in the reader's language", async () => {
    // The error is held as a key and translated at render, so it is still
    // correct if the language changes while the message is on screen.
    server.use(
      http.post("*/kiosk/authenticate", () =>
        HttpResponse.json({ detail: "nope" }, { status: 401 }),
      ),
    );
    localStorage.setItem("locale", "am");
    render(<KioskPage />);

    await userEvent.type(
      await screen.findByPlaceholderText("የኪዮስክ ቶከኑን እዚህ ይለጥፉ"),
      "bad-token",
    );
    await userEvent.click(screen.getByRole("button", { name: /ኪዮስኩን አንቃ/ }));

    expect(
      await screen.findByText("ልክ ያልሆነ ወይም የቦዘነ የኪዮስክ ቶከን"),
    ).toBeInTheDocument();
  });
});

describe("the kiosk keypad", () => {
  afterEach(() => {
    localStorage.removeItem("kiosk_token");
  });

  it("accepts an employee code with letters and a dash, like EMP-0001", async () => {
    // The pad took digits only, at most eight. Employee codes are any string
    // up to 30 characters, so most employees could not clock in (audit N61).
    let sentCode: unknown = null;
    server.use(
      http.post("*/kiosk/authenticate", () =>
        HttpResponse.json({
          session: { public_id: "K1", name: "Front desk", branch: null },
          tenant: { name: "Acme", subdomain: "acme", logo_path: null },
          settings: { pin_required: false, auto_reset_seconds: 4 },
        }),
      ),
      http.post("*/kiosk/check-in", async ({ request }) => {
        sentCode = ((await request.json()) as { employee_code: string })
          .employee_code;
        return HttpResponse.json(
          { employee_name: "Abebe Kebede", was_duplicate: false },
          { status: 201 },
        );
      }),
    );
    localStorage.setItem("locale", "en");
    render(<KioskPage />);

    await userEvent.type(
      await screen.findByPlaceholderText(/paste kiosk token/i),
      "good-token",
    );
    await userEvent.click(
      screen.getByRole("button", { name: /Activate Kiosk/ }),
    );

    await userEvent.type(
      await screen.findByRole("textbox", { name: /employee code/i }),
      "EMP-0001",
    );
    const checkIn = screen.getAllByRole("button", { name: /check in/i });
    await userEvent.click(checkIn[checkIn.length - 1]);

    await vi.waitFor(() => expect(sentCode).toBe("EMP-0001"));
  });
});

describe("SearchInput translations", () => {
  afterEach(() => localStorage.setItem("locale", "en"));

  it("translates its default placeholder and the clear button's label", async () => {
    localStorage.setItem("locale", "am");
    render(<SearchInput value="abc" onChange={() => {}} />);

    expect(await screen.findByPlaceholderText("ፈልግ...")).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: "ፍለጋውን አጽዳ" }),
    ).toBeInTheDocument();
  });

  it("keeps a placeholder the caller passes", async () => {
    localStorage.setItem("locale", "am");
    render(
      <SearchInput
        value=""
        onChange={() => {}}
        placeholder="Find an employee"
      />,
    );

    expect(
      await screen.findByPlaceholderText("Find an employee"),
    ).toBeInTheDocument();
  });
});
