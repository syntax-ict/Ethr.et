import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { rules } from "@/lib/forms/rules";
import { OtpForm } from "@/app/(auth)/login/otp/otp-form";
import type { useAuthHostContext } from "@/lib/auth/use-auth-host-context";

/**
 * Text-message codes reach mobiles on both networks — Ethio Telecom (9…) and
 * Safaricom Ethiopia (7…) — and never a landline. The general phone rule used
 * by every other form still accepts landlines.
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

beforeEach(() => {
  requestOtp.mockReset();
  requestOtp.mockResolvedValue({ message: "sent" });
  localStorage.setItem("locale", "en");
  hostContextValue.mockReturnValue({
    context: "tenant",
    tenantSlug: "acme",
    tenantName: "Acme",
    tenantLogoPath: null,
    tenantBrand: null,
    tenantLanding: null,
    tenantSignIn: { sso: false, otp: true, email_code: false },
    tenantDefaultLocale: null,
    onLanding: false,
    carryOrganisation: null,
    tenantRequest: null,
    tenantContextLoading: false,
    tenantLookupError: null,
    tenantFieldVisible: false,
  });
});

describe("the mobile rule", () => {
  it.each(["0911223344", "+251911223344", "0711223344", "251711223344"])(
    "accepts %s",
    (n) => expect(rules.mobilePhone().safeParse(n).success).toBe(true),
  );

  it("refuses a landline, which the general phone rule still accepts", () => {
    expect(rules.mobilePhone().safeParse("0111223344").success).toBe(false);
    expect(rules.phone().safeParse("0111223344").success).toBe(true);
  });
});

function renderForm() {
  const client = new QueryClient();
  return render(
    <QueryClientProvider client={client}>
      <OtpForm />
    </QueryClientProvider>,
  );
}

describe("the text-message code page", () => {
  it("refuses a landline with a clear message instead of sending", async () => {
    const user = userEvent.setup();
    const { container } = renderForm();

    await user.type(
      container.querySelector("#phone") as HTMLInputElement,
      "111223344",
    );
    await user.click(screen.getByRole("button", { name: /send code/i }));

    expect(
      await screen.findByText(/only to mobile numbers/i),
    ).toBeInTheDocument();
    expect(requestOtp).not.toHaveBeenCalled();
  });

  it("sends to a Safaricom number", async () => {
    const user = userEvent.setup();
    const { container } = renderForm();

    await user.type(
      container.querySelector("#phone") as HTMLInputElement,
      "711223344",
    );
    await user.click(screen.getByRole("button", { name: /send code/i }));

    expect(requestOtp).toHaveBeenCalledWith(
      expect.objectContaining({ tenant: "acme" }),
    );
  });

  it("tells people both networks are accepted", () => {
    renderForm();

    expect(
      screen.getByPlaceholderText("9XXXXXXXX / 7XXXXXXXX"),
    ).toBeInTheDocument();
  });
});
