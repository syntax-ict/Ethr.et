import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import OperationsPage from "@/app/(dashboard)/admin/operations/page";
import { OperationsStrip } from "@/features/admin/components/operations-strip";
import type { OperationsStatus } from "@/features/admin/operations";

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({ isSuperAdmin: true }),
}));
vi.mock("@/components/shared/role-gate", () => ({
  RoleGate: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

/**
 * The operations centre page: it says plainly which areas need attention and
 * why, it never treats `unknown` as an alarm, and a reason code it does not
 * know is still shown rather than dropped.
 */
const NOW = "2026-10-28T09:00:00Z";

function status(overrides: Partial<OperationsStatus> = {}): OperationsStatus {
  return {
    status: "ok",
    checked_at: NOW,
    background: {
      state: "ok",
      reasons: [],
      scheduler: { last_run_at: NOW, age_seconds: 30, state: "ok" },
      external_caller: { last_run_at: NOW, age_seconds: 60, state: "ok" },
      page_traffic: { last_run_at: NOW, age_seconds: 10 },
      last_drain_at: NOW,
      queues: [
        {
          name: "default",
          depth: 0,
          oldest_waiting_seconds: null,
          state: "ok",
        },
        {
          name: "exports",
          depth: 2,
          oldest_waiting_seconds: 2400,
          state: "ok",
        },
      ],
    },
    failed_jobs: { state: "ok", reasons: [], count: 0, last_failed_at: null },
    delivery: {
      state: "ok",
      reasons: [],
      mail: {
        mailer: "smtp",
        last_sent_at: NOW,
        last_failed_at: null,
        tls: "pinned",
      },
    },
    release: {
      state: "ok",
      reasons: [],
      environment: "production",
      commit: "07593ce98aabbccdd",
      built_at: NOW,
      pending_migrations: 0,
      config_cached_at: NOW,
      routes_cached_at: NOW,
      debug: false,
      queue_connection: "database",
      broadcast_connection: "null",
      maintenance_routes_open: false,
      cron_token_set: true,
      php: {
        version: "8.3.33",
        upload_max_filesize: "2M",
        post_max_size: "8M",
        memory_limit: "128M",
        max_execution_time: "30",
      },
    },
    services: {
      state: "ok",
      reasons: [],
      checks: [{ name: "database", status: "healthy", response_ms: 2 }],
      disk_free_gb: 40,
    },
    ...overrides,
  };
}

function respondWith(body: OperationsStatus) {
  server.use(
    http.get("*/api/v1/admin/operations", () => HttpResponse.json(body)),
  );
}

function renderWithClient(node: React.ReactNode) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>{node}</QueryClientProvider>,
  );
}

beforeEach(() => {
  localStorage.setItem("locale", "en");
});

describe("Operations page", () => {
  it("names a dead external caller and links to the area that needs attention", async () => {
    const healthy = status();
    respondWith(
      status({
        status: "warning",
        background: {
          ...healthy.background,
          state: "warning",
          reasons: ["external_caller_stale"],
          external_caller: {
            last_run_at: "2026-10-28T08:00:00Z",
            age_seconds: 3600,
            state: "warning",
          },
        },
      }),
    );

    renderWithClient(<OperationsPage />);

    expect(
      await screen.findByText("One area needs attention"),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/the external cron caller has gone quiet/i),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: "Background work" }),
    ).toHaveAttribute("href", "#background");
    expect(screen.getByText("40 min")).toBeInTheDocument();
  });

  it("does not treat unknown as something that needs attention", async () => {
    const healthy = status();
    respondWith(
      status({
        status: "unknown",
        background: {
          ...healthy.background,
          state: "unknown",
          reasons: ["scheduler_never_run"],
          scheduler: { last_run_at: null, age_seconds: null, state: "unknown" },
        },
      }),
    );

    renderWithClient(<OperationsPage />);

    expect(
      await screen.findByText("Nothing needs attention"),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/the scheduler has never run/i),
    ).toBeInTheDocument();
  });

  it("shows a reason code it does not know rather than hiding it", async () => {
    const healthy = status();
    respondWith(
      status({
        status: "critical",
        release: {
          ...healthy.release,
          state: "critical",
          reasons: ["a_reason_from_a_newer_api"],
        },
      }),
    );

    renderWithClient(<OperationsPage />);

    expect(
      await screen.findByText("a_reason_from_a_newer_api"),
    ).toBeInTheDocument();
  });

  it("offers a retry when the status cannot be loaded", async () => {
    server.use(
      http.get("*/api/v1/admin/operations", () =>
        HttpResponse.json({ title: "Server Error" }, { status: 500 }),
      ),
    );

    renderWithClient(<OperationsPage />);

    expect(
      await screen.findByRole("button", { name: /retry|try again/i }),
    ).toBeInTheDocument();
  });
});

describe("Operations strip on the console home", () => {
  it("links each area to its panel and shows its state", async () => {
    const healthy = status();
    respondWith(
      status({
        status: "critical",
        release: {
          ...healthy.release,
          state: "critical",
          reasons: ["pending_migrations"],
        },
      }),
    );

    renderWithClient(<OperationsStrip />);

    const release = await screen.findByRole("link", { name: /release/i });
    expect(release).toHaveAttribute("href", "/admin/operations#release");
    expect(within(release).getByText("Action needed")).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: /open operations/i }),
    ).toHaveAttribute("href", "/admin/operations");
  });
});
