import { describe, it, expect } from "vitest";
import { render as rtlRender, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactElement } from "react";
import { AdmsSetupCard } from "@/features/devices/components/adms-setup-card";
import type { Device } from "@/features/devices/api";

/**
 * What an administrator enters on a ZKTeco terminal for ADMS (2026-10-14), and
 * the two reasons it may not be getting through: an empty allowlist, and an
 * address the allowlist does not cover.
 */
function render(ui: ReactElement) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return rtlRender(
    <QueryClientProvider client={client}>{ui}</QueryClientProvider>,
  );
}

const DEVICE = {
  public_id: "DEV1",
  name: "Gate",
  adapter_type: "zkteco_adms",
  serial_number: "CQZ7232460001",
  webhook_ip_allowlist: [],
  adms: { server: "ethr.et", last_rejected: null },
} as unknown as Device;

describe("<AdmsSetupCard>", () => {
  it("says where to point the terminal, and warns while the allowlist is empty", () => {
    render(<AdmsSetupCard device={DEVICE} />);

    expect(screen.getByText("ethr.et")).toBeInTheDocument();
    expect(screen.getByText("CQZ7232460001")).toBeInTheDocument();
    expect(screen.getByText(/allowlist is empty/)).toBeInTheDocument();
  });

  it("names the address of the last refused attempt", () => {
    render(
      <AdmsSetupCard
        device={
          {
            ...DEVICE,
            webhook_ip_allowlist: ["10.0.0.1"],
            adms: {
              server: "ethr.et",
              last_rejected: { ip: "196.188.4.20", at: "2026-10-14T08:00:00Z" },
            },
          } as unknown as Device
        }
      />,
    );

    expect(screen.getByText(/196\.188\.4\.20/)).toBeInTheDocument();
    expect(screen.queryByText(/allowlist is empty/)).not.toBeInTheDocument();
  });

  it("renders nothing for a device that is not ADMS, or for someone not shown its setup", () => {
    const { container } = render(
      <AdmsSetupCard device={{ ...DEVICE, adms: undefined } as Device} />,
    );
    expect(container).toBeEmptyDOMElement();
  });
});
