import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { IssuedAddressDialog } from "@/features/devices/components/issued-address-dialog";
import {
  issuedSetupFor,
  type IssuedSetup,
} from "@/features/devices/issued-setup";
import type { Device } from "@/features/devices/api";

/**
 * The dialog after registering follows how the terminal connects (device setup
 * upgrade, PR 1). Three modes need three different things typed, or none; the
 * dialog used to show the Hikvision push address for all of them.
 */
vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

function renderDialog(setup: IssuedSetup | null, onClose = vi.fn()) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  render(
    <QueryClientProvider client={client}>
      <IssuedAddressDialog setup={setup} onClose={onClose} />
    </QueryClientProvider>,
  );
  return onClose;
}

const PUSH_URL =
  "https://www.ethr.et/api/v1/devices/webhook/hikvision?token=issued-once";

describe("<IssuedAddressDialog>", () => {
  it("shows a push terminal the address to enter, in parts, and only Done closes it", async () => {
    const onClose = renderDialog({ mode: "push", url: PUSH_URL });

    expect(await screen.findByTestId("issued-address")).toHaveTextContent(
      PUSH_URL,
    );
    expect(screen.getByText("Enter these on the terminal")).toBeInTheDocument();
    expect(screen.getByText("www.ethr.et")).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: /test connection/i }),
    ).not.toBeInTheDocument();

    await userEvent.setup().keyboard("{Escape}");
    expect(onClose).not.toHaveBeenCalled();
    await userEvent.setup().click(screen.getByRole("button", { name: "Done" }));
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it("shows an ADMS terminal the server, port and serial, with no address and no token", async () => {
    renderDialog({
      mode: "adms",
      server: "ethr.et",
      serial: "CQZ7232460001",
      allowlistEmpty: true,
    });

    const box = await screen.findByTestId("adms-setup");
    expect(box).toHaveTextContent("ethr.et");
    expect(box).toHaveTextContent("CQZ7232460001");
    expect(screen.getByText(/COMM → Cloud Server Setting/)).toBeInTheDocument();
    expect(
      screen.getByText(/needs no web address and no token/i),
    ).toBeInTheDocument();
    // An empty allowlist is why the terminal would be refused first.
    expect(screen.getByText(/allowlist is empty/i)).toBeInTheDocument();
    expect(screen.queryByTestId("issued-address")).not.toBeInTheDocument();
    expect(screen.queryByText(/token=/)).not.toBeInTheDocument();
    expect(
      screen.queryByText("Enter these on the terminal"),
    ).not.toBeInTheDocument();
  });

  it("leaves the allowlist warning out once the allowlist has an address", async () => {
    renderDialog({
      mode: "adms",
      server: "ethr.et",
      serial: "S1",
      allowlistEmpty: false,
    });

    await screen.findByTestId("adms-setup");
    expect(screen.queryByText(/allowlist is empty/i)).not.toBeInTheDocument();
  });

  it("tells a pulled terminal ETHR will call it, shows no address, and tests the connection", async () => {
    let tested = 0;
    server.use(
      http.get("*/api/v1/devices/DEV1/status", () => {
        tested++;
        return HttpResponse.json({
          device_public_id: "DEV1",
          status: "online",
          error_code: null,
        });
      }),
    );
    const user = userEvent.setup();
    renderDialog({
      mode: "pull",
      devicePublicId: "DEV1",
      adapterType: "zkteco",
    });

    expect(
      await screen.findByText("ETHR will call the terminal"),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/nothing to type on the terminal/i),
    ).toBeInTheDocument();
    expect(screen.queryByTestId("issued-address")).not.toBeInTheDocument();
    expect(screen.queryByTestId("adms-setup")).not.toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /test connection/i }));
    expect(
      await screen.findByText("Device is online and responding"),
    ).toBeInTheDocument();
    expect(tested).toBe(1);
  });

  it("says when the pulled terminal did not answer", async () => {
    server.use(
      http.get("*/api/v1/devices/DEV1/status", () =>
        HttpResponse.json({
          device_public_id: "DEV1",
          status: "offline",
          error_code: null,
        }),
      ),
    );
    const user = userEvent.setup();
    renderDialog({
      mode: "pull",
      devicePublicId: "DEV1",
      adapterType: "zkteco",
    });

    await user.click(
      await screen.findByRole("button", { name: /test connection/i }),
    );
    await waitFor(() =>
      expect(screen.getByRole("status")).toHaveTextContent(/offline/i),
    );
  });

  it("stays closed with nothing to show", () => {
    renderDialog(null);
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
  });
});

describe("issuedSetupFor", () => {
  const base = {
    public_id: "DEV1",
    adapter_type: "hikvision",
    serial_number: null,
    webhook_ip_allowlist: [],
  };
  const device = (extra: object) =>
    ({ ...base, ...extra }) as unknown as Device;

  it("follows connection_mode", () => {
    expect(
      issuedSetupFor(
        device({
          connection_mode: "push",
          uses_webhook_token: true,
          webhook_url: PUSH_URL,
        }),
      ),
    ).toEqual({ mode: "push", url: PUSH_URL });

    expect(
      issuedSetupFor(
        device({
          adapter_type: "zkteco_adms",
          connection_mode: "adms",
          uses_webhook_token: false,
          serial_number: "SN1",
          webhook_ip_allowlist: ["10.0.0.1"],
          webhook_url: PUSH_URL,
          adms: { server: "ethr.et", last_rejected: null },
        }),
      ),
    ).toEqual({
      mode: "adms",
      server: "ethr.et",
      serial: "SN1",
      allowlistEmpty: false,
    });

    expect(
      issuedSetupFor(
        device({
          adapter_type: "suprema",
          connection_mode: "pull",
          uses_webhook_token: true,
          webhook_url: PUSH_URL,
        }),
      ),
    ).toEqual({ mode: "pull", devicePublicId: "DEV1", adapterType: "suprema" });
  });

  it("has nothing to say for the simulator, and never offers a token it was not given", () => {
    expect(
      issuedSetupFor(
        device({
          adapter_type: "mock",
          connection_mode: "pull",
          uses_webhook_token: false,
          webhook_url: PUSH_URL,
        }),
      ),
    ).toBeNull();
    expect(
      issuedSetupFor(
        device({ connection_mode: "push", uses_webhook_token: true }),
      ),
    ).toBeNull();
  });
});
