import { describe, it, expect, vi } from "vitest";
import { render as rtlRender, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactElement } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { AllowAddressButton } from "@/features/devices/components/allow-address-button";

const granted = vi.hoisted(() => ({
  abilities: ["device.update"] as string[],
}));
vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    hasPermission: (ability: string) => granted.abilities.includes(ability),
  }),
}));

function render(ui: ReactElement) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return rtlRender(
    <QueryClientProvider client={client}>{ui}</QueryClientProvider>,
  );
}

describe("<AllowAddressButton>", () => {
  it("posts the address it names, and nothing else", async () => {
    granted.abilities = ["device.update"];
    let body: unknown = null;
    server.use(
      http.post("*/api/v1/devices/DEV1/allowlist", async ({ request }) => {
        body = await request.json();
        return HttpResponse.json({ public_id: "DEV1" });
      }),
    );

    render(<AllowAddressButton devicePublicId="DEV1" ip="196.188.4.20" />);
    await userEvent.click(
      screen.getByRole("button", { name: /Allow 196\.188\.4\.20/ }),
    );

    await waitFor(() => expect(body).toEqual({ ip: "196.188.4.20" }));
  });

  it("is not offered to someone who may only view devices", () => {
    granted.abilities = ["device.view"];

    const { container } = render(
      <AllowAddressButton devicePublicId="DEV1" ip="196.188.4.20" />,
    );

    expect(container).toBeEmptyDOMElement();
  });

  it("is not offered when the refusal named no address", () => {
    granted.abilities = ["device.update"];

    const { container } = render(
      <AllowAddressButton devicePublicId="DEV1" ip={null} />,
    );

    expect(container).toBeEmptyDOMElement();
  });
});
