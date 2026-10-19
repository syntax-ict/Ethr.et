import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import {
  CommandPalette,
  openCommandPalette,
} from "@/components/shared/command-palette";
import { act } from "react";
import type { GlobalSearchResult } from "@/features/search/api";

const pushMock = vi.fn();
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: pushMock }),
}));

function mockUser(role: string, permissions: string[] = []) {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "01HZUSER", name: "Test", role },
        permissions,
        tenant: null,
      }),
    ),
  );
}

const EMPTY: GlobalSearchResult = {
  query: "",
  employees: [],
  attendance: [],
  leave: [],
  departments: [],
};

function mockSearch(
  result: Partial<GlobalSearchResult>,
  onHit?: (q: string | null) => void,
) {
  server.use(
    http.get("*/api/v1/search", ({ request }) => {
      const q = new URL(request.url).searchParams.get("q");
      onHit?.(q);
      return HttpResponse.json({ ...EMPTY, query: q ?? "", ...result });
    }),
  );
}

function renderPalette() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={queryClient}>
      <CommandPalette />
    </QueryClientProvider>,
  );
}

const placeholder = /search people, attendance, leave/i;

describe("CommandPalette global search", () => {
  beforeEach(() => {
    pushMock.mockReset();
    // Only this feature's key: the test setup keeps the locale in storage too.
    window.localStorage.removeItem("ethr.search.recent.01HZUSER");
  });

  it("finds a person and opens their record", async () => {
    mockUser("hr_admin");
    mockSearch({
      employees: [
        {
          id: "01HZEMP1",
          name: "Abebe Kebede",
          employee_code: "EMP-0042",
          department: "Finance",
          position: "Officer",
        },
      ],
    });

    const user = userEvent.setup();
    renderPalette();

    await user.keyboard("{Meta>}k{/Meta}");
    await user.type(await screen.findByPlaceholderText(placeholder), "abebe");

    const option = await screen.findByRole("option", {
      name: /Abebe Kebede/,
    });
    // The context a person needs to pick the right Abebe.
    expect(option).toHaveTextContent("EMP-0042 · Officer · Finance");
    await user.click(option);

    await waitFor(() =>
      expect(pushMock).toHaveBeenCalledWith("/employees/01HZEMP1"),
    );
  });

  it("selects the first record when results arrive, so Enter opens it", async () => {
    // Found 2026-10-19 in the browser: while the request was in flight a page
    // link matched first and kept the selection, so Enter opened Organization
    // instead of the person the search had just found.
    mockUser("hr_admin", ["org.create"]);
    mockSearch({
      employees: [
        {
          id: "01HZEMP1",
          name: "Abebe Kebede",
          employee_code: "EMP-0042",
          department: null,
          position: null,
        },
      ],
    });

    const user = userEvent.setup();
    renderPalette();

    await user.keyboard("{Meta>}k{/Meta}");
    await user.type(await screen.findByPlaceholderText(placeholder), "abe");
    const option = await screen.findByRole("option", { name: /Abebe/ });
    await waitFor(() =>
      expect(option).toHaveAttribute("aria-selected", "true"),
    );

    await user.keyboard("{Enter}");
    expect(pushMock).toHaveBeenLastCalledWith("/employees/01HZEMP1");
  });

  it("groups attendance, leave and departments, each linking where it opens", async () => {
    mockUser("hr_admin");
    mockSearch({
      attendance: [
        { id: "01HZEMP1", name: "Abebe Kebede", employee_code: null },
      ],
      leave: [
        {
          id: "01HZLEAVE1",
          employee_name: "Abebe Kebede",
          leave_type: "Annual leave",
          start_date: "2026-10-20",
          end_date: "2026-10-22",
          status: "pending",
          own: false,
        },
      ],
      departments: [{ id: "01HZDEPT1", name: "Abebe Logistics", code: "LOG" }],
    });

    const user = userEvent.setup();
    renderPalette();

    await user.keyboard("{Control>}k{/Control}");
    await user.type(await screen.findByPlaceholderText(placeholder), "abebe");

    const attendance = await screen.findByRole("group", { name: "Attendance" });
    await user.click(within(attendance).getByRole("option"));
    expect(pushMock).toHaveBeenLastCalledWith(
      "/employees/01HZEMP1?tab=attendance",
    );

    await user.keyboard("{Control>}k{/Control}");
    await user.type(await screen.findByPlaceholderText(placeholder), "abebe");
    await user.click(
      await screen.findByRole("option", { name: /Annual leave/ }),
    );
    expect(pushMock).toHaveBeenLastCalledWith("/leave?tab=team");

    await user.keyboard("{Control>}k{/Control}");
    await user.type(await screen.findByPlaceholderText(placeholder), "abebe");
    await user.click(
      await screen.findByRole("option", { name: /Abebe Logistics/ }),
    );
    expect(pushMock).toHaveBeenLastCalledWith("/organization?tab=departments");
  });

  it("remembers what was opened and offers it next time, for this user", async () => {
    mockUser("hr_admin");
    mockSearch({
      employees: [
        {
          id: "01HZEMP1",
          name: "Abebe Kebede",
          employee_code: "EMP-0042",
          department: null,
          position: null,
        },
      ],
    });

    const user = userEvent.setup();
    renderPalette();

    await user.keyboard("{Meta>}k{/Meta}");
    await user.type(await screen.findByPlaceholderText(placeholder), "abebe");
    await user.click(await screen.findByRole("option", { name: /Abebe/ }));

    await user.keyboard("{Meta>}k{/Meta}");
    const recent = await screen.findByRole("group", { name: "Recent" });
    expect(within(recent).getByRole("option")).toHaveTextContent(
      "Abebe Kebede",
    );
    expect(
      JSON.parse(window.localStorage.getItem("ethr.search.recent.01HZUSER")!),
    ).toHaveLength(1);
  });

  it("opens with / when no field has focus, and leaves / alone in a field", async () => {
    mockUser("employee");
    const user = userEvent.setup();
    render(
      <QueryClientProvider client={new QueryClient()}>
        <input aria-label="notes" />
        <CommandPalette />
      </QueryClientProvider>,
    );

    await user.type(screen.getByLabelText("notes"), "a/b");
    expect(screen.queryByPlaceholderText(placeholder)).not.toBeInTheDocument();
    expect(screen.getByLabelText("notes")).toHaveValue("a/b");

    (document.activeElement as HTMLElement | null)?.blur();
    await user.keyboard("/");
    expect(await screen.findByPlaceholderText(placeholder)).toBeInTheDocument();
  });

  it("opens from openCommandPalette() without a keypress (header/mobile trigger)", async () => {
    mockUser("employee");
    renderPalette();

    expect(screen.queryByPlaceholderText(placeholder)).not.toBeInTheDocument();

    act(() => openCommandPalette());

    expect(await screen.findByPlaceholderText(placeholder)).toBeInTheDocument();
  });

  it("does not search records until two letters are typed, and shows key hints", async () => {
    mockUser("employee");
    const hits: Array<string | null> = [];
    mockSearch({}, (q) => hits.push(q));

    const user = userEvent.setup();
    renderPalette();

    await user.keyboard("{Meta>}k{/Meta}");
    await user.type(await screen.findByPlaceholderText(placeholder), "a");
    await new Promise((r) => setTimeout(r, 350));
    expect(hits).toEqual([]);
    expect(screen.getByText(/to move/i)).toBeInTheDocument();
    expect(screen.getByText(/to open/i)).toBeInTheDocument();

    await user.type(screen.getByPlaceholderText(placeholder), "b");
    await waitFor(() => expect(hits).toEqual(["ab"]));
  });

  it("says plainly when nothing the user can open matches", async () => {
    mockUser("employee");
    mockSearch({});

    const user = userEvent.setup();
    renderPalette();

    await user.keyboard("{Meta>}k{/Meta}");
    await user.type(await screen.findByPlaceholderText(placeholder), "zzqqxx");

    expect(
      await screen.findByText(/nothing you can open matches "zzqqxx"/i),
    ).toBeInTheDocument();
  });
});
