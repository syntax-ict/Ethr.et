import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { AuthLayoutClient } from "@/app/(auth)/auth-layout-client";
import { LoginForm } from "@/app/(auth)/login/login-form";
import { ForgotForm } from "@/app/(auth)/login/forgot/forgot-form";
import type { useAuthHostContext } from "@/lib/auth/use-auth-host-context";

/**
 * The organisation's front door: `ethr.et/{slug}`, `/login?org={slug}`, or
 * its own domain, which a production build cannot classify until
 * `/api/v1/auth/tenant-context` answers. The sign-in form draws inside the
 * two-panel layout first, and a person can type in that window. When the
 * context arrives the page becomes the organisation portal, a different
 * element tree, so React mounted the form again and emptied the email and
 * password already typed.
 */
type HostCtx = ReturnType<typeof useAuthHostContext>;

const hostContextValue = vi.fn<() => HostCtx>();
vi.mock("@/lib/auth/use-auth-host-context", () => ({
  useAuthHostContext: () => hostContextValue(),
}));

const location = { pathname: "/login", search: "" };
vi.mock("next/navigation", () => ({
  usePathname: () => location.pathname,
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), back: vi.fn() }),
  useSearchParams: () => new URLSearchParams(location.search),
}));

// The production apex: the build cannot classify the host, the address names
// the organisation, and its context is still on the way.
const arriving: HostCtx = {
  context: "unknown",
  tenantSlug: "acme",
  tenantName: null,
  tenantLogoPath: null,
  tenantBrand: null,
  tenantLanding: null,
  tenantSignIn: null,
  tenantDefaultLocale: null,
  onLanding: true,
  carryOrganisation: null,
  tenantRequest: null,
  tenantContextLoading: true,
  tenantLookupError: null,
  tenantFieldVisible: false,
};

const arrived: HostCtx = {
  ...arriving,
  context: "tenant",
  tenantName: "Acme Manufacturing",
  tenantBrand: {
    logo_url: null,
    primary_color: "#0f766e",
    accent_color: null,
  },
  tenantLanding: {
    headline: "Welcome to Acme",
    message: null,
    support_email: null,
    support_phone: null,
    background: "brand",
  },
  tenantSignIn: { sso: false, otp: false },
  tenantDefaultLocale: "en",
  carryOrganisation: "acme",
  tenantContextLoading: false,
};

// A verified custom domain: nothing in the address names the organisation,
// so the field shows until the host answers for it.
const customDomainArriving: HostCtx = {
  ...arriving,
  tenantSlug: null,
  onLanding: false,
  tenantFieldVisible: true,
};

const customDomainArrived: HostCtx = {
  ...arrived,
  tenantSlug: "acme",
  onLanding: false,
  carryOrganisation: null,
  tenantRequest: { mode: "host" },
};

const queryClient = () =>
  new QueryClient({ defaultOptions: { queries: { retry: false } } });

function page(client: QueryClient, form: React.ReactNode = <LoginForm />) {
  return (
    <QueryClientProvider client={client}>
      <AuthLayoutClient>{form}</AuthLayoutClient>
    </QueryClientProvider>
  );
}

function at(pathname: string, search = "") {
  location.pathname = pathname;
  location.search = search;
  window.history.replaceState(
    {},
    "",
    `${pathname}${search ? `?${search}` : ""}`,
  );
}

async function typeThenArrive(before: HostCtx, after: HostCtx) {
  const client = queryClient();
  hostContextValue.mockReturnValue(before);

  const { rerender } = render(page(client));
  // Nothing of the organisation's yet: the two-panel layout.
  expect(screen.queryByText("Welcome to Acme")).toBeNull();

  await userEvent.type(screen.getByLabelText(/Email/), "abebe@acme.et");
  await userEvent.type(
    screen.getByLabelText(/^Password/),
    "Correct-Horse-Battery-9",
  );

  hostContextValue.mockReturnValue(after);
  rerender(page(client));

  // The portal drew, so the context really did arrive and the shell changed.
  expect(screen.getAllByText("Welcome to Acme").length).toBeGreaterThan(0);
}

beforeEach(() => {
  hostContextValue.mockReset();
  localStorage.clear();
  localStorage.setItem("locale", "en");
});

describe("the organisation's front door when its context arrives", () => {
  it("keeps the email and password typed at ethr.et/{slug}", async () => {
    at("/acme");
    await typeThenArrive(arriving, arrived);

    expect(screen.getByLabelText(/Email/)).toHaveValue("abebe@acme.et");
    expect(screen.getByLabelText(/^Password/)).toHaveValue(
      "Correct-Horse-Battery-9",
    );
  });

  it("keeps them at /login?org={slug}", async () => {
    at("/login", "org=acme");
    await typeThenArrive(arriving, arrived);

    expect(screen.getByLabelText(/Email/)).toHaveValue("abebe@acme.et");
    expect(screen.getByLabelText(/^Password/)).toHaveValue(
      "Correct-Horse-Battery-9",
    );
  });

  it("keeps them on the organisation's own domain", async () => {
    at("/login");
    await typeThenArrive(customDomainArriving, customDomainArrived);

    expect(screen.getByLabelText(/Email/)).toHaveValue("abebe@acme.et");
    expect(screen.getByLabelText(/^Password/)).toHaveValue(
      "Correct-Horse-Battery-9",
    );
  });

  it("keeps the person in the field they were typing in", async () => {
    at("/acme");
    await typeThenArrive(arriving, arrived);

    expect(screen.getByLabelText(/^Password/)).toHaveFocus();
    // And the next key lands after what was already there.
    await userEvent.keyboard("!");
    expect(screen.getByLabelText(/^Password/)).toHaveValue(
      "Correct-Horse-Battery-9!",
    );
  });

  it("signs in with what was typed before the context arrived", async () => {
    let sent: unknown = null;
    server.use(
      http.get(
        "*/sanctum/csrf-cookie",
        () => new HttpResponse(null, { status: 204 }),
      ),
      http.post("*/auth/login", async ({ request }) => {
        sent = await request.json();
        return HttpResponse.json({ mfa_required: true });
      }),
    );
    at("/acme");
    await typeThenArrive(arriving, arrived);

    // The form's own state took the values, not only the inputs.
    await userEvent.click(screen.getByRole("button", { name: /^Sign in$/i }));
    await waitFor(() =>
      expect(sent).toMatchObject({
        email: "abebe@acme.et",
        password: "Correct-Horse-Battery-9",
      }),
    );
  });

  it("starts the next page empty when a navigation also changes the layout", async () => {
    at("/acme");
    const client = queryClient();
    hostContextValue.mockReturnValue(arrived);
    const { rerender } = render(page(client));
    await userEvent.type(screen.getByLabelText(/Email/), "abebe@acme.et");

    // "Forgot password?" from the portal: the two-panel layout, another page.
    at("/login/forgot", "org=acme");
    hostContextValue.mockReturnValue({ ...arrived, onLanding: false });
    rerender(page(client, <ForgotForm />));

    // The portal's hero card is gone: the shell really did change.
    expect(document.getElementById("sign-in")).toBeNull();
    expect(screen.getByLabelText(/Email/)).toHaveValue("");
  });
});
