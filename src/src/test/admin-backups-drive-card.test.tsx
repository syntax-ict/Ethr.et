import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import BackupsPage from "@/app/(dashboard)/admin/backups/page";
import type {
  BackupConsoleState,
  BackupDriveState,
} from "@/features/admin/backups";

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({ isSuperAdmin: true }),
}));
vi.mock("@/components/shared/role-gate", () => ({
  RoleGate: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

/**
 * The Google Drive card on the Backups page: what it shows in each state, that
 * the redirect address is there to copy, that Connect sends the browser to
 * the address the server returns, and that the page finishes a return from
 * Google by posting `code` and `state` once and cleaning the address.
 */
const NOW = "2026-11-15T08:00:00Z";
const REDIRECT = "https://admin.ethr.et/admin/backups";
const KEY = "base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=";

const NOT_SAVED: BackupDriveState = {
  redirect_uri: REDIRECT,
  saved: false,
  client_id: null,
  secret_set: false,
  connected: false,
  account_email: null,
  connected_at: null,
  enabled: false,
  available: false,
  blocker: null,
  verified: false,
  verified_at: null,
  last_test_at: null,
  last_test_ok: null,
  last_test_error: null,
  key: null,
};

const SAVED: BackupDriveState = {
  ...NOT_SAVED,
  saved: true,
  client_id: "client-1.apps.googleusercontent.com",
  secret_set: true,
  blocker: "off_host_not_connected",
  key: { revealed_at: null, saved_at: null },
};

const CONNECTED: BackupDriveState = {
  ...SAVED,
  connected: true,
  account_email: "owner@example.com",
  connected_at: NOW,
  blocker: "off_host_switched_off",
};

const VERIFIED: BackupDriveState = {
  ...CONNECTED,
  verified: true,
  verified_at: NOW,
  last_test_at: NOW,
  last_test_ok: true,
  key: { revealed_at: NOW, saved_at: NOW },
};

function state(drive: BackupDriveState): BackupConsoleState {
  return {
    source: "none",
    enabled: false,
    available: false,
    blocker: "off_host_not_configured",
    keep: 14,
    residency_enforced: false,
    storage: null,
    key: null,
    google_drive: drive,
    health: {
      state: "warning",
      reasons: ["off_host_not_configured"],
      last_backup_at: NOW,
      age_seconds: 60,
      last_failed_at: null,
      last_failed_stage: null,
      off_host: {
        configured: false,
        disk: "s3",
        destination: null,
        last_copied_at: null,
      },
      disk: null,
    },
  };
}

function serve(drive: BackupDriveState) {
  server.use(
    http.get("*/api/v1/admin/backups", () => HttpResponse.json(state(drive))),
  );
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

beforeEach(() => {
  localStorage.setItem("locale", "en");
});

const originalLocation = window.location;

afterEach(() => {
  Object.defineProperty(window, "location", {
    configurable: true,
    writable: true,
    value: originalLocation,
  });
  window.history.replaceState(null, "", "/");
  vi.restoreAllMocks();
});

describe("Google Drive card", () => {
  it("before anything is saved, shows the redirect address and offers only the client form", async () => {
    serve(NOT_SAVED);

    renderPage();

    expect(await screen.findByText(REDIRECT)).toBeInTheDocument();
    expect(screen.getByText(/not connected/i)).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: /connect google drive/i }),
    ).toBeDisabled();
    expect(
      screen.getByRole("button", { name: /test google drive/i }),
    ).toBeDisabled();
    expect(
      screen.queryByRole("switch", { name: /use google drive/i }),
    ).toBeNull();
    expect(screen.getByLabelText(/client secret/i)).toHaveAttribute(
      "type",
      "password",
    );
  });

  it("copies the redirect address", async () => {
    serve(NOT_SAVED);
    const user = userEvent.setup();
    const writeText = vi.fn().mockResolvedValue(undefined);
    vi.spyOn(navigator.clipboard, "writeText").mockImplementation(writeText);

    renderPage();

    await user.click(
      await screen.findByRole("button", { name: /copy the redirect uri/i }),
    );

    expect(writeText).toHaveBeenCalledWith(REDIRECT);
  });

  it("saved but not connected: the secret stays hidden and Connect is on", async () => {
    serve(SAVED);

    renderPage();

    const secret = await screen.findByLabelText(/client secret/i);
    expect(secret).toHaveValue("");
    expect(secret).toHaveAttribute(
      "placeholder",
      expect.stringMatching(/leave empty to keep it/i),
    );
    expect(screen.getByLabelText(/client id/i)).toHaveValue(
      "client-1.apps.googleusercontent.com",
    );
    expect(
      screen.getByRole("button", { name: /connect google drive/i }),
    ).toBeEnabled();
  });

  it("connected: names the account, wants a test, and keeps the switch locked", async () => {
    serve(CONNECTED);

    renderPage();

    expect(await screen.findByText(/owner@example.com/)).toBeInTheDocument();
    expect(screen.getByText(/not tested/i)).toBeInTheDocument();
    expect(
      screen.getByRole("switch", { name: /use google drive/i }),
    ).toBeDisabled();
    expect(
      screen.getByRole("button", { name: /disconnect/i }),
    ).toBeInTheDocument();
  });

  it("verified with the key saved: the switch can be used", async () => {
    serve(VERIFIED);

    renderPage();

    expect(
      await screen.findByRole("switch", { name: /use google drive/i }),
    ).toBeEnabled();
  });

  it("in production, says Drive takes no copies and keeps the switch off", async () => {
    // BackupResidency: Drive stores outside Ethiopia (Proclamation 1321/2024
    // Art. 22), so production blocks it however ready it is.
    serve({ ...VERIFIED, blocker: "off_host_outside_ethiopia" });

    renderPage();

    expect(
      await screen.findByRole("switch", { name: /use google drive/i }),
    ).toBeDisabled();
    expect(
      screen.getByText(/does not copy backups to google drive/i),
    ).toBeInTheDocument();
    expect(screen.getByText(/can still be restored/i)).toBeInTheDocument();
  });

  it("shows the key only when asked for", async () => {
    serve(CONNECTED);
    server.use(
      http.post("*/api/v1/admin/backups/google-drive/key", () =>
        HttpResponse.json({ key: KEY }),
      ),
    );
    const user = userEvent.setup();

    renderPage();

    expect(screen.queryByText(KEY)).toBeNull();
    await user.click(
      await screen.findByRole("button", { name: /show the google drive key/i }),
    );

    expect(await screen.findByText(KEY)).toBeInTheDocument();
  });

  it("shows what Google answered to a failed test", async () => {
    serve({
      ...CONNECTED,
      last_test_at: NOW,
      last_test_ok: false,
      last_test_error: "The user's Drive storage quota has been exceeded.",
    });

    renderPage();

    expect(await screen.findByText(/storage quota/i)).toBeInTheDocument();
  });

  it("Connect asks the server for the consent address and sends the browser there", async () => {
    serve(SAVED);
    server.use(
      http.post("*/api/v1/admin/backups/google-drive/connect", () =>
        HttpResponse.json({
          url: "https://accounts.google.com/o/oauth2/v2/auth?x=1",
        }),
      ),
    );
    const assign = vi.fn();
    Object.defineProperty(window, "location", {
      configurable: true,
      writable: true,
      value: Object.assign(new URL("https://admin.ethr.et/admin/backups"), {
        assign,
      }),
    });
    const user = userEvent.setup();

    renderPage();

    await user.click(
      await screen.findByRole("button", { name: /connect google drive/i }),
    );

    await waitFor(() =>
      expect(assign).toHaveBeenCalledWith(
        "https://accounts.google.com/o/oauth2/v2/auth?x=1",
      ),
    );
  });

  it("saves the client without sending an empty secret", async () => {
    serve(SAVED);
    let saved: Record<string, unknown> | null = null;
    server.use(
      http.put("*/api/v1/admin/backups/google-drive", async ({ request }) => {
        saved = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(state(SAVED));
      }),
    );
    const user = userEvent.setup();

    renderPage();

    await user.click(
      await screen.findByRole("button", { name: /save client/i }),
    );

    await waitFor(() => expect(saved).not.toBeNull());
    expect(saved).toEqual({
      client_id: "client-1.apps.googleusercontent.com",
      client_secret: null,
    });
  });
});

describe("returning from Google", () => {
  it("posts the code and state once, cleans the address and says it connected", async () => {
    window.history.replaceState(
      null,
      "",
      "/admin/backups?code=4%2F0AxCode&state=abc123&scope=drive.file",
    );
    serve(SAVED);
    const posts: Array<Record<string, unknown>> = [];
    server.use(
      http.post(
        "*/api/v1/admin/backups/google-drive/callback",
        async ({ request }) => {
          posts.push((await request.json()) as Record<string, unknown>);
          return HttpResponse.json(state(CONNECTED));
        },
      ),
    );

    renderPage();

    expect(await screen.findByRole("status")).toHaveTextContent(
      /google drive is connected/i,
    );
    expect(posts).toEqual([{ code: "4/0AxCode", state: "abc123" }]);
    expect(window.location.search).toBe("");
  });

  it("shows the server's reason when the connection is refused", async () => {
    window.history.replaceState(
      null,
      "",
      "/admin/backups?code=c&state=expired",
    );
    serve(SAVED);
    server.use(
      http.post("*/api/v1/admin/backups/google-drive/callback", () =>
        HttpResponse.json(
          { errors: { state: ["This sign-in link has expired."] } },
          { status: 422 },
        ),
      ),
    );

    renderPage();

    expect(await screen.findByRole("alert")).toHaveTextContent(
      /sign-in link has expired/i,
    );
    expect(window.location.search).toBe("");
  });

  it("says so when Google was refused, without calling the API", async () => {
    window.history.replaceState(null, "", "/admin/backups?error=access_denied");
    serve(SAVED);
    const called = vi.fn();
    server.use(
      http.post("*/api/v1/admin/backups/google-drive/callback", () => {
        called();
        return HttpResponse.json(state(CONNECTED));
      }),
    );

    renderPage();

    expect(await screen.findByRole("alert")).toHaveTextContent(
      /access was not allowed/i,
    );
    expect(called).not.toHaveBeenCalled();
    expect(window.location.search).toBe("");
  });
});
