import { describe, expect, it } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { StatutoryDetailsCard } from "@/features/payroll/components/statutory-details-card";

/**
 * The header of the monthly tax and pension schedules (2026-10-14): without
 * the employer TIN they still download, but say NOT SET where it belongs.
 */
function serve() {
  const saved: Record<string, unknown>[] = [];
  server.use(
    http.get("*/api/v1/settings", () =>
      HttpResponse.json({
        payroll: {
          pay_period: "monthly",
          employer_tin: null,
          tax_center: null,
          pension_scheme: "private",
        },
      }),
    ),
    http.put("*/api/v1/settings", async ({ request }) => {
      const body = (await request.json()) as {
        settings: Record<string, unknown>;
      };
      saved.push(body.settings);
      return HttpResponse.json({});
    }),
  );
  return saved;
}

describe("<StatutoryDetailsCard>", () => {
  it("saves the employer TIN as digits only, the tax centre and the scheme", async () => {
    const saved = serve();
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    render(
      <QueryClientProvider client={client}>
        <StatutoryDetailsCard />
      </QueryClientProvider>,
    );
    const user = userEvent.setup();

    await user.type(
      await screen.findByLabelText("Employer TIN"),
      "00-1234 5678",
    );
    await user.type(screen.getByLabelText("Tax centre"), "Bole Medium");
    await user.click(screen.getByRole("combobox", { name: /pension scheme/i }));
    await user.click(
      await screen.findByRole("option", { name: "Public (PSSSA)" }),
    );
    await user.click(screen.getByRole("button", { name: /save/i }));

    await waitFor(() => expect(saved).toHaveLength(1));
    expect(saved[0]).toEqual({
      employer_tin: "0012345678",
      tax_center: "Bole Medium",
      pension_scheme: "public",
    });
  });
});
