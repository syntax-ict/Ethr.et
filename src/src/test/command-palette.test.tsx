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

/**
 * A goal typed in plain words finds the task or page that answers it, for the
 * people allowed to open it, and opening it only navigates.
 */
describe("CommandPalette tasks and pages", () => {
  beforeEach(() => {
    pushMock.mockReset();
    window.localStorage.removeItem("ethr.search.recent.01HZUSER");
  });

  async function openAndType(text: string) {
    const user = userEvent.setup();
    await user.keyboard("{Meta>}k{/Meta}");
    await user.type(await screen.findByRole("combobox"), text);
    return user;
  }

  it("opens today's absent list for someone who may see all attendance", async () => {
    mockUser("hr_admin", ["attendance.viewAll"]);
    mockSearch({});
    renderPalette();

    const user = await openAndType("who is absent today");
    const option = await screen.findByRole("option", {
      name: /who is absent today/i,
    });
    // The row says what opening it does.
    expect(option).toHaveTextContent(/filtered to the people marked absent/i);
    await user.click(option);

    const today = new Date().toISOString().slice(0, 10);
    expect(pushMock).toHaveBeenLastCalledWith(
      `/attendance?status=absent&from=${today}&to=${today}`,
    );
  });

  it("sends a supervisor to the team page for the same question", async () => {
    mockUser("supervisor", ["attendance.viewTeam"]);
    mockSearch({});
    renderPalette();

    const user = await openAndType("absent today");
    await user.click(
      await screen.findByRole("option", { name: /who is absent today/i }),
    );
    expect(pushMock).toHaveBeenLastCalledWith("/attendance/team");
  });

  it("never offers approvals to someone who cannot decide them", async () => {
    mockUser("employee");
    mockSearch({});
    renderPalette();

    await openAndType("approve a leave request");
    // The ask still lands somewhere: the person's own leave page.
    await screen.findByRole("option", { name: /request leave/i });
    expect(
      screen.queryByRole("option", { name: /pending approvals/i }),
    ).not.toBeInTheDocument();
  });

  it("opens the payroll list for 'prepare payroll' and nothing more", async () => {
    mockUser("finance_admin", ["payroll.process", "payroll.viewAll"]);
    mockSearch({});
    renderPalette();

    const user = await openAndType("prepare this month's payroll");
    const option = await screen.findByRole("option", { name: /run payroll/i });
    expect(option).toHaveTextContent(/nothing is paid until it is approved/i);
    await user.click(option);

    expect(pushMock).toHaveBeenCalledTimes(1);
    expect(pushMock).toHaveBeenLastCalledWith("/payroll");
  });

  it("offers more than one answer to an ambiguous ask", async () => {
    mockUser("supervisor", ["attendance.viewTeam", "leave.approve"]);
    mockSearch({});
    renderPalette();

    await openAndType("leave request");
    await screen.findByRole("option", { name: /review pending approvals/i });
    expect(
      screen.getByRole("option", { name: /request leave/i }),
    ).toBeInTheDocument();
    expect((await screen.findAllByRole("option")).length).toBeGreaterThan(1);
  });

  it("lists a child page with its group, and the page's own description", async () => {
    mockUser("hr_admin", ["attendance.viewAll"]);
    mockSearch({});
    renderPalette();

    await openAndType("attendance records");
    const option = await screen.findByRole("option", {
      name: /attendance.*records/i,
    });
    expect(option).toHaveTextContent(/track and manage employee attendance/i);
  });

  it("answers an ask typed in Amharic", async () => {
    mockUser("employee");
    mockSearch({});
    window.localStorage.setItem("locale", "am");
    window.dispatchEvent(new Event("locale-changed"));
    try {
      renderPalette();

      const user = await openAndType("ፈቃድ ጠይቅ");
      await user.click(await screen.findByRole("option", { name: /ፈቃድ ጠይቅ/ }));
      expect(pushMock).toHaveBeenLastCalledWith("/leave");
    } finally {
      window.localStorage.setItem("locale", "en");
      window.dispatchEvent(new Event("locale-changed"));
    }
  });

  it("points at the kinds of thing that can be searched when nothing matches", async () => {
    mockUser("employee");
    mockSearch({});
    renderPalette();

    await openAndType("zzqqxx");
    expect(
      await screen.findByText(/a task such as "request leave"/i),
    ).toBeInTheDocument();
  });

  it("selects the best answer, even after a query that hid it", async () => {
    // Found 2026-10-22 in the browser: typed over "change my phone number",
    // "who is late today" kept "Who is absent today" first and selected, so
    // Enter opened the absent list.
    mockUser("hr_admin", ["attendance.viewAll"]);
    mockSearch({});
    renderPalette();

    const user = await openAndType("change my phone number");
    const input = screen.getByRole("combobox");
    await user.clear(input);
    await user.type(input, "who is late today");

    const options = await screen.findAllByRole("option");
    expect(options[0]).toHaveTextContent(/who is late today/i);
    await waitFor(() =>
      expect(options[0]).toHaveAttribute("aria-selected", "true"),
    );
    await user.keyboard("{Enter}");
    expect(pushMock).toHaveBeenCalledWith(
      expect.stringContaining("/attendance?status=late"),
    );
  });

  it("puts the page first when it answers better than any task", async () => {
    mockUser("hr_admin", ["employee.create"]);
    mockSearch({});
    renderPalette();

    await openAndType("find an employee");
    const options = await screen.findAllByRole("option");
    expect(options[0]).toHaveTextContent(/^Employees/);
    expect(screen.getByRole("option", { name: /add employee/i })).toBeTruthy();
  });

  it("offers the person's own tasks when nothing matches, and opening one only navigates", async () => {
    mockUser("employee");
    mockSearch({});
    renderPalette();

    const user = await openAndType("zzqqxx");
    await screen.findByText(/tasks you can start/i);
    // An employee's tasks, never an administrator's.
    expect(
      screen.queryByRole("button", { name: /add employee/i }),
    ).not.toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: /check in or out/i }));
    expect(pushMock).toHaveBeenCalledWith("/attendance");
  });
});
