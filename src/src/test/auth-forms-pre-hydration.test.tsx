import { beforeEach, describe, expect, it, vi } from "vitest";
import { renderToString } from "react-dom/server";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { LoginForm } from "@/app/(auth)/login/login-form";
import { RegisterForm } from "@/app/(auth)/register/register-form";
import { ActivateForm } from "@/app/(auth)/login/activate/activate-form";
import { ChangePasswordForm } from "@/app/(auth)/login/change-password/change-password-form";
import { FindOrganisationForm } from "@/app/(auth)/login/find/find-organisation-form";
import { ForgotForm } from "@/app/(auth)/login/forgot/forgot-form";
import { MfaForm } from "@/app/(auth)/login/mfa/mfa-form";
import { OtpForm } from "@/app/(auth)/login/otp/otp-form";
import { ResetPasswordForm } from "@/app/(auth)/login/reset/reset-form";
import type { useAuthHostContext } from "@/lib/auth/use-auth-host-context";

/**
 * A person who presses a sign-in button before the page has hydrated (slow
 * connection) gets the browser's own submit. The forms had no `method`, so
 * that was a GET, and every field, passwords included, landed in the address.
 * Measured in Chromium against the static export with scripts blocked.
 *
 * Two lines of defence, both pinned here:
 *   1. every submit control is disabled in the prerendered HTML, which also
 *      stops Enter-key implicit submission (`useHydrated()` is false there);
 *   2. every form says `method="post"`, so a submit that still gets through
 *      carries its fields in the body, never in the address.
 *
 * "Before mount" is the server markup: `renderToString`, where `useHydrated()`
 * is false, parsed into a detached element.
 */
type HostCtx = ReturnType<typeof useAuthHostContext>;

const hostContextValue = vi.fn<() => HostCtx>();
vi.mock("@/lib/auth/use-auth-host-context", () => ({
  useAuthHostContext: () => hostContextValue(),
}));

let search = "";
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), back: vi.fn() }),
  usePathname: () => "/login",
  useSearchParams: () => new URLSearchParams(search),
}));

vi.mock("@/features/auth/sign-in", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/features/auth/sign-in")>()),
  prepareCsrfCookie: vi.fn().mockResolvedValue(undefined),
  requestOtp: vi.fn().mockResolvedValue({ message: "Code sent" }),
}));

const generic: HostCtx = {
  context: "unknown",
  tenantSlug: null,
  tenantName: null,
  tenantLogoPath: null,
  tenantBrand: null,
  tenantLanding: null,
  tenantSignIn: null,
  tenantDefaultLocale: null,
  tenantBillingOnly: false,
  onLanding: false,
  carryOrganisation: null,
  tenantRequest: null,
  tenantContextLoading: false,
  tenantLookupError: null,
  tenantFieldVisible: true,
};

beforeEach(() => {
  hostContextValue.mockReset();
  hostContextValue.mockReturnValue(generic);
  search = "token=t&email=a%40b.c&tenant=mersa-city";
  localStorage.setItem("locale", "en");
  sessionStorage.setItem("mfa_pending", "true");
});

function withQuery(ui: React.ReactElement) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return <QueryClientProvider client={client}>{ui}</QueryClientProvider>;
}

/** The prerendered markup of a page, in a detached element. */
function prerendered(ui: React.ReactElement): HTMLElement {
  const host = document.createElement("div");
  host.innerHTML = renderToString(withQuery(ui));
  return host;
}

/**
 * Every control a browser would submit the form with. A `<button>` with no
 * `type` is a submit button, so it is counted.
 */
const SUBMIT_CONTROLS =
  'button[type="submit"], button:not([type]), input[type="submit"], input[type="image"]';

/** Returns how many submit controls it checked, so a caller can rule out none. */
function expectNoUsableSubmit(root: HTMLElement): number {
  const forms = [...root.querySelectorAll("form")];
  expect(forms.length).toBeGreaterThan(0);
  let checked = 0;
  for (const form of forms) {
    for (const control of form.querySelectorAll<HTMLElement>(SUBMIT_CONTROLS)) {
      checked += 1;
      if (!control.hasAttribute("disabled")) {
        throw new Error(
          `An enabled submit control before hydration: ${control.outerHTML.slice(0, 160)}`,
        );
      }
    }
  }
  return checked;
}

function expectPost(root: HTMLElement) {
  const forms = [...root.querySelectorAll("form")];
  expect(forms).toHaveLength(1);
  expect(forms[0].getAttribute("method")).toBe("post");
}

function submitButton(container: HTMLElement) {
  return container.querySelector<HTMLButtonElement>(
    'form button[type="submit"]',
  )!;
}

// The third column says the prerendered form has a submit control, which must
// then be disabled; it is false only for register's first step, which carries
// none (so nothing can be submitted before hydration). The fourth says the
// button is enabled by hydration alone: MFA's also waits for a full code.
const forms: [string, () => React.ReactElement, boolean, boolean][] = [
  ["login", () => <LoginForm />, true, true],
  [
    "register (first step has no submit control)",
    () => <RegisterForm />,
    false,
    false,
  ],
  ["activate", () => <ActivateForm />, true, true],
  ["change password", () => <ChangePasswordForm />, true, true],
  ["find my organisation", () => <FindOrganisationForm />, true, true],
  ["forgot password", () => <ForgotForm />, true, true],
  ["MFA (button also waits for a full code)", () => <MfaForm />, true, false],
  ["text-code sign-in, address step", () => <OtpForm />, true, true],
  ["reset password", () => <ResetPasswordForm />, true, true],
];

describe.each(forms)(
  "the %s form",
  (_name, page, hasSubmitBeforeHydration, liftsOnMount) => {
    it("posts, never gets, and has no usable submit before hydration", () => {
      const root = prerendered(page());

      expectPost(root);
      const checked = expectNoUsableSubmit(root);
      if (hasSubmitBeforeHydration) {
        expect(checked).toBeGreaterThan(0);
      }
    });

    if (liftsOnMount) {
      it("enables its submit button once the page has hydrated", async () => {
        const { container } = render(withQuery(page()));

        await waitFor(() => {
          expect(submitButton(container)).toBeEnabled();
        });
      });
    }
  },
);

describe("the text-code sign-in code step", () => {
  it("posts as well", async () => {
    hostContextValue.mockReturnValue({
      ...generic,
      context: "tenant",
      tenantSlug: "mersa-city",
      tenantName: "Mersa City",
      tenantFieldVisible: false,
    });
    const { container } = render(withQuery(<OtpForm />));

    await waitFor(() => expect(submitButton(container)).toBeEnabled());
    fireEvent.change(screen.getByPlaceholderText("name@example.com"), {
      target: { value: "a@example.com" },
    });
    fireEvent.click(submitButton(container));

    await waitFor(() => {
      expect(screen.getByText("Code sent")).toBeInTheDocument();
    });
    expectPost(container);
  });
});

describe("the sign-in form and the address", () => {
  it("never reads a password from the URL", () => {
    search = "identifier=a%40b.c&password=leaked";
    window.history.replaceState(
      null,
      "",
      "/login?identifier=a%40b.c&password=leaked",
    );

    render(withQuery(<LoginForm />));

    const password = document.querySelector<HTMLInputElement>("#password")!;
    expect(password.value).toBe("");
    window.history.replaceState(null, "", "/");
  });
});
