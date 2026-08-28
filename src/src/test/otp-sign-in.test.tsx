import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import OtpSignInPage from "@/app/(auth)/login/otp/page";

// The page reads tenant context via TanStack Query (useAuthHostContext), so it
// needs a client the way the real app provides one in providers.tsx.
function renderOtpPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={queryClient}>
      <OtpSignInPage />
    </QueryClientProvider>,
  );
}

/**
 * OTP sign-in end to end: phone entry, code entry, and the unavailable state
 * a tenant with no SMS gateway configured actually hits (SMS_DRIVER=log
 * reports itself unavailable, which is the default everywhere until a real
 * gateway is configured).
 *
 * Not covered here (needs the Pest suite): whether verifying a code actually
 * authenticates the browser's session. That was the real bug this feature
 * exposed — OtpController::verify() issued a token but never called
 * Auth::login(), so the SPA's cookie-only requests would 401 right after a
 * "successful" sign-in. See OtpLoginTest::"verifying a code actually
 * authenticates the session, not just the token".
 */
const push = vi.fn();
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push, back: vi.fn(), replace: vi.fn() }),
}));

const REQUEST_URL = "*/api/v1/auth/otp/request";
const VERIFY_URL = "*/api/v1/auth/otp/verify";

beforeEach(() => {
  push.mockReset();
  server.use(
    http.get(
      "*/sanctum/csrf-cookie",
      () => new HttpResponse(null, { status: 204 }),
    ),
  );
  localStorage.setItem("tenant", "acme");
});

function fillPhone(container: HTMLElement) {
  const input = container.querySelector("#phone") as HTMLInputElement;
  fireEvent.change(input, { target: { value: "911223344" } });
}

describe("OTP sign-in", () => {
  it("requests a code, then verifies it and signs in", async () => {
    server.use(
      http.post(REQUEST_URL, () =>
        HttpResponse.json({
          message: "If the account exists, a verification code has been sent.",
        }),
      ),
      http.post(VERIFY_URL, () => HttpResponse.json({ mfa_required: false })),
    );

    const { container } = renderOtpPage();
    fillPhone(container);
    fireEvent.click(screen.getByRole("button", { name: /send code/i }));

    expect(
      await screen.findByText(/verification code has been sent/i),
    ).toBeInTheDocument();

    const digitInputs = container.querySelectorAll(
      'input[inputmode="numeric"]',
    );
    "123456".split("").forEach((digit, i) => {
      fireEvent.change(digitInputs[i], { target: { value: digit } });
    });

    fireEvent.click(
      screen.getByRole("button", { name: /verify and sign in/i }),
    );

    await waitFor(() => expect(push).toHaveBeenCalledWith("/dashboard"));
  });

  it("routes to the MFA challenge when the account also has MFA enabled", async () => {
    server.use(
      http.post(REQUEST_URL, () =>
        HttpResponse.json({ message: "Code sent." }),
      ),
      http.post(VERIFY_URL, () => HttpResponse.json({ mfa_required: true })),
    );

    const { container } = renderOtpPage();
    fillPhone(container);
    fireEvent.click(screen.getByRole("button", { name: /send code/i }));
    await screen.findByText(/code sent/i);

    const digitInputs = container.querySelectorAll(
      'input[inputmode="numeric"]',
    );
    "654321".split("").forEach((digit, i) => {
      fireEvent.change(digitInputs[i], { target: { value: digit } });
    });
    fireEvent.click(
      screen.getByRole("button", { name: /verify and sign in/i }),
    );

    await waitFor(() => expect(push).toHaveBeenCalledWith("/login/mfa"));
    expect(sessionStorage.getItem("mfa_pending")).toBe("true");
  });

  it("shows an invalid-code message and clears the boxes on rejection", async () => {
    server.use(
      http.post(REQUEST_URL, () =>
        HttpResponse.json({ message: "Code sent." }),
      ),
      http.post(VERIFY_URL, () =>
        HttpResponse.json(
          {
            type: "https://ethr.et/errors/invalid-otp",
            title: "Invalid Code",
            status: 422,
            detail: "That verification code is invalid or has expired.",
          },
          { status: 422 },
        ),
      ),
    );

    const { container } = renderOtpPage();
    fillPhone(container);
    fireEvent.click(screen.getByRole("button", { name: /send code/i }));
    await screen.findByText(/code sent/i);

    const digitInputs = container.querySelectorAll(
      'input[inputmode="numeric"]',
    );
    "000000".split("").forEach((digit, i) => {
      fireEvent.change(digitInputs[i], { target: { value: digit } });
    });
    fireEvent.click(
      screen.getByRole("button", { name: /verify and sign in/i }),
    );

    expect(
      await screen.findByText(/invalid or has expired/i),
    ).toBeInTheDocument();
    expect(push).not.toHaveBeenCalled();
  });

  it("shows the unavailable state rather than a generic error when no gateway is configured", async () => {
    server.use(
      http.post(REQUEST_URL, () =>
        HttpResponse.json(
          {
            type: "https://ethr.et/errors/otp-unavailable",
            title: "OTP Unavailable",
            status: 503,
            detail: "Verification codes cannot be sent.",
          },
          { status: 503 },
        ),
      ),
    );

    const { container } = renderOtpPage();
    fillPhone(container);
    fireEvent.click(screen.getByRole("button", { name: /send code/i }));

    expect(
      await screen.findByText(/sms sign-in isn't available/i),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: /back to password sign-in/i }),
    ).toHaveAttribute("href", "/login");
  });

  it("does not reveal whether a phone number matches an account", async () => {
    // The backend answers identically for a known and unknown phone; the page
    // must not add a distinguishing branch of its own.
    let received: string | null = null;
    server.use(
      http.post(REQUEST_URL, async ({ request }) => {
        const body = (await request.json()) as { phone: string };
        received = body.phone;
        return HttpResponse.json({
          message: "If the account exists, a verification code has been sent.",
        });
      }),
    );

    const { container } = renderOtpPage();
    fillPhone(container);
    fireEvent.click(screen.getByRole("button", { name: /send code/i }));

    await waitFor(() => expect(received).toBe("+251911223344"));
    expect(
      await screen.findByText(/verification code has been sent/i),
    ).toBeInTheDocument();
  });
});
