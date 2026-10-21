import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import ProfileDocumentsPage from "@/app/(dashboard)/profile/documents/page";
import { CalendarProvider } from "@/lib/calendar/calendar-context";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

/**
 * An employee sees and opens their own documents. Until 2026-10-20 the only
 * route to a document was the HR screen (blueprint ESS8).
 */
const CONTRACT = {
  public_id: "01HZDOC0000000000000000001",
  type: "contract",
  title: "Employment contract 2026",
  file_path: "employees/x/documents/contract.pdf",
  file_size: 1000,
  mime_type: "application/pdf",
  expiry_date: null,
  is_expired: false,
  is_expiring_soon: false,
  created_at: "2026-10-01T06:00:00Z",
};

function renderPage(docs: unknown[]) {
  server.use(
    http.get("*/api/v1/profile/documents", () =>
      HttpResponse.json({ data: docs }),
    ),
    http.get("*/api/v1/profile/documents/:id", () =>
      HttpResponse.json({
        download_url: "https://api.example.test/signed/contract.pdf",
        document: CONTRACT,
      }),
    ),
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "U1", role: "employee" },
        permissions: [],
        tenant: { timezone: "Africa/Addis_Ababa" },
      }),
    ),
  );
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>
      <CalendarProvider>{children}</CalendarProvider>
    </QueryClientProvider>
  );
  return render(<ProfileDocumentsPage />, { wrapper: Wrapper });
}

let opened: string[] = [];
beforeEach(() => {
  opened = [];
  vi.spyOn(window, "open").mockImplementation((url) => {
    opened.push(String(url));
    return null;
  });
});
afterEach(() => vi.restoreAllMocks());

describe("<ProfileDocumentsPage>", () => {
  it("lists the caller's documents and opens one through a signed link", async () => {
    const user = userEvent.setup();
    renderPage([CONTRACT]);

    expect(
      await screen.findByText("Employment contract 2026"),
    ).toBeInTheDocument();
    await user.click(
      screen.getByRole("button", { name: "Open Employment contract 2026" }),
    );

    await vi.waitFor(() =>
      expect(opened).toEqual(["https://api.example.test/signed/contract.pdf"]),
    );
  });

  it("says so when there are none", async () => {
    renderPage([]);

    expect(await screen.findByText("No documents yet")).toBeInTheDocument();
  });
});
