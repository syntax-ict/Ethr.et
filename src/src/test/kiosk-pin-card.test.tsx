import { describe, expect, it } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { KioskPinCard } from "@/features/employees/components/kiosk-pin-card";
import type { Employee } from "@/features/employees/types";

function employee(hasPin: boolean): Employee {
  return {
    public_id: "01HZEMP0001",
    name: "Abebe Kebede",
    has_kiosk_pin: hasPin,
  } as Employee;
}

function capturePins() {
  const sent: unknown[] = [];
  server.use(
    http.put("*/api/v1/employees/:id/kiosk-pin", async ({ request }) => {
      const body = (await request.json()) as { pin: string | null };
      sent.push(body.pin);
      return HttpResponse.json({
        public_id: "01HZEMP0001",
        has_kiosk_pin: body.pin !== null,
      });
    }),
  );
  return sent;
}

function renderCard(hasPin: boolean) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <KioskPinCard employee={employee(hasPin)} />
    </QueryClientProvider>,
  );
}

// "Require PIN" refused every employee at every kiosk, because nothing could
// set a PIN (audit N62).
describe("the kiosk PIN card", () => {
  it("sets a PIN of the digits typed", async () => {
    const sent = capturePins();
    renderCard(false);

    expect(screen.getByText("Not set")).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: /set pin/i }));
    await userEvent.type(await screen.findByLabelText(/new pin/i), "48a21");
    await userEvent.click(screen.getByRole("button", { name: /save pin/i }));

    // Non-digits are dropped as they are typed.
    await waitFor(() => expect(sent).toEqual(["4821"]));
  });

  it("removes a PIN by sending null", async () => {
    const sent = capturePins();
    renderCard(true);

    await userEvent.click(screen.getByRole("button", { name: /^remove$/i }));

    await waitFor(() => expect(sent).toEqual([null]));
  });
});
