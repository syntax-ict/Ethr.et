import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { PasswordPolicyCard } from "@/features/settings/components/password-policy-card";
import { ActivationCodeCard } from "@/features/settings/components/activation-code-card";
import { LoginIdentifiersCard } from "@/features/settings/components/login-identifiers-card";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

function renderCard(node: ReactNode) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={queryClient}>{node}</QueryClientProvider>,
  );
}

const POLICY = {
  min_length: 8,
  require_uppercase: false,
  require_lowercase: false,
  require_number: false,
  require_symbol: false,
};

/** Captures the body of `PUT /settings`. */
function captureSettingsPut() {
  const sent: { body: unknown } = { body: null };
  server.use(
    http.put("*/api/v1/settings", async ({ request }) => {
      sent.body = await request.json();
      return HttpResponse.json({ message: "Settings updated", settings: {} });
    }),
  );
  return sent;
}

describe("<PasswordPolicyCard>", () => {
  it("sends the whole policy when one rule changes, and no expiry", async () => {
    const sent = captureSettingsPut();
    const user = userEvent.setup();
    renderCard(<PasswordPolicyCard policy={POLICY} />);

    const save = screen.getByRole("button", { name: "Save password rules" });
    expect(save).toBeDisabled();

    await user.click(
      screen.getByRole("switch", { name: "Require an uppercase letter" }),
    );
    await user.click(save);

    await waitFor(() =>
      expect(sent.body).toEqual({
        settings: {
          password_policy: {
            min_length: 8,
            require_uppercase: true,
            require_lowercase: false,
            require_number: false,
            require_symbol: false,
          },
        },
      }),
    );
  });

  it("sends an edited length as a number", async () => {
    const sent = captureSettingsPut();
    const user = userEvent.setup();
    renderCard(<PasswordPolicyCard policy={POLICY} />);

    const length = screen.getByLabelText("Minimum length");
    await user.clear(length);
    await user.type(length, "14");
    await user.click(
      screen.getByRole("button", { name: "Save password rules" }),
    );

    await waitFor(() => expect(sent.body).not.toBeNull());
    expect(
      (sent.body as { settings: { password_policy: { min_length: unknown } } })
        .settings.password_policy.min_length,
    ).toBe(14);
  });

  it.each(["7", "129", "", "9.5"])(
    "refuses a length of %j and sends nothing",
    async (value) => {
      const sent = captureSettingsPut();
      const user = userEvent.setup();
      renderCard(<PasswordPolicyCard policy={POLICY} />);

      const length = screen.getByLabelText("Minimum length");
      await user.clear(length);
      if (value) await user.type(length, value);

      expect(
        screen.getByText("Enter a whole number from 8 to 128."),
      ).toBeInTheDocument();
      expect(length).toHaveAttribute("aria-invalid", "true");
      expect(
        screen.getByRole("button", { name: "Save password rules" }),
      ).toBeDisabled();
      expect(sent.body).toBeNull();
    },
  );

  it("shows the server's message under the length when it refuses", async () => {
    server.use(
      http.put("*/api/v1/settings", () =>
        HttpResponse.json(
          {
            message: "The given data was invalid.",
            errors: {
              "settings.password_policy.min_length": ["Too short for us."],
            },
          },
          { status: 422 },
        ),
      ),
    );
    const user = userEvent.setup();
    renderCard(<PasswordPolicyCard policy={POLICY} />);

    await user.click(screen.getByRole("switch", { name: "Require a number" }));
    await user.click(
      screen.getByRole("button", { name: "Save password rules" }),
    );

    expect(await screen.findByText("Too short for us.")).toBeInTheDocument();
  });

  it("says a stricter rule applies to the next password", () => {
    renderCard(<PasswordPolicyCard policy={POLICY} />);
    expect(
      screen.getByText(/applies to the next password someone sets/),
    ).toBeInTheDocument();
    expect(screen.queryByText(/expiry/i)).not.toBeInTheDocument();
  });
});

describe("<ActivationCodeCard>", () => {
  it("shows the saved lifetime and sends an edited one as a number", async () => {
    const sent = captureSettingsPut();
    const user = userEvent.setup();
    renderCard(<ActivationCodeCard hours={24} />);

    const hours = screen.getByLabelText("Code lifetime (hours)");
    expect(hours).toHaveValue(24);
    const save = screen.getByRole("button", { name: "Save code lifetime" });
    expect(save).toBeDisabled();

    await user.clear(hours);
    await user.type(hours, "48");
    await user.click(save);

    await waitFor(() =>
      expect(sent.body).toEqual({ settings: { activation_code_hours: 48 } }),
    );
  });

  it.each(["0", "73", "168", "", "1.5"])(
    "refuses %j and sends nothing",
    async (value) => {
      const sent = captureSettingsPut();
      const user = userEvent.setup();
      renderCard(<ActivationCodeCard hours={24} />);

      const hours = screen.getByLabelText("Code lifetime (hours)");
      await user.clear(hours);
      if (value) await user.type(hours, value);

      expect(
        screen.getByText("Enter a whole number from 1 to 72."),
      ).toBeInTheDocument();
      expect(
        screen.getByRole("button", { name: "Save code lifetime" }),
      ).toBeDisabled();
      expect(sent.body).toBeNull();
    },
  );

  it("says a change applies to codes issued from now on", () => {
    renderCard(<ActivationCodeCard hours={24} />);
    expect(
      screen.getByText(/applies to codes issued from now on/),
    ).toBeInTheDocument();
  });
});

describe("<LoginIdentifiersCard>", () => {
  const ALL = ["email", "phone", "employee_code", "username"];

  function policy(identifiers: string[]) {
    server.use(
      http.get("*/api/v1/onboarding/access", () =>
        HttpResponse.json({
          available: ALL,
          login_identifiers: identifiers,
          role_defaults: { employee: "phone" },
        }),
      ),
    );
  }

  function captureAccessPut() {
    const sent: { body: unknown } = { body: null };
    server.use(
      http.put("*/api/v1/onboarding/access", async ({ request }) => {
        sent.body = await request.json();
        return HttpResponse.json({
          available: ALL,
          login_identifiers: (sent.body as { login_identifiers: string[] })
            .login_identifiers,
          role_defaults: {},
        });
      }),
    );
    return sent;
  }

  it("adds a method, keeping the saved order, and sends no role defaults", async () => {
    policy(["phone", "email"]);
    const sent = captureAccessPut();
    const user = userEvent.setup();
    renderCard(<LoginIdentifiersCard />);

    await user.click(await screen.findByLabelText("Username"));
    await user.click(
      screen.getByRole("button", { name: "Save sign-in methods" }),
    );

    await waitFor(() =>
      expect(sent.body).toEqual({
        login_identifiers: ["phone", "email", "username"],
      }),
    );
  });

  it("will not save with none chosen", async () => {
    policy(["email"]);
    const sent = captureAccessPut();
    const user = userEvent.setup();
    renderCard(<LoginIdentifiersCard />);

    await user.click(await screen.findByLabelText("Email address"));

    expect(
      screen.getByText("Choose at least one sign-in method."),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: "Save sign-in methods" }),
    ).toBeDisabled();
    expect(sent.body).toBeNull();
  });

  it("asks before removing a method people use, and saves on yes", async () => {
    policy(["email", "phone"]);
    const sent = captureAccessPut();
    const user = userEvent.setup();
    renderCard(<LoginIdentifiersCard />);

    await user.click(await screen.findByLabelText("Mobile number"));
    await user.click(
      screen.getByRole("button", { name: "Save sign-in methods" }),
    );

    expect(
      await screen.findByText("Remove a sign-in method?"),
    ).toBeInTheDocument();
    expect(screen.getByText(/Mobile number will have to use/)).toBeVisible();
    expect(sent.body).toBeNull();

    await user.click(screen.getByRole("button", { name: "Remove and save" }));
    await waitFor(() =>
      expect(sent.body).toEqual({ login_identifiers: ["email"] }),
    );
  });

  it("sends nothing when the question is cancelled", async () => {
    policy(["email", "phone"]);
    const sent = captureAccessPut();
    const user = userEvent.setup();
    renderCard(<LoginIdentifiersCard />);

    await user.click(await screen.findByLabelText("Mobile number"));
    await user.click(
      screen.getByRole("button", { name: "Save sign-in methods" }),
    );
    await user.click(await screen.findByRole("button", { name: "Cancel" }));

    expect(sent.body).toBeNull();
  });

  it("does not ask when a method is only added", async () => {
    policy(["email"]);
    const sent = captureAccessPut();
    const user = userEvent.setup();
    renderCard(<LoginIdentifiersCard />);

    await user.click(await screen.findByLabelText("Mobile number"));
    await user.click(
      screen.getByRole("button", { name: "Save sign-in methods" }),
    );

    await waitFor(() =>
      expect(sent.body).toEqual({ login_identifiers: ["email", "phone"] }),
    );
    expect(
      screen.queryByText("Remove a sign-in method?"),
    ).not.toBeInTheDocument();
  });
});
