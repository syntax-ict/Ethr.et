import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import SmsDeliveryPage from "@/app/(dashboard)/admin/sms/page";
import type { SmsDeliveryState, SmsGatewayState } from "@/features/admin/sms";

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({ isSuperAdmin: true }),
}));
vi.mock("@/components/shared/role-gate", () => ({
  RoleGate: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

/**
 * The console's SMS delivery page: it never holds a credential it was not
 * just given, it will not switch SMS on before a test has passed, and it shows
 * what the gateway answered.
 */
const GATEWAY: SmsGatewayState = {
  slot: "primary",
  label: "Aggregator",
  endpoint: "https://api.aggregator.example/sms/send",
  method: "json",
  auth_type: "header",
  auth_header: "KEY",
  auth_username: null,
  secret_set: true,
  username_field: null,
  password_field: null,
  recipient_field: "to",
  message_field: "message",
  sender_field: "sender",
  sender_id: "ETHR",
  number_format: "international",
  success_json_path: "status",
  success_json_value: "success",
  success_body_contains: null,
  timeout_seconds: 10,
  verified: false,
  verified_at: null,
  last_test_at: "2026-10-14T08:00:00Z",
  last_test_ok: false,
  last_test_status: 200,
  last_test_response: '{"status":"error","message":"invalid sender"}',
};

function state(overrides: Partial<SmsDeliveryState> = {}): SmsDeliveryState {
  return {
    enabled: false,
    route_prefix: null,
    daily_cap: 500,
    sent_today: 3,
    available: false,
    source: "console",
    primary: GATEWAY,
    secondary: null,
    ...overrides,
  };
}

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <SmsDeliveryPage />
    </QueryClientProvider>,
  );
}

let saved: Record<string, unknown> | null = null;

beforeEach(() => {
  saved = null;
  localStorage.setItem("locale", "en");
});

describe("SMS delivery page", () => {
  it("keeps the switch locked until the primary gateway has passed a test", async () => {
    server.use(
      http.get("*/api/v1/admin/sms", () => HttpResponse.json(state())),
    );

    renderPage();

    const toggle = await screen.findByRole("switch", { name: /send sms/i });
    expect(toggle).toBeDisabled();
    expect(
      screen.getByText(/successful test from the primary gateway first/i),
    ).toBeInTheDocument();
  });

  it("allows the switch once the gateway is verified", async () => {
    server.use(
      http.get("*/api/v1/admin/sms", () =>
        HttpResponse.json(state({ primary: { ...GATEWAY, verified: true } })),
      ),
    );

    renderPage();

    expect(
      await screen.findByRole("switch", { name: /send sms/i }),
    ).toBeEnabled();
  });

  it("never shows a stored credential, only that one is set", async () => {
    server.use(
      http.get("*/api/v1/admin/sms", () => HttpResponse.json(state())),
    );

    renderPage();

    const secret = await screen.findByLabelText(/api key, token or password/i);
    expect(secret).toHaveValue("");
    expect(secret).toHaveAttribute("type", "password");
    expect(secret).toHaveAttribute(
      "placeholder",
      expect.stringMatching(/leave empty to keep it/i),
    );
  });

  it("does not send a credential when the field is left empty", async () => {
    server.use(
      http.get("*/api/v1/admin/sms", () => HttpResponse.json(state())),
      http.put("*/api/v1/admin/sms/gateways/primary", async ({ request }) => {
        saved = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(state());
      }),
    );
    const user = userEvent.setup();

    renderPage();

    await user.click(
      await screen.findByRole("button", { name: /save gateway/i }),
    );

    await waitFor(() => expect(saved).not.toBeNull());
    expect(saved?.auth_secret ?? null).toBeNull();
    expect(saved?.endpoint).toBe(GATEWAY.endpoint);
  });

  it("shows what the gateway answered to the last test", async () => {
    server.use(
      http.get("*/api/v1/admin/sms", () => HttpResponse.json(state())),
    );

    renderPage();

    expect(await screen.findByText(/not accepted/i)).toBeInTheDocument();
    expect(screen.getByText(/HTTP 200/)).toBeInTheDocument();
    expect(screen.getByText(/invalid sender/)).toBeInTheDocument();
  });

  it("explains when SMS still comes from the server's .env file", async () => {
    server.use(
      http.get("*/api/v1/admin/sms", () =>
        HttpResponse.json(state({ source: "env", primary: null })),
      ),
    );

    renderPage();

    expect(
      await screen.findByText(/configured in the server's \.env file/i),
    ).toBeInTheDocument();
  });

  it("shows the second gateway only when a route is chosen", async () => {
    server.use(
      http.get("*/api/v1/admin/sms", () =>
        HttpResponse.json(state({ route_prefix: "7" })),
      ),
    );

    renderPage();

    expect(
      await screen.findByText(/second gateway — routed numbers/i),
    ).toBeInTheDocument();
  });
});
