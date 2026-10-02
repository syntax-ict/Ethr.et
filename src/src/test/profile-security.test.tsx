import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { toast } from "sonner";
import { server } from "./msw/server";
import SecurityPage from "@/app/(dashboard)/profile/security/page";

const push = vi.fn();
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push, replace: vi.fn(), back: vi.fn() }),
}));

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const SECRET = "JBSWY3DPEHPK3PXP";
/** What Google2FA::getQRCodeUrl() returns: the URI, not an image. */
const OTPAUTH = `otpauth://totp/ETHR:abebe%40example.com?secret=${SECRET}&issuer=ETHR&algorithm=SHA1&digits=6&period=30`;

function renderPage(mfaEnabled = false) {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: {
          public_id: "U1",
          name: "Abebe",
          email: "abebe@example.com",
          role: "employee",
          mfa_enabled: mfaEnabled,
        },
        tenant: {
          public_id: "T1",
          name: "Demo",
          timezone: "Africa/Addis_Ababa",
        },
        permissions: [],
      }),
    ),
    http.get("*/api/v1/auth/sessions", () => HttpResponse.json({ data: [] })),
    http.get("*/api/v1/auth/devices", () => HttpResponse.json({ data: [] })),
    http.post("*/api/v1/auth/mfa/setup", () =>
      HttpResponse.json({ secret: SECRET, qr_code_url: OTPAUTH }),
    ),
  );

  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={qc}>
      <SecurityPage />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.mocked(toast.success).mockClear();
  vi.mocked(toast.error).mockClear();
});

describe("Profile security — two-factor setup", () => {
  it("sends the setup secret back with the first code", async () => {
    // EnableMfaRequest requires `secret` and `code`; setup does not store the
    // secret. The page sent `{ code }` alone, so enabling always 422'd.
    let enabled: unknown;
    server.use(
      http.post("*/api/v1/auth/mfa/enable", async ({ request }) => {
        enabled = await request.json();
        return HttpResponse.json({
          message: "Two-factor authentication has been enabled.",
        });
      }),
    );

    renderPage();
    fireEvent.click(await screen.findByRole("button", { name: "Enable MFA" }));

    fireEvent.change(
      await screen.findByLabelText("2. Enter the 6-digit code from your app:"),
      { target: { value: "123456" } },
    );
    fireEvent.click(screen.getByRole("button", { name: "Verify & Enable" }));

    await waitFor(() =>
      expect(enabled).toEqual({ secret: SECRET, code: "123456" }),
    );
    expect(toast.success).toHaveBeenCalledWith(
      "Two-factor authentication enabled",
    );
  });

  it("draws the QR code instead of loading the otpauth URI as an image", async () => {
    renderPage();
    fireEvent.click(await screen.findByRole("button", { name: "Enable MFA" }));

    const qr = await screen.findByRole("img", { name: "MFA QR code" });
    expect(qr.tagName.toLowerCase()).toBe("svg");
    expect(document.querySelector(`img[src^="otpauth:"]`)).toBeNull();
  });

  it("names the code field in the disable dialog", async () => {
    renderPage(true);
    fireEvent.click(await screen.findByRole("button", { name: "Disable" }));

    expect(
      await screen.findByRole("textbox", {
        name: "Enter your current authenticator code to confirm:",
      }),
    ).toBeInTheDocument();
  });
});

describe("Profile security — organization requires two-factor (audit N6)", () => {
  afterEach(() => {
    window.history.replaceState(null, "", "/");
  });

  it("explains why the user was sent here when enrolment is owed", async () => {
    window.history.replaceState(null, "", "/profile/security?mfa=required");
    renderPage(false);

    expect(
      await screen.findByText("Two-factor authentication is required"),
    ).toBeInTheDocument();
  });

  it("offers the way back once two-factor is set up", async () => {
    window.history.replaceState(null, "", "/profile/security?mfa=required");
    renderPage(true);

    fireEvent.click(
      await screen.findByRole("button", { name: "Continue to ETHR" }),
    );
    expect(push).toHaveBeenCalledWith("/dashboard");
    expect(
      screen.queryByText("Two-factor authentication is required"),
    ).not.toBeInTheDocument();
  });

  it("shows no notice to a user who came here on their own", async () => {
    renderPage(false);

    expect(
      await screen.findByRole("button", { name: "Enable MFA" }),
    ).toBeInTheDocument();
    expect(
      screen.queryByText("Two-factor authentication is required"),
    ).not.toBeInTheDocument();
  });
});
