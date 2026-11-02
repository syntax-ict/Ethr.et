import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import BackupsPage from "@/app/(dashboard)/admin/backups/page";
import type {
  BackupConsoleState,
  BackupStorageState,
} from "@/features/admin/backups";

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({ isSuperAdmin: true }),
}));
vi.mock("@/components/shared/role-gate", () => ({
  RoleGate: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

/**
 * The console's Backups page: it never holds a secret it was not just given,
 * it will not switch copies on before the storage is tested and the key is
 * confirmed saved, it shows what the storage answered, and the key appears
 * only when asked for.
 */
const NOW = "2026-11-02T08:00:00Z";
const KEY = "base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=";

const STORAGE: BackupStorageState = {
  endpoint: "https://storage.example.com",
  region: "us-east-1",
  bucket: "ethr-backups",
  access_key_id: "AKIAEXAMPLE",
  secret_set: true,
  path_style: true,
  verified: false,
  verified_at: null,
  last_test_at: NOW,
  last_test_ok: false,
  last_test_error: "NoSuchBucket: The specified bucket does not exist",
};

function state(
  overrides: Partial<BackupConsoleState> = {},
): BackupConsoleState {
  return {
    source: "console",
    enabled: false,
    available: false,
    blocker: "off_host_switched_off",
    keep: 14,
    storage: STORAGE,
    key: { revealed_at: null, saved_at: null },
    health: {
      state: "warning",
      reasons: ["off_host_switched_off"],
      last_backup_at: NOW,
      age_seconds: 60,
      last_failed_at: null,
      last_failed_stage: null,
      off_host: {
        configured: false,
        disk: "ethr-backups",
        last_copied_at: null,
      },
    },
    ...overrides,
  };
}

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <BackupsPage />
    </QueryClientProvider>,
  );
}

let saved: Record<string, unknown> | null = null;

beforeEach(() => {
  saved = null;
  localStorage.setItem("locale", "en");
});

describe("Backups page", () => {
  it("keeps the switch locked until the storage is tested and the key is saved", async () => {
    server.use(
      http.get("*/api/v1/admin/backups", () =>
        HttpResponse.json(
          state({
            storage: { ...STORAGE, verified: true, last_test_ok: true },
          }),
        ),
      ),
    );

    renderPage();

    const toggle = await screen.findByRole("switch", {
      name: /copy each nightly backup/i,
    });
    expect(toggle).toBeDisabled();
    expect(
      screen.getByText(/confirm the key is saved before switching this on/i),
    ).toBeInTheDocument();
  });

  it("allows the switch once both are done", async () => {
    server.use(
      http.get("*/api/v1/admin/backups", () =>
        HttpResponse.json(
          state({
            storage: { ...STORAGE, verified: true, last_test_ok: true },
            key: { revealed_at: NOW, saved_at: NOW },
          }),
        ),
      ),
    );

    renderPage();

    expect(
      await screen.findByRole("switch", { name: /copy each nightly backup/i }),
    ).toBeEnabled();
  });

  it("never shows a stored secret, and does not send one when left empty", async () => {
    server.use(
      http.get("*/api/v1/admin/backups", () => HttpResponse.json(state())),
      http.put("*/api/v1/admin/backups/storage", async ({ request }) => {
        saved = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(state());
      }),
    );
    const user = userEvent.setup();

    renderPage();

    const secret = await screen.findByLabelText(/secret access key/i);
    expect(secret).toHaveValue("");
    expect(secret).toHaveAttribute("type", "password");
    expect(secret).toHaveAttribute(
      "placeholder",
      expect.stringMatching(/leave empty to keep it/i),
    );

    await user.click(screen.getByRole("button", { name: /save storage/i }));

    await waitFor(() => expect(saved).not.toBeNull());
    expect(saved?.secret_access_key ?? null).toBeNull();
    expect(saved?.bucket).toBe("ethr-backups");
  });

  it("shows what the storage answered to the last test", async () => {
    server.use(
      http.get("*/api/v1/admin/backups", () => HttpResponse.json(state())),
    );

    renderPage();

    expect(await screen.findByText(/NoSuchBucket/)).toBeInTheDocument();
    expect(screen.getByText(/not tested/i)).toBeInTheDocument();
  });

  it("shows the key only when asked for", async () => {
    server.use(
      http.get("*/api/v1/admin/backups", () => HttpResponse.json(state())),
      http.post("*/api/v1/admin/backups/key", () =>
        HttpResponse.json({ key: KEY }),
      ),
    );
    const user = userEvent.setup();

    renderPage();

    await user.click(await screen.findByRole("button", { name: /show key/i }));

    expect(await screen.findByText(KEY)).toBeInTheDocument();
  });

  it("offers only the storage form before anything is saved", async () => {
    server.use(
      http.get("*/api/v1/admin/backups", () =>
        HttpResponse.json(
          state({
            source: "none",
            storage: null,
            key: null,
            blocker: "off_host_not_configured",
          }),
        ),
      ),
    );

    renderPage();

    expect(
      await screen.findByRole("button", { name: /test connection/i }),
    ).toBeDisabled();
    expect(screen.queryByRole("button", { name: /show key/i })).toBeNull();
    expect(screen.getByText(/on the backups page/i)).toBeInTheDocument();
  });
});
