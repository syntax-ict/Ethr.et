import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { readdirSync, readFileSync } from "node:fs";
import { join, relative } from "node:path";
import type { ReactNode } from "react";
import type { ColumnDef } from "@tanstack/react-table";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { registerLocale } from "@/lib/i18n/translations";
import enTranslations from "@/lib/i18n/locales/en.json";
import { DataTable } from "@/components/patterns/DataTable";
import { ErrorBoundary } from "@/components/shared/error-boundary";
import { CommandDialog, CommandInput } from "@/components/ui/command";
import { LeaveOverview } from "@/features/dashboard/components/leave-overview";
import OfflinePage from "@/app/offline/page";

/**
 * Audit N37: user-visible strings that never went through t(), so an Amharic
 * reader got English with no way for a translator to reach it — and the i18n
 * gate, which only inspects t() calls, could not see them either.
 *
 * Each render test swaps the active dictionary for one in which every key
 * renders as `⟦key⟧`. Text that goes through t() comes out as a sentinel;
 * text that is still a literal comes out as English. That proves the routing
 * without depending on what the translations say, which matters here: the new
 * keys' Amharic values are English placeholders awaiting a native speaker, so
 * a test that switched to `am` and looked for English would pass either way.
 *
 * Interpolated keys keep their `:placeholders` in the sentinel, so the test
 * also proves the values are passed as parameters rather than baked into a
 * template-literal fallback (which the translation would silently discard).
 */
const en = enTranslations as Record<string, string>;

const sentinels: Record<string, string> = Object.fromEntries(
  Object.entries(en).map(([key, value]) => {
    const params = value.match(/:[a-z_]+/g) ?? [];
    return [key, `⟦${[key, ...params].join(" ")}⟧`];
  }),
);

beforeEach(() => {
  registerLocale("en", sentinels);
});

afterEach(() => {
  registerLocale("en", en);
  vi.restoreAllMocks();
});

describe("error and offline states render through t()", () => {
  it("DataTable error state", async () => {
    const columns: ColumnDef<{ id: string }, unknown>[] = [
      { accessorKey: "id", header: "Id" },
    ];
    render(
      <DataTable
        tableId="n37"
        columns={columns}
        data={[]}
        isError
        onRetry={() => {}}
      />,
    );

    expect(
      await screen.findByText("⟦table.error_loading⟧"),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: "⟦common.try_again⟧" }),
    ).toBeInTheDocument();
    expect(
      screen.queryByText("Something went wrong loading this data."),
    ).not.toBeInTheDocument();
  });

  it("ErrorBoundary default fallback", async () => {
    vi.spyOn(console, "error").mockImplementation(() => {});
    function Boom(): ReactNode {
      throw new Error("boom");
    }
    render(
      <ErrorBoundary>
        <Boom />
      </ErrorBoundary>,
    );

    expect(
      await screen.findByRole("heading", { name: "⟦error.title⟧" }),
    ).toBeInTheDocument();
    // The thrown error's own message is shown as-is, as before.
    expect(screen.getByText("boom")).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: "⟦common.try_again⟧" }),
    ).toBeInTheDocument();
  });

  it("ErrorBoundary message fallback when the thrown value has none", async () => {
    vi.spyOn(console, "error").mockImplementation(() => {});
    function Boom(): ReactNode {
      throw "not an Error";
    }
    render(
      <ErrorBoundary>
        <Boom />
      </ErrorBoundary>,
    );

    expect(await screen.findByText("⟦error.unexpected⟧")).toBeInTheDocument();
    expect(
      screen.queryByText("An unexpected error occurred"),
    ).not.toBeInTheDocument();
  });

  it("offline page", async () => {
    render(<OfflinePage />);

    expect(
      await screen.findByRole("heading", { name: "⟦offline.page_title⟧" }),
    ).toBeInTheDocument();
    expect(screen.getByText("⟦offline.page_body⟧")).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: "⟦common.try_again⟧" }),
    ).toBeInTheDocument();
    // The static Amharic line is deliberately kept as it was.
    expect(
      screen.getByText("ከኢንተርኔት ጋር ግንኙነት የለም። ኔትወርክዎን ያረጋግጡ።"),
    ).toBeInTheDocument();
  });

  it("command palette dialog title", async () => {
    render(
      <CommandDialog open>
        <CommandInput />
      </CommandDialog>,
    );

    expect(
      await screen.findByRole("dialog", { name: "⟦command.palette_title⟧" }),
    ).toBeInTheDocument();
  });

  it("leave balance progress bar label is interpolated, not a template literal", async () => {
    server.use(
      http.get("*/api/v1/dashboard/employee", () =>
        HttpResponse.json({
          attendance_today: null,
          leave_balances: [
            { type: "Annual", entitled: 16, used: 4.5, remaining: 11.5 },
          ],
          latest_payslip: null,
          upcoming_holidays: [],
          pending_approvals: 0,
          tenant_summary: {
            employee_count: 1,
            department_count: 1,
            branch_count: 1,
          },
          onboarding_complete: true,
        }),
      ),
    );
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    render(
      <QueryClientProvider client={queryClient}>
        <LeaveOverview />
      </QueryClientProvider>,
    );

    expect(
      await screen.findByRole("progressbar", {
        name: "⟦dashboard.leave_usage_aria Annual 28⟧",
      }),
    ).toBeInTheDocument();
  });
});

/**
 * Placeholders are props, not text, and most sit inside dialogs that need a
 * page's worth of mocked API to open — so these are checked in the source,
 * the way icon-button-names.test.ts checks accessible names.
 */
const ROOT = join(__dirname, "..");

function tsxFiles(dir: string): string[] {
  return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    if (entry.name === "test" || entry.name === "node_modules") return [];
    const path = join(dir, entry.name);
    if (entry.isDirectory()) return tsxFiles(path);
    return entry.name.endsWith(".tsx") ? [path] : [];
  });
}

const N37_PLACEHOLDERS = [
  "Morning Shift",
  "Optional — context for the transition",
  "Spouse, Parent...",
  "BSc, MSc, MBA...",
  "Computer Science...",
  "G1, Manager I, Level 5...",
  "Ethiopian",
  "Addis Ababa",
  "Commercial Bank of Ethiopia",
];

describe("N37 placeholders", () => {
  it("none of them is a hard-coded placeholder string any more", () => {
    const offenders = tsxFiles(ROOT).flatMap((file) => {
      const source = readFileSync(file, "utf8");
      return N37_PLACEHOLDERS.filter((text) =>
        source.includes(`placeholder="${text}"`),
      ).map((text) => `${relative(ROOT, file).replace(/\\/g, "/")}: "${text}"`);
    });

    expect(offenders).toEqual([]);
  });

  it("each one is still the English text a reader sees today", () => {
    const values = Object.values(en);
    for (const text of N37_PLACEHOLDERS) {
      expect(values).toContain(text);
    }
  });
});
