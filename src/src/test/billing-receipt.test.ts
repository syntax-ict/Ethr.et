import { afterEach, describe, expect, it, vi } from "vitest";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { downloadReceipt } from "@/features/billing/api";

/**
 * "Download receipt" opened `/api/v1/billing/invoices/{id}/receipt` with
 * `window.open`. On the single production host the organisation travels in
 * `X-Tenant`, which a navigation cannot send, so no tenant resolved, the
 * invoice binding failed closed, and every receipt was a 404 (audit N51).
 */
describe("downloading a receipt", () => {
  afterEach(() => {
    vi.restoreAllMocks();
    localStorage.removeItem("tenant");
  });

  it("asks for it through the API client, which names the organisation", async () => {
    localStorage.setItem("tenant", "acme");
    let tenantHeader: string | null = null;
    server.use(
      http.get("*/api/v1/billing/invoices/:id/receipt", ({ request }) => {
        tenantHeader = request.headers.get("X-Tenant");
        return new HttpResponse(new Blob(["%PDF-1.4"]), {
          headers: { "Content-Type": "application/pdf" },
        });
      }),
    );
    const saved = vi
      .spyOn(URL, "createObjectURL")
      .mockImplementation(() => "blob:receipt");

    await downloadReceipt("01HZINV0001");

    expect(tenantHeader).toBe("acme");
    expect(saved).toHaveBeenCalledTimes(1);
  });
});
