import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import DataExportPage from "@/app/(dashboard)/settings/data-export/page";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

/** One row of `DataExportController::index`'s `data` array. */
const READY = {
  public_id: "EXP1",
  status: "ready",
  size_bytes: 3 * 1024 * 1024,
  file_count: 42,
  expires_at: "2026-10-27T08:00:00+00:00",
  created_at: "2026-10-20T08:00:00+00:00",
};

function me(permissions: string[]) {
  return http.get("*/api/v1/auth/me", () =>
    HttpResponse.json({
      user: { public_id: "U1", name: "Test User", role: "tenant_admin" },
      tenant: { public_id: "T1", name: "Demo", timezone: "Africa/Addis_Ababa" },
      permissions,
    }),
  );
}

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  return render(<DataExportPage />, { wrapper: Wrapper });
}

describe("<DataExportPage>", () => {
  it("lists a ready export with its size and a download button", async () => {
    server.use(
      me(["settings.manage"]),
      http.get("*/api/v1/settings/data-export", () =>
        HttpResponse.json({ data: [READY], expires_days: 7 }),
      ),
    );
    renderPage();

    expect(await screen.findByText("Ready")).toBeInTheDocument();
    expect(screen.getByText(/3\.0 MB, 42 files/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Download" })).toBeEnabled();
  });

  it("requests an export and shows it waiting", async () => {
    let requested = false;
    server.use(
      me(["settings.manage"]),
      http.get("*/api/v1/settings/data-export", () =>
        HttpResponse.json({
          data: requested
            ? [
                {
                  ...READY,
                  public_id: "EXP2",
                  status: "queued",
                  size_bytes: null,
                  file_count: null,
                  expires_at: null,
                },
              ]
            : [],
          expires_days: 7,
        }),
      ),
      http.post("*/api/v1/settings/data-export", () => {
        requested = true;
        return HttpResponse.json(
          { ...READY, status: "queued" },
          { status: 202 },
        );
      }),
    );
    const user = userEvent.setup();
    renderPage();

    expect(await screen.findByText("No exports yet.")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Request export" }));

    await waitFor(() => expect(requested).toBe(true));
    expect(await screen.findByText("Waiting")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Download" })).toBeNull();
  });
});
