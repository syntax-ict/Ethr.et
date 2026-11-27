import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { toast } from "sonner";
import { server } from "./msw/server";
import { UnmatchedBadgesCard } from "@/features/devices/components/unmatched-badges-card";
import { CalendarProvider } from "@/lib/calendar/calendar-context";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const granted = vi.hoisted(() => ({ list: ["attendance.manage"] }));

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    hasPermission: (p: string) => granted.list.includes(p),
    can: {},
    role: "hr_admin",
  }),
}));

const saved = vi.hoisted(() => ({ files: [] as string[] }));
vi.mock("@/lib/utils/csv-export", async (original) => ({
  ...(await original<typeof import("@/lib/utils/csv-export")>()),
  saveBlob: (name: string) => saved.files.push(name),
}));

const built = vi.hoisted(() => ({ rows: [] as unknown[] }));
vi.mock("@/features/onboarding/v2/xlsx-grid", async (original) => ({
  ...(await original<typeof import("@/features/onboarding/v2/xlsx-grid")>()),
  buildTemplate: async (
    _spec: unknown,
    _calendar: unknown,
    _text: unknown,
    rows: unknown[],
  ) => {
    built.rows = rows;
    return new ArrayBuffer(8);
  },
}));

vi.mock("@/components/shared/employee-search-select", () => ({
  EmployeeSearchSelect: ({
    onChange,
    initialSearch,
  }: {
    onChange: (picked: { public_id: string; name: string }) => void;
    initialSearch?: string;
  }) => (
    <button
      type="button"
      data-initial-search={initialSearch ?? ""}
      onClick={() =>
        onChange({ public_id: "01HZEMP0000000000000000001", name: "Selam" })
      }
    >
      Pick Selam
    </button>
  ),
}));

/**
 * Punches from a badge no employee matched are held and shown here. Assigning
 * the badge replays them; dismissing drops them (2026-10-20, blueprint AT3).
 */
const ROW = {
  device_public_id: "01HZDEV0000000000000000001",
  device_name: "Main gate",
  badge: "7777",
  punches: 2,
  first_seen: "2026-10-14 05:01:22",
  last_seen: "2026-10-14 14:05:00",
};

/** What the confirmation reads before Assign: no warnings unless a test says so. */
const PREVIEW = {
  punches: 2,
  first_day: "2026-10-14",
  last_day: "2026-10-15",
  employee: {
    public_id: "01HZEMP0000000000000000001",
    name: "Selam",
    employee_code: null,
    department: null,
    position: null,
    branch: null,
    hire_date: null,
    status: "confirmed",
    badge_number: null,
  },
  warnings: [] as { code: string; value: string | number | null }[],
};

function renderCard(
  rows: unknown[],
  onPost: (path: string, body: unknown) => void,
) {
  server.use(
    http.get("*/api/v1/devices/unmatched-punches/preview", () =>
      HttpResponse.json(PREVIEW),
    ),
    http.get("*/api/v1/devices/unmatched-punches", () =>
      HttpResponse.json({ data: rows }),
    ),
    http.post(
      "*/api/v1/devices/unmatched-punches/:action",
      async ({ request, params }) => {
        onPost(String(params.action), await request.json());
        return HttpResponse.json(
          params.action === "assign"
            ? { replayed: 2, refused: 0 }
            : { dismissed: 2 },
        );
      },
    ),
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "U1", role: "hr_admin" },
        permissions: ["attendance.manage"],
        tenant: { timezone: "Africa/Addis_Ababa" },
      }),
    ),
  );
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>
      <CalendarProvider>{children}</CalendarProvider>
    </QueryClientProvider>
  );
  return render(<UnmatchedBadgesCard />, { wrapper: Wrapper });
}

describe("<UnmatchedBadgesCard>", () => {
  it("assigns a held badge to the chosen employee", async () => {
    const posts: Array<[string, unknown]> = [];
    const user = userEvent.setup();
    renderCard([ROW], (path, body) => posts.push([path, body]));

    expect(await screen.findByText("Badge 7777")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Pick Selam" }));
    await user.click(screen.getByRole("button", { name: "Assign" }));
    await user.click(
      await screen.findByRole("button", { name: "Confirm and assign" }),
    );

    await vi.waitFor(() =>
      expect(posts).toEqual([
        [
          "assign",
          {
            device_public_id: ROW.device_public_id,
            badge: "7777",
            employee_public_id: "01HZEMP0000000000000000001",
          },
        ],
      ]),
    );
  });

  it("asks before dismissing a badge", async () => {
    const posts: Array<[string, unknown]> = [];
    const user = userEvent.setup();
    renderCard([ROW], (path, body) => posts.push([path, body]));

    await user.click(await screen.findByRole("button", { name: "Dismiss" }));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText("Dismiss badge 7777?")).toBeInTheDocument();
    expect(posts).toEqual([]);

    await user.click(within(dialog).getByRole("button", { name: "Dismiss" }));
    await vi.waitFor(() =>
      expect(posts).toEqual([
        ["dismiss", { device_public_id: ROW.device_public_id, badge: "7777" }],
      ]),
    );
  });

  it("shows nothing when no badge is waiting", async () => {
    const { container } = renderCard([], () => {});
    await new Promise((r) => setTimeout(r, 50));
    expect(container).toBeEmptyDOMElement();
  });
});

// Each badge says what the server makes of it now (2026-11-12).
const SELAM = {
  employee_public_id: "01HZEMP0000000000000000009",
  employee_name: "Selam Tesfaye",
  score: 1,
  reasons: ["badge_number"],
};

describe("<UnmatchedBadgesCard> row states", () => {
  beforeEach(() => {
    granted.list = ["attendance.manage", "employee.create"];
    saved.files = [];
    built.rows = [];
  });

  // The Employees menu links to /devices#unmatched, and the card is not on
  // the page until its badges arrive, so the browser's jump finds nothing.
  it("scrolls to itself once it appears, when the address names it", async () => {
    const scrolled = vi.fn();
    Element.prototype.scrollIntoView = scrolled;
    window.history.replaceState(null, "", "/devices#unmatched");
    try {
      renderCard([ROW], () => {});
      expect(await screen.findByText("Badge 7777")).toBeInTheDocument();
      await vi.waitFor(() => expect(scrolled).toHaveBeenCalledTimes(1));
      expect(scrolled.mock.contexts[0]).toHaveProperty("id", "unmatched");
    } finally {
      window.history.replaceState(null, "", "/");
    }
  });

  it("names the holder of a badge the server now matches, and replays in one click", async () => {
    const posts: Array<[string, unknown]> = [];
    const user = userEvent.setup();
    renderCard(
      [
        {
          ...ROW,
          match: {
            outcome: "matched",
            employee_public_id: SELAM.employee_public_id,
            confidence: 1,
            candidates: [SELAM],
          },
        },
      ],
      (path, body) => posts.push([path, body]),
    );

    expect(
      await screen.findByText("This is Selam Tesfaye"),
    ).toBeInTheDocument();
    // No new employee for a badge someone already has.
    expect(
      screen.queryByRole("link", { name: "Add as a new employee" }),
    ).not.toBeInTheDocument();

    await user.click(
      screen.getByRole("button", { name: "Assign and replay 2 punches" }),
    );
    await user.click(
      await screen.findByRole("button", { name: "Confirm and assign" }),
    );
    await vi.waitFor(() =>
      expect(posts).toEqual([
        [
          "assign",
          {
            device_public_id: ROW.device_public_id,
            badge: "7777",
            employee_public_id: SELAM.employee_public_id,
          },
        ],
      ]),
    );
  });

  it("offers a possible match for a person to confirm, never in one click", async () => {
    const posts: Array<[string, unknown]> = [];
    const user = userEvent.setup();
    renderCard(
      [
        {
          ...ROW,
          match: {
            outcome: "probable",
            employee_public_id: SELAM.employee_public_id,
            confidence: 0.7,
            candidates: [{ ...SELAM, score: 0.7 }],
          },
        },
      ],
      (path, body) => posts.push([path, body]),
    );

    await user.click(
      await screen.findByRole("button", { name: "Possibly Selam Tesfaye" }),
    );
    expect(screen.getByText(/70%/)).toBeInTheDocument();
    expect(posts).toEqual([]);
    expect(
      screen.queryByRole("button", { name: /Assign and replay/ }),
    ).not.toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Assign" }));
    await user.click(
      await screen.findByRole("button", { name: "Confirm and assign" }),
    );
    await vi.waitFor(() =>
      expect(posts).toEqual([
        [
          "assign",
          expect.objectContaining({
            employee_public_id: SELAM.employee_public_id,
          }),
        ],
      ]),
    );
  });

  it("links a badge nobody has to a new employee, with the badge and the day it was first held", async () => {
    renderCard(
      [
        {
          ...ROW,
          // 22:30 UTC is already the next day in Addis Ababa.
          first_seen: "2026-10-13 22:30:00",
          match: {
            outcome: "new",
            employee_public_id: null,
            confidence: 0,
            candidates: [],
          },
        },
      ],
      () => {},
    );

    const link = await screen.findByRole("link", {
      name: "Add as a new employee",
    });
    const href = new URL(link.getAttribute("href")!, "http://x");
    expect(href.pathname).toBe("/employees/new");
    expect(href.searchParams.get("badge")).toBe("7777");
    expect(href.searchParams.get("device")).toBe(ROW.device_public_id);
    expect(href.searchParams.get("hire_date")).toBe("2026-10-14");
  });

  it("says so instead of offering a form for a badge that cannot be a badge number", async () => {
    renderCard(
      [
        {
          ...ROW,
          badge: "card#4411/ext",
          match: {
            outcome: "new",
            employee_public_id: null,
            confidence: 0,
            candidates: [],
          },
        },
      ],
      () => {},
    );

    expect(
      await screen.findByText(/cannot be saved as a badge number/),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("link", { name: "Add as a new employee" }),
    ).not.toBeInTheDocument();
  });

  it("does not offer a new employee to someone who cannot add one", async () => {
    granted.list = ["attendance.manage"];
    renderCard(
      [
        {
          ...ROW,
          match: {
            outcome: "new",
            employee_public_id: null,
            confidence: 0,
            candidates: [],
          },
        },
      ],
      () => {},
    );

    expect(await screen.findByText("Badge 7777")).toBeInTheDocument();
    expect(
      screen.queryByRole("link", { name: "Add as a new employee" }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Download these badges as a list" }),
    ).not.toBeInTheDocument();
  });

  it("downloads the employee template with one row per badge that still needs a person", async () => {
    const user = userEvent.setup();
    server.use(
      http.post("*/api/v1/employees/import/template", () =>
        HttpResponse.json({
          headers: ["name", "hire_date", "badge_number"],
          required: ["name"],
          date_columns: ["hire_date"],
          text_columns: ["badge_number"],
          lists: {},
        }),
      ),
    );
    const matched = {
      ...ROW,
      badge: "1001",
      match: {
        outcome: "matched",
        employee_public_id: SELAM.employee_public_id,
        confidence: 1,
        candidates: [SELAM],
      },
    };
    renderCard([ROW, { ...ROW, badge: "8888" }, matched], () => {});

    await user.click(
      await screen.findByRole("button", {
        name: "Download these badges as a list",
      }),
    );

    await vi.waitFor(() =>
      expect(saved.files).toEqual(["ethr-unrecognised-badges.xlsx"]),
    );
    expect(built.rows).toEqual([
      { badge_number: "7777" },
      { badge_number: "8888" },
    ]);
  });
});

// One request replays for a few seconds and says how many are left; the card
// asks again with the same inputs until none are (2026-11-16).
describe("<UnmatchedBadgesCard> carrying on", () => {
  const MANY = { ...ROW, punches: 3 };

  function answers(
    list: Array<
      { replayed: number; refused: number; remaining: number } | number
    >,
    posts: unknown[],
    gates: Array<Promise<void> | undefined> = [],
  ) {
    let call = 0;
    return http.post(
      "*/api/v1/devices/unmatched-punches/assign",
      async ({ request }) => {
        posts.push(await request.json());
        const n = call++;
        await gates[n];
        const answer = list[n];
        return typeof answer === "number"
          ? HttpResponse.json(
              {
                detail:
                  "These punches are already being brought in. Try again in a moment.",
              },
              { status: answer },
            )
          : HttpResponse.json(answer);
      },
    );
  }

  async function assignFirst() {
    const user = userEvent.setup();
    await user.click(await screen.findByRole("button", { name: "Pick Selam" }));
    await user.click(screen.getByRole("button", { name: "Assign" }));
    await user.click(
      await screen.findByRole("button", { name: "Confirm and assign" }),
    );
    return user;
  }

  beforeEach(() => {
    granted.list = ["attendance.manage"];
    vi.mocked(toast.success).mockClear();
    vi.mocked(toast.error).mockClear();
  });

  it("asks again with the same inputs until nothing is left, and says the summed total once", async () => {
    const posts: unknown[] = [];
    renderCard([MANY], () => {});
    server.use(
      answers(
        [
          { replayed: 1, refused: 0, remaining: 2 },
          { replayed: 1, refused: 0, remaining: 1 },
          { replayed: 1, refused: 0, remaining: 0 },
        ],
        posts,
      ),
    );

    await assignFirst();

    await vi.waitFor(() =>
      expect(toast.success).toHaveBeenCalledWith(
        "3 held punches were recorded.",
      ),
    );
    expect(toast.success).toHaveBeenCalledTimes(1);
    const body = {
      device_public_id: ROW.device_public_id,
      badge: "7777",
      employee_public_id: "01HZEMP0000000000000000001",
    };
    expect(posts).toEqual([body, body, body]);
  });

  it("shows how far it has got, stops when asked and carries on from there", async () => {
    const posts: unknown[] = [];
    let release: () => void = () => {};
    const gate = new Promise<void>((resolve) => {
      release = resolve;
    });
    renderCard([MANY], () => {});
    server.use(
      answers(
        [
          { replayed: 1, refused: 0, remaining: 2 },
          { replayed: 1, refused: 0, remaining: 1 },
          { replayed: 1, refused: 0, remaining: 0 },
        ],
        posts,
        [undefined, gate],
      ),
    );

    const user = await assignFirst();
    expect(
      await screen.findByText("1 brought in, 2 still to go"),
    ).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Stop" }));
    release();

    expect(
      await screen.findByText("Stopped. 2 brought in, 1 still to go"),
    ).toBeInTheDocument();
    expect(posts).toHaveLength(2);
    expect(toast.success).not.toHaveBeenCalled();

    await user.click(screen.getByRole("button", { name: "Carry on" }));
    await vi.waitFor(() =>
      expect(toast.success).toHaveBeenCalledWith(
        "3 held punches were recorded.",
      ),
    );
    expect(posts).toHaveLength(3);
  });

  it("stops on a failure, says why, and carries on with the same inputs", async () => {
    const posts: unknown[] = [];
    renderCard([MANY], () => {});
    server.use(
      answers(
        [
          { replayed: 1, refused: 0, remaining: 2 },
          409,
          { replayed: 2, refused: 0, remaining: 0 },
        ],
        posts,
      ),
    );

    const user = await assignFirst();
    expect(
      await screen.findByText(
        "These punches are already being brought in. Try again in a moment. 1 brought in, 2 still to go",
      ),
    ).toBeInTheDocument();
    expect(posts).toHaveLength(2);

    await user.click(screen.getByRole("button", { name: "Carry on" }));

    await vi.waitFor(() =>
      expect(toast.success).toHaveBeenCalledWith(
        "3 held punches were recorded.",
      ),
    );
    expect(posts).toHaveLength(3);
    expect(posts[2]).toEqual(posts[1]);
  });
});

// A Hikvision terminal sends the person's name beside the number; the card
// shows it, and the people it could be, for a person to choose from.
describe("<UnmatchedBadgesCard> the name a terminal sends", () => {
  const NEW = {
    outcome: "new",
    employee_public_id: null,
    confidence: 0,
    candidates: [],
  };
  const ABEBE = {
    employee_public_id: "01HZEMP0000000000000000042",
    employee_name: "Abebe Kebede Tesfaye",
    department: "Land Office",
  };

  beforeEach(() => {
    granted.list = ["attendance.manage", "employee.create"];
    built.rows = [];
  });

  it("says what the terminal calls the number, and fills the select from a same-name match without assigning", async () => {
    const posts: Array<[string, unknown]> = [];
    const user = userEvent.setup();
    renderCard(
      [
        {
          ...ROW,
          match: NEW,
          name_on_device: "Abebe Kebede",
          name_matches: [ABEBE],
        },
      ],
      (path, body) => posts.push([path, body]),
    );

    expect(
      await screen.findByText("The terminal calls this person Abebe Kebede"),
    ).toBeInTheDocument();
    expect(screen.getByText(/Land Office/)).toBeInTheDocument();
    expect(screen.getByText(/same name on the terminal/)).toBeInTheDocument();

    await user.click(
      screen.getByRole("button", { name: "Possibly Abebe Kebede Tesfaye" }),
    );
    expect(posts).toEqual([]);

    // The pick is the select's value: Assign now sends that person.
    await user.click(screen.getByRole("button", { name: "Assign" }));
    await user.click(
      await screen.findByRole("button", { name: "Confirm and assign" }),
    );
    await vi.waitFor(() =>
      expect(posts).toEqual([
        [
          "assign",
          expect.objectContaining({
            employee_public_id: ABEBE.employee_public_id,
          }),
        ],
      ]),
    );
  });

  it("does not list a person twice when the resolver already offers them", async () => {
    renderCard(
      [
        {
          ...ROW,
          match: {
            outcome: "probable",
            employee_public_id: ABEBE.employee_public_id,
            confidence: 0.7,
            candidates: [{ ...ABEBE, score: 0.7, reasons: ["badge_number"] }],
          },
          name_on_device: "Abebe Kebede",
          name_matches: [ABEBE],
        },
      ],
      () => {},
    );

    await screen.findByRole("button", {
      name: "Possibly Abebe Kebede Tesfaye",
    });
    expect(
      screen.getAllByRole("button", { name: "Possibly Abebe Kebede Tesfaye" }),
    ).toHaveLength(1);
  });

  it("keeps the name out of the new-employee link, and puts it in the downloaded list", async () => {
    const user = userEvent.setup();
    server.use(
      http.post("*/api/v1/employees/import/template", () =>
        HttpResponse.json({
          headers: ["name", "hire_date", "badge_number"],
          required: ["name"],
          date_columns: ["hire_date"],
          text_columns: ["badge_number"],
          lists: {},
        }),
      ),
    );
    renderCard(
      [
        { ...ROW, match: NEW, name_on_device: "Abebe Kebede" },
        { ...ROW, badge: "8888", match: NEW },
      ],
      () => {},
    );

    const links = await screen.findAllByRole("link", {
      name: "Add as a new employee",
    });
    const href = new URL(links[0].getAttribute("href")!, "http://x");
    // A name in the address would be written to the server's access log.
    expect(href.searchParams.has("name")).toBe(false);
    expect(href.searchParams.get("badge")).toBe("7777");
    expect(links[0].getAttribute("href")).not.toContain("Abebe");

    await user.click(
      screen.getByRole("button", { name: "Download these badges as a list" }),
    );
    await vi.waitFor(() =>
      expect(built.rows).toEqual([
        { badge_number: "7777", name: "Abebe Kebede" },
        { badge_number: "8888" },
      ]),
    );
  });

  it("says a badge matched by number is being recorded by itself, and keeps Assign", async () => {
    const matched = {
      outcome: "matched",
      employee_public_id: SELAM.employee_public_id,
      confidence: 1,
      candidates: [SELAM],
    };
    renderCard([{ ...ROW, match: matched, recording_itself: true }], () => {});

    expect(
      await screen.findByText(
        "Matched by its number and the name on the terminal. The held punches are being recorded.",
      ),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/Write this number in a person's Badge number/),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: "Assign and replay 2 punches" }),
    ).toBeInTheDocument();
  });

  it("says nothing of recording itself for a badge matched by name or not at all", async () => {
    renderCard([{ ...ROW, match: NEW, recording_itself: false }], () => {});

    expect(await screen.findByText("Badge 7777")).toBeInTheDocument();
    expect(screen.queryByText(/are being recorded/)).not.toBeInTheDocument();
  });

  it("shows nothing about a name when the server sent none", async () => {
    renderCard(
      [{ ...ROW, match: NEW, name_on_device: null, name_matches: [] }],
      () => {},
    );

    expect(await screen.findByText("Badge 7777")).toBeInTheDocument();
    expect(screen.queryByText(/The terminal calls/)).not.toBeInTheDocument();
    expect(
      screen.queryByText(/same name on the terminal/),
    ).not.toBeInTheDocument();
  });

  it("shows no name to someone who cannot manage attendance", async () => {
    granted.list = [];
    renderCard(
      [{ ...ROW, name_on_device: "Abebe Kebede", name_matches: [ABEBE] }],
      () => {},
    );

    expect(await screen.findByText("Badge 7777")).toBeInTheDocument();
    expect(screen.queryByText(/The terminal calls/)).not.toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: /Possibly/ }),
    ).not.toBeInTheDocument();
  });
});

describe("<UnmatchedBadgesCard> the identity line and the warnings", () => {
  const SEBLE = {
    employee_public_id: "01HZEMP0000000000000000077",
    employee_name: "Seblewengel Mekonen Tesfaye",
    department: "Land Office",
    fit: "similar" as const,
    identity: {
      employee_code: "WOR-0042",
      department: "Land Office",
      position: "Surveyor",
      branch: "Habru Branch",
      hire_date: "2026-11-11",
      status: "confirmed",
      badge_number: "W-77",
      warnings: [
        { code: "other_badge", value: "W-77" },
        { code: "hired_after", value: "2026-11-10" },
        { code: "own_attendance", value: 2 },
      ],
    },
  };
  const NEW = {
    outcome: "new",
    employee_public_id: null,
    confidence: 0,
    candidates: [],
  };

  beforeEach(() => {
    granted.list = ["attendance.manage"];
  });

  it("shows who a similar-name person is, with the label and each warning", async () => {
    renderCard(
      [
        {
          ...ROW,
          match: NEW,
          name_on_device: "Seblewongel Mekonnen",
          name_matches: [SEBLE],
        },
      ],
      () => {},
    );

    await screen.findByRole("button", {
      name: "Possibly Seblewengel Mekonen Tesfaye",
    });
    expect(
      screen.getByText(/similar name on the terminal/),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/No\. WOR-0042 · Land Office · Surveyor · Habru Branch/),
    ).toBeInTheDocument();
    expect(
      screen.getByText("Already has badge number W-77"),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/Hired after this badge's first punch on/),
    ).toBeInTheDocument();
    expect(
      screen.getByText(
        "Already has attendance of their own on 2 of the days this badge punched",
      ),
    ).toBeInTheDocument();
  });

  it("gives the resolver's possible people the same line", async () => {
    renderCard(
      [
        {
          ...ROW,
          match: {
            outcome: "probable",
            employee_public_id: SEBLE.employee_public_id,
            confidence: 0.7,
            candidates: [
              {
                employee_public_id: SEBLE.employee_public_id,
                employee_name: SEBLE.employee_name,
                score: 0.7,
                reasons: ["badge_number"],
                identity: { ...SEBLE.identity, warnings: [] },
              },
            ],
          },
        },
      ],
      () => {},
    );

    await screen.findByRole("button", {
      name: "Possibly Seblewengel Mekonen Tesfaye",
    });
    expect(screen.getByText(/No\. WOR-0042/)).toBeInTheDocument();
    expect(screen.getByText(/70%/)).toBeInTheDocument();
    expect(screen.queryByText(/Already has/)).not.toBeInTheDocument();
  });

  it("shows no warning for a person with none, and an exact name keeps its old label", async () => {
    renderCard(
      [
        {
          ...ROW,
          match: NEW,
          name_on_device: "Seblewengel Mekonen",
          name_matches: [
            {
              ...SEBLE,
              fit: "same" as const,
              identity: { ...SEBLE.identity, badge_number: null, warnings: [] },
            },
          ],
        },
      ],
      () => {},
    );

    await screen.findByRole("button", {
      name: "Possibly Seblewengel Mekonen Tesfaye",
    });
    expect(screen.getByText(/same name on the terminal/)).toBeInTheDocument();
    expect(screen.queryByText(/similar name/)).not.toBeInTheDocument();
    expect(screen.queryByText(/Already has/)).not.toBeInTheDocument();
  });

  it("opens the picker already searched by the given name the terminal sent", async () => {
    const { container } = renderCard(
      [{ ...ROW, match: NEW, name_on_device: "Seblewongel Mekonnen" }],
      () => {},
    );

    await screen.findByText("Badge 7777");
    expect(
      container.querySelector("[data-initial-search='Seblewongel']"),
    ).not.toBeNull();
  });

  it("seeds nothing when the terminal sent no name", async () => {
    const { container } = renderCard([{ ...ROW, match: NEW }], () => {});

    await screen.findByText("Badge 7777");
    expect(container.querySelector("[data-initial-search='']")).not.toBeNull();
  });
});

describe("<UnmatchedBadgesCard> confirmation before assigning", () => {
  beforeEach(() => {
    granted.list = ["attendance.manage"];
  });

  const WARNED = {
    ...PREVIEW,
    employee: {
      ...PREVIEW.employee,
      employee_code: "WOR-9",
      department: "Land Office",
    },
    warnings: [
      { code: "other_badge", value: "W-77" },
      { code: "moves_from", value: "Kebede Alemu" },
    ],
  };

  it("opens a dialog instead of assigning, and shows the count, the days and the person", async () => {
    const posts: Array<[string, unknown]> = [];
    const user = userEvent.setup();
    renderCard([ROW], (path, body) => posts.push([path, body]));

    await user.click(await screen.findByRole("button", { name: "Pick Selam" }));
    await user.click(screen.getByRole("button", { name: "Assign" }));

    const dialog = await screen.findByRole("dialog");
    expect(
      await within(dialog).findByText(
        /Held punches that go into their attendance: 2, from/,
      ),
    ).toBeInTheDocument();
    expect(within(dialog).getByText("Selam")).toBeInTheDocument();
    expect(
      within(dialog).getByText(/Badge 7777 sent by Main gate/),
    ).toBeInTheDocument();
    expect(posts).toEqual([]);
    expect(
      within(dialog).getByRole("button", { name: "Confirm and assign" }),
    ).toBeEnabled();
    expect(within(dialog).queryByRole("checkbox")).not.toBeInTheDocument();

    await user.click(
      within(dialog).getByRole("button", { name: "Confirm and assign" }),
    );
    await vi.waitFor(() =>
      expect(posts).toEqual([
        [
          "assign",
          {
            device_public_id: ROW.device_public_id,
            badge: "7777",
            employee_public_id: "01HZEMP0000000000000000001",
          },
        ],
      ]),
    );
  });

  it("keeps Confirm off until the warnings are ticked, then sends accept_warnings", async () => {
    const posts: Array<[string, unknown]> = [];
    const user = userEvent.setup();
    renderCard([ROW], (path, body) => posts.push([path, body]));
    server.use(
      http.get("*/api/v1/devices/unmatched-punches/preview", () =>
        HttpResponse.json(WARNED),
      ),
    );

    await user.click(await screen.findByRole("button", { name: "Pick Selam" }));
    await user.click(screen.getByRole("button", { name: "Assign" }));

    const dialog = await screen.findByRole("dialog");
    const confirm = within(dialog).getByRole("button", {
      name: "Confirm and assign",
    });
    await within(dialog).findByText(/Already has badge number W-77/);
    expect(
      dialog.querySelector('[data-warning="moves_from"]'),
    ).toHaveTextContent("matched to Kebede Alemu and will be moved from them");
    expect(confirm).toBeDisabled();

    await user.click(
      within(dialog).getByRole("checkbox", {
        name: "I have checked these and this is the right person",
      }),
    );
    expect(confirm).toBeEnabled();
    await user.click(confirm);

    await vi.waitFor(() =>
      expect(posts).toEqual([
        [
          "assign",
          {
            device_public_id: ROW.device_public_id,
            badge: "7777",
            employee_public_id: "01HZEMP0000000000000000001",
            accept_warnings: true,
          },
        ],
      ]),
    );
  });

  it("says another employee when the holder may not be named", async () => {
    const user = userEvent.setup();
    renderCard([ROW], () => {});
    server.use(
      http.get("*/api/v1/devices/unmatched-punches/preview", () =>
        HttpResponse.json({
          ...PREVIEW,
          warnings: [{ code: "moves_from", value: null }],
        }),
      ),
    );

    await user.click(await screen.findByRole("button", { name: "Pick Selam" }));
    await user.click(screen.getByRole("button", { name: "Assign" }));

    expect(
      await screen.findByText(/matched to another employee and will be moved/),
    ).toBeInTheDocument();
  });

  it("leaves Confirm off when the preview cannot be read, and reads it again on Try again", async () => {
    let calls = 0;
    const user = userEvent.setup();
    renderCard([ROW], () => {});
    server.use(
      http.get("*/api/v1/devices/unmatched-punches/preview", () => {
        calls += 1;
        return calls === 1
          ? HttpResponse.json({ detail: "Nope." }, { status: 500 })
          : HttpResponse.json(PREVIEW);
      }),
    );

    await user.click(await screen.findByRole("button", { name: "Pick Selam" }));
    await user.click(screen.getByRole("button", { name: "Assign" }));

    const dialog = await screen.findByRole("dialog");
    await within(dialog).findByRole("alert");
    expect(
      within(dialog).getByRole("button", { name: "Confirm and assign" }),
    ).toBeDisabled();

    await user.click(within(dialog).getByRole("button", { name: "Try again" }));
    await vi.waitFor(() =>
      expect(
        within(dialog).getByRole("button", { name: "Confirm and assign" }),
      ).toBeEnabled(),
    );
  });
});
