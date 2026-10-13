import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { OtpForm } from "@/app/(auth)/login/otp/otp-form";
import type { useAuthHostContext } from "@/lib/auth/use-auth-host-context";

/**
 * Sign-in codes by email. ETHR has no SMS gateway yet, so an organisation's
 * code page offers email alone; once SMS is configured it offers both, and the
 * person chooses. The request carries `email` or `phone`, never both.
 */
type HostCtx = ReturnType<typeof useAuthHostContext>;

const hostContextValue = vi.fn<() => HostCtx>();
vi.mock("@/lib/auth/use-auth-host-context", () => ({
  useAuthHostContext: () => hostContextValue(),
}));

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), back: vi.fn() }),
}));

const requestOtp = vi.fn();
vi.mock("@/features/auth/sign-in", () => ({
  prepareCsrfCookie: vi.fn().mockResolvedValue(undefined),
  requestOtp: (payload: unknown) => requestOtp(payload),
  verifyOtp: vi.fn(),
}));

function organisation(signIn: HostCtx["tenantSignIn"]): HostCtx {
  return {
    context: "tenant",
    tenantSlug: "acme",
    tenantName: "Acme",
    tenantLogoPath: null,
    tenantBrand: null,
    tenantLanding: null,
    tenantSignIn: signIn,
    tenantDefaultLocale: null,
    onLanding: false,
    carryOrganisation: null,
    tenantRequest: null,
    tenantContextLoading: false,
    tenantLookupError: null,
    tenantFieldVisible: false,
  };
}

function renderForm() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <OtpForm />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  hostContextValue.mockReset();
  requestOtp.mockReset();
  requestOtp.mockResolvedValue({ message: "sent" });
  localStorage.setItem("locale", "en");
});

describe("the one-time code page", () => {
  it("asks for an email address when email is the only channel", async () => {
    hostContextValue.mockReturnValue(
      organisation({ sso: false, otp: false, email_code: true }),
    );
    const user = userEvent.setup();

    renderForm();

    expect(screen.queryByRole("radiogroup")).not.toBeInTheDocument();
    expect(screen.queryByLabelText(/phone/i)).not.toBeInTheDocument();

    await user.type(screen.getByLabelText(/email/i), "abebe@example.com");
    await user.click(screen.getByRole("button", { name: /send code/i }));

    await waitFor(() =>
      expect(requestOtp).toHaveBeenCalledWith({
        email: "abebe@example.com",
        tenant: "acme",
      }),
    );
  });

  it("refuses an address that is not an email before asking the server", async () => {
    hostContextValue.mockReturnValue(
      organisation({ sso: false, otp: false, email_code: true }),
    );
    const user = userEvent.setup();

    renderForm();

    await user.type(screen.getByLabelText(/email/i), "not-an-email");
    await user.click(screen.getByRole("button", { name: /send code/i }));

    expect(
      await screen.findByText(/enter a valid email address/i),
    ).toBeInTheDocument();
    expect(requestOtp).not.toHaveBeenCalled();
  });

  it("lets the person choose once text messages work too", async () => {
    hostContextValue.mockReturnValue(
      organisation({ sso: false, otp: true, email_code: true }),
    );
    const user = userEvent.setup();

    renderForm();

    expect(screen.getByRole("radio", { name: /email/i })).toHaveAttribute(
      "aria-checked",
      "true",
    );

    await user.click(screen.getByRole("radio", { name: /text message/i }));

    expect(screen.getByLabelText(/phone/i)).toBeInTheDocument();
    expect(screen.queryByLabelText(/^email/i)).not.toBeInTheDocument();
  });
});
