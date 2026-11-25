import { beforeEach, describe, it, expect, vi } from "vitest";
import {
  render,
  screen,
  fireEvent,
  waitFor,
  within,
} from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { Workbook } from "exceljs";
import { server } from "./msw/server";
import {
  MigrationStep,
  actionsFor,
} from "@/features/onboarding/v2/components/migration-step";
import { readWorkbook } from "@/features/onboarding/v2/xlsx-grid";
import type {
  MigrationBatch,
  PeopleRead,
} from "@/features/onboarding/v2/types";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const saved: { name: string; blob: Blob }[] = [];
vi.mock("@/lib/utils/csv-export", () => ({
  saveBlob: (name: string, blob: Blob) => saved.push({ name, blob }),
  saveCsv: vi.fn(),
}));

function renderWithClient(ui: React.ReactElement) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>{ui}</QueryClientProvider>,
  );
}

const AMBIGUOUS_BATCH: MigrationBatch = {
  public_id: "01HZBATCH0000000000000001",
  source_type: "csv",
  source_ref: null,
  status: "reviewing",
  totals: null,
  summary: { ambiguous: 1 },
  rows: [
    {
      public_id: "01HZROW00000000000000001",
      display_name: "New Arrival",
      external_identifier: null,
      match_outcome: "ambiguous",
      match_confidence: 0.7,
      candidates: [
        {
          employee_public_id: "01HZEMP0000000000000001",
          employee_name: "Person A",
          score: 0.7,
          reasons: ["phone"],
        },
        {
          employee_public_id: "01HZEMP0000000000000002",
          employee_name: "Person B",
          score: 0.7,
          reasons: ["phone"],
        },
      ],
      resolved_employee_public_id: null,
      action: "defer",
      changes: [],
      can_apply_changes: false,
      scheduled_action: false,
      processed_at: null,
    },
  ],
};

/** What the server answers about a list it can stage as it stands. */
const READ_OK: PeopleRead = {
  people: 1,
  header: false,
  columns: [],
  ignored: [],
  calendar: "gregorian",
  calendar_from_header: false,
  without_hire_date: 0,
  problems: [],
  warnings: [],
  headers: [],
  header_likely: false,
  needs_name: false,
  source: null,
  suggested_source: null,
};

const WARNED_BATCH = {
  ...AMBIGUOUS_BATCH,
  rows: [
    {
      ...AMBIGUOUS_BATCH.rows[0],
      warnings: [
        {
          code: "device_id_not_badge",
          message: "The terminal number A_1 is kept as the terminal's own.",
        },
      ],
    },
  ],
};

/** The list is read by the server; each test says what it answers. */
function reads(answer: Partial<PeopleRead> = {}) {
  const asked: { grid: string[][]; calendar: string }[] = [];
  server.use(
    http.post("*/onboarding/migration/read", async ({ request }) => {
      asked.push((await request.json()) as (typeof asked)[number]);
      return HttpResponse.json({ ...READ_OK, ...answer });
    }),
  );
  return asked;
}

describe("<MigrationStep>", () => {
  beforeEach(() => {
    reads();
  });

  it("stages rows and surfaces candidate names, scores, and reasons for an ambiguous match", async () => {
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
      http.post("*/onboarding/migration/rows", () =>
        HttpResponse.json(AMBIGUOUS_BATCH, { status: 201 }),
      ),
      http.get("*/onboarding/migration/batches/:batchId", () =>
        HttpResponse.json(AMBIGUOUS_BATCH),
      ),
    );

    const { container } = renderWithClient(<MigrationStep />);

    const textarea = container.querySelector("textarea") as HTMLTextAreaElement;
    fireEvent.change(textarea, {
      target: { value: "New Arrival, , 0911000000" },
    });
    fireEvent.click(screen.getByText("Stage for review"));

    expect(await screen.findByText("Person A")).toBeInTheDocument();
    expect(screen.getByText("Person B")).toBeInTheDocument();
    expect(screen.getAllByText(/70%/).length).toBeGreaterThan(0);
    expect(screen.getAllByText(/phone/).length).toBeGreaterThan(0);
  });

  it("lets a reviewer pick a candidate to resolve an ambiguous row", async () => {
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
      http.post("*/onboarding/migration/rows", () =>
        HttpResponse.json(AMBIGUOUS_BATCH, { status: 201 }),
      ),
    );

    let patched: Record<string, unknown> | null = null;
    server.use(
      http.patch("*/onboarding/migration/rows/:rowId", async ({ request }) => {
        patched = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({
          ...AMBIGUOUS_BATCH.rows[0],
          resolved_employee_public_id: patched.employee_public_id,
          action: "merge",
        });
      }),
      http.get("*/onboarding/migration/batches/:batchId", () =>
        HttpResponse.json(AMBIGUOUS_BATCH),
      ),
    );

    const { container } = renderWithClient(<MigrationStep />);

    const textarea = container.querySelector("textarea") as HTMLTextAreaElement;
    fireEvent.change(textarea, { target: { value: "New Arrival" } });
    fireEvent.click(screen.getByText("Stage for review"));

    await screen.findByText("Person A");
    const useButtons = screen.getAllByText("Use");
    fireEvent.click(useButtons[0]);

    await waitFor(() => expect(patched).not.toBeNull());
    expect(patched).toMatchObject({
      action: "merge",
      employee_public_id: "01HZEMP0000000000000001",
    });
  });

  // A list brought in a second time (2026-11-18): its people are held as
  // "may already be here", named with their number, and the reviewer says
  // whether each is the person already here.
  it("says most of a list is already here by name and asks whether each is them", async () => {
    const sameName = (n: number) => ({
      ...AMBIGUOUS_BATCH.rows[0],
      public_id: `01HZROWSAME00000000000000${n}`,
      display_name: `Person ${n}`,
      match_outcome: "same_name" as const,
      match_confidence: 0.2,
      candidates: [
        {
          employee_public_id: `01HZEMPSAME0000000000000${n}`,
          employee_name: `Person ${n}`,
          score: 0.2,
          reasons: ["same_full_name"],
          employee_code: `WOR-000${n}`,
        },
      ],
      resolved_employee_public_id: `01HZEMPSAME0000000000000${n}`,
      action: "defer" as const,
    });
    const batch: MigrationBatch = {
      ...AMBIGUOUS_BATCH,
      summary: { same_name: 2, new: 1 },
      rows: [
        sameName(1),
        sameName(2),
        { ...AMBIGUOUS_BATCH.rows[0], match_outcome: "new", candidates: [] },
      ],
    };

    const patched: Record<string, unknown>[] = [];
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
      http.post("*/onboarding/migration/rows", () =>
        HttpResponse.json(batch, { status: 201 }),
      ),
      http.get("*/onboarding/migration/batches/:batchId", () =>
        HttpResponse.json(batch),
      ),
      http.patch("*/onboarding/migration/rows/:rowId", async ({ request }) => {
        patched.push((await request.json()) as Record<string, unknown>);
        return HttpResponse.json(batch.rows[0]);
      }),
    );

    const { container } = renderWithClient(<MigrationStep />);
    const textarea = container.querySelector("textarea") as HTMLTextAreaElement;
    fireEvent.change(textarea, { target: { value: "Person 1" } });
    fireEvent.click(screen.getByText("Stage for review"));

    expect(
      await screen.findByText("2 of these 3 people are already here by name."),
    ).toBeInTheDocument();
    expect(screen.getByText(/WOR-0001/)).toBeInTheDocument();
    expect(screen.getAllByText("may already be here").length).toBeGreaterThan(
      0,
    );
    // A name is not a score: no percentage beside it.
    expect(screen.queryByText(/20%/)).toBeNull();

    fireEvent.click(screen.getAllByText("This is them")[0]);
    await waitFor(() => expect(patched).toHaveLength(1));
    expect(patched[0]).toMatchObject({
      action: "merge",
      employee_public_id: "01HZEMPSAME00000000000001",
    });

    fireEvent.click(screen.getAllByText("A different person")[1]);
    await waitFor(() => expect(patched).toHaveLength(2));
    expect(patched[1]).toMatchObject({ action: "create" });
  });

  it("links every row naming one person in one go, after asking", async () => {
    const held = (n: number, resolved: boolean) => ({
      ...AMBIGUOUS_BATCH.rows[0],
      public_id: `01HZROWBULK00000000000000${n}`,
      display_name: `Person ${n}`,
      match_outcome: "same_name" as const,
      candidates: [
        {
          employee_public_id: `01HZEMPBULK0000000000000${n}`,
          employee_name: `Person ${n}`,
          score: 0.2,
          reasons: ["same_full_name"],
          employee_code: `WOR-000${n}`,
        },
      ],
      resolved_employee_public_id: resolved
        ? `01HZEMPBULK0000000000000${n}`
        : null,
      action: "defer" as const,
    });
    const batch: MigrationBatch = {
      ...AMBIGUOUS_BATCH,
      summary: { same_name: 3 },
      // The third names more than one person, so it is not counted.
      rows: [held(1, true), held(2, true), held(3, false)],
    };
    let posted = 0;
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
      http.post("*/onboarding/migration/rows", () =>
        HttpResponse.json(batch, { status: 201 }),
      ),
      http.get("*/onboarding/migration/batches/:batchId", () =>
        HttpResponse.json(batch),
      ),
      http.post(
        "*/onboarding/migration/batches/:batchId/confirm-same-name",
        () => {
          posted++;
          return HttpResponse.json({ confirmed: 2 });
        },
      ),
    );

    const { container } = renderWithClient(<MigrationStep />);
    const textarea = container.querySelector("textarea") as HTMLTextAreaElement;
    fireEvent.change(textarea, { target: { value: "Person 1" } });
    fireEvent.click(screen.getByText("Stage for review"));

    fireEvent.click(
      await screen.findByText("All 2 are the people already here"),
    );
    expect(
      await screen.findByText("Link all 2 to the people already here?"),
    ).toBeInTheDocument();
    expect(posted).toBe(0);

    fireEvent.click(screen.getByRole("button", { name: "Link them" }));
    await waitFor(() => expect(posted).toBe(1));
  });

  // In a list that completes people, finding them by name is the point.
  it("does not warn that a list completing people was brought in before", async () => {
    const batch: MigrationBatch = {
      ...AMBIGUOUS_BATCH,
      mode: "complete",
      summary: { same_name: 1 },
      rows: [
        {
          ...AMBIGUOUS_BATCH.rows[0],
          match_outcome: "same_name",
          candidates: [
            {
              employee_public_id: "01HZEMPSAME00000000000001",
              employee_name: "Person 1",
              score: 0.2,
              reasons: ["same_full_name"],
              employee_code: "WOR-0001",
            },
          ],
        },
      ],
    };
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
      http.post("*/onboarding/migration/rows", () =>
        HttpResponse.json(batch, { status: 201 }),
      ),
      http.get("*/onboarding/migration/batches/:batchId", () =>
        HttpResponse.json(batch),
      ),
    );

    const { container } = renderWithClient(<MigrationStep />);
    const textarea = container.querySelector("textarea") as HTMLTextAreaElement;
    fireEvent.change(textarea, { target: { value: "Person 1" } });
    fireEvent.click(screen.getByText("Stage for review"));

    expect(await screen.findByText("This is them")).toBeInTheDocument();
    expect(screen.queryByText(/already here by name/)).toBeNull();
    // A list that completes people adds nobody.
    expect(screen.queryByText("A different person")).toBeNull();
  });

  it("points to adding a device when there is none, and picks the only one", async () => {
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
    );
    const first = renderWithClient(<MigrationStep />);
    expect(await screen.findByText("Connect a device")).toHaveAttribute(
      "href",
      "/devices",
    );
    // No empty device card beside the list: there is nothing to choose.
    expect(screen.queryByText("Discover enrolled users")).toBeNull();
    expect(screen.queryByText("From an attendance device")).toBeNull();
    first.unmount();

    let staged = "";
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({
          data: [{ public_id: "01HZDEV1", name: "Gate terminal" }],
          meta: {},
          links: {},
        }),
      ),
      http.post("*/onboarding/migration/devices/:device", ({ params }) => {
        staged = String(params.device);
        return HttpResponse.json(AMBIGUOUS_BATCH, { status: 201 });
      }),
      http.get("*/onboarding/migration/batches/:batchId", () =>
        HttpResponse.json(AMBIGUOUS_BATCH),
      ),
    );
    renderWithClient(<MigrationStep />);
    const discover = await screen.findByText("Discover enrolled users");
    await waitFor(() => expect(discover.closest("button")).toBeEnabled());
    fireEvent.click(discover);
    await waitFor(() => expect(staged).toBe("01HZDEV1"));
  });

  // Opened for the main gate, which only sends, in an organisation with a
  // readable back door: the back door was chosen in its place without a
  // word, and its list staged as the main gate's (review of #707).
  it("does not put another terminal in place of the one it was opened for", async () => {
    let staged = false;
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({
          data: [
            {
              public_id: "01HZDEVPUSH",
              name: "Main gate",
              receives_only: true,
            },
            {
              public_id: "01HZDEVBACK",
              name: "Back door",
              receives_only: false,
            },
          ],
          meta: {},
          links: {},
        }),
      ),
      http.post("*/onboarding/migration/devices/:device", () => {
        staged = true;
        return HttpResponse.json(AMBIGUOUS_BATCH, { status: 201 });
      }),
    );
    renderWithClient(<MigrationStep initialDeviceId="01HZDEVPUSH" />);

    expect(
      await screen.findByText(
        "Main gate sends its punches to ETHR and cannot be asked who is enrolled on it. Choose another terminal.",
      ),
    ).toBeInTheDocument();
    expect(
      screen.getByText("Discover enrolled users").closest("button"),
    ).toBeDisabled();
    expect(staged).toBe(false);
  });

  it("offers no device that only sends to ETHR, and says why when that is the only one", async () => {
    let staged = false;
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({
          data: [
            {
              public_id: "01HZDEVPUSH",
              name: "Habru gate",
              receives_only: true,
            },
          ],
          meta: {},
          links: {},
        }),
      ),
      http.post("*/onboarding/migration/devices/:device", () => {
        staged = true;
        return HttpResponse.json(AMBIGUOUS_BATCH, { status: 201 });
      }),
    );
    renderWithClient(<MigrationStep initialDeviceId="01HZDEVPUSH" />);

    expect(
      await screen.findByText(/cannot be asked who is enrolled on it/),
    ).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Devices" })).toHaveAttribute(
      "href",
      "/devices",
    );
    // No button that would stage an empty list.
    expect(screen.queryByText("Discover enrolled users")).toBeNull();
    expect(screen.queryByText("Connect a device")).toBeNull();
    expect(staged).toBe(false);
  });

  it("shows what the server made of the list and which lines need fixing, and stages nothing until they are fixed", async () => {
    let posted = false;
    const asked = reads({
      people: 1,
      header: true,
      columns: ["name", "hire_date", "salary"],
      ignored: ["Notes"],
      problems: [
        {
          line: 3,
          message: "The hire date is not a valid date.",
          column: "hire_date",
          code: "date",
        },
      ],
    });
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
      http.post("*/onboarding/migration/rows", () => {
        posted = true;
        return HttpResponse.json(AMBIGUOUS_BATCH, { status: 201 });
      }),
    );

    const { container } = renderWithClient(<MigrationStep />);
    const textarea = container.querySelector("textarea") as HTMLTextAreaElement;
    fireEvent.change(textarea, {
      target: {
        value: [
          "Name\tHire date\tSalary\tNotes",
          "Abebe Kebede\t2019-07-08\t12500\tsenior",
          "Hanna Girma\t2019-02-30\t\t",
        ].join("\n"),
      },
    });

    expect(await screen.findByText(/^1 person ready/)).toBeInTheDocument();
    expect(
      screen.getByText(/Columns: Name, Hire date, Salary/),
    ).toBeInTheDocument();
    expect(screen.getByText(/Not read.*Notes/)).toBeInTheDocument();
    const table = screen.getByRole("table", { name: "Lines to fix" });
    const cells = within(table)
      .getAllByRole("cell")
      .map((cell) => cell.textContent);
    expect(cells).toEqual([
      "Hire date",
      "The hire date is not a valid date.",
      "1",
      "3",
    ]);

    // The browser sent cells, not meanings: the header went as it was typed.
    expect(asked[0].grid[0]).toEqual(["Name", "Hire date", "Salary", "Notes"]);

    const stage = screen.getByText("Stage for review").closest("button")!;
    expect(stage).toBeDisabled();
    fireEvent.click(stage);
    expect(posted).toBe(false);
  });

  it("shows what is worth knowing about lines that are staged all the same, grouped as the problems are", async () => {
    reads({
      people: 7,
      warnings: Array.from({ length: 7 }, (_, i) => ({
        line: i + 2,
        column: "badge_number",
        code: "terminal_number_not_accepted",
        message: `The number "A${i}" is not taken.`,
      })),
    });
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
    );

    const { container } = renderWithClient(<MigrationStep />);
    fireEvent.change(
      container.querySelector("textarea") as HTMLTextAreaElement,
      {
        target: { value: "Abebe Kebede\tA0" },
      },
    );

    const cells = within(
      await screen.findByRole("table", {
        name: "Worth knowing before you add them",
      }),
    )
      .getAllByRole("cell")
      .map((cell) => cell.textContent);
    expect(cells).toEqual([
      "Badge number",
      'The number "A0" is not taken.',
      "7",
      "2, 3, 4, 5, 6, 7, 8",
    ]);
    expect(
      screen.getByText("Stage for review").closest("button"),
    ).not.toBeDisabled();
  });

  it("shows a staged row's warnings on the row", async () => {
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
      http.post("*/onboarding/migration/rows", () =>
        HttpResponse.json(WARNED_BATCH, { status: 201 }),
      ),
      http.get("*/onboarding/migration/batches/:batchId", () =>
        HttpResponse.json(WARNED_BATCH),
      ),
    );

    const { container } = renderWithClient(<MigrationStep />);
    fireEvent.change(
      container.querySelector("textarea") as HTMLTextAreaElement,
      {
        target: { value: "Abebe Kebede\tA_1" },
      },
    );
    fireEvent.click(await screen.findByText("Stage for review"));

    expect(
      await screen.findByText(
        "The terminal number A_1 is kept as the terminal's own.",
      ),
    ).toBeInTheDocument();
  });
  it("offers adding people or completing those already here only where asked, and sends the choice", async () => {
    let body: { mode?: string } | null = null;
    reads();
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
      http.post("*/onboarding/migration/rows", async ({ request }) => {
        body = (await request.json()) as typeof body;
        return HttpResponse.json(AMBIGUOUS_BATCH, { status: 201 });
      }),
      http.get("*/onboarding/migration/batches/:batchId", () =>
        HttpResponse.json(AMBIGUOUS_BATCH),
      ),
    );

    // The setup and a device's roster add people: no choice to make.
    const plain = renderWithClient(<MigrationStep />);
    expect(screen.queryByLabelText("Add people")).not.toBeInTheDocument();
    plain.unmount();

    const { container } = renderWithClient(
      <MigrationStep sources="list" canComplete />,
    );
    expect(screen.getByLabelText("Add people")).toBeChecked();
    fireEvent.click(
      screen.getByLabelText(
        "Complete people who are already here (nobody new is added)",
      ),
    );
    fireEvent.change(container.querySelector("textarea")!, {
      target: { value: "Abebe Kebede, , 0911000000" },
    });
    fireEvent.click(screen.getByText("Stage for review"));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body!.mode).toBe("complete");
  });

  it("sends no mode when the list adds people", async () => {
    let body: { mode?: string } | null = null;
    reads();
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
      http.post("*/onboarding/migration/rows", async ({ request }) => {
        body = (await request.json()) as typeof body;
        return HttpResponse.json(AMBIGUOUS_BATCH, { status: 201 });
      }),
      http.get("*/onboarding/migration/batches/:batchId", () =>
        HttpResponse.json(AMBIGUOUS_BATCH),
      ),
    );

    const { container } = renderWithClient(
      <MigrationStep sources="list" canComplete />,
    );
    fireEvent.change(container.querySelector("textarea")!, {
      target: { value: "Abebe Kebede, , 0911000000" },
    });
    fireEvent.click(screen.getByText("Stage for review"));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).not.toHaveProperty("mode");
  });

  it("titles a list that completes people, leaves out who is not found, and offers nobody to add", async () => {
    const COMPLETING: MigrationBatch = {
      ...AMBIGUOUS_BATCH,
      mode: "complete",
      summary: { new: 1 },
      rows: [
        {
          ...AMBIGUOUS_BATCH.rows[0],
          display_name: "Nobody Known",
          match_outcome: "new",
          match_confidence: null,
          candidates: [],
          action: "skip",
        },
      ],
    };
    reads();
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
      http.post("*/onboarding/migration/rows", () =>
        HttpResponse.json(COMPLETING, { status: 201 }),
      ),
      http.get("*/onboarding/migration/batches/:batchId", () =>
        HttpResponse.json(COMPLETING),
      ),
    );

    const { container } = renderWithClient(
      <MigrationStep sources="list" canComplete />,
    );
    fireEvent.change(container.querySelector("textarea")!, {
      target: { value: "Nobody Known, , 0911000000" },
    });
    fireEvent.click(screen.getByText("Stage for review"));

    expect(
      await screen.findByText("Completing people who are already here"),
    ).toBeInTheDocument();
    expect(screen.getByText("Not found in ETHR, left out")).toBeInTheDocument();
    expect(screen.getByText("Left out")).toBeInTheDocument();
    // Nobody arrives from this list: no choice of how new people arrive.
    expect(screen.queryByText("The new people on this list")).toBeNull();
    expect(
      screen.getByRole("button", { name: "Fill in these people" }),
    ).toBeInTheDocument();
  });

  it("does not warn about a missing hire date while completing people, and counts who matched nobody as not found", async () => {
    const COMPLETING: MigrationBatch = {
      ...AMBIGUOUS_BATCH,
      mode: "complete",
      summary: { new: 1 },
      rows: [
        {
          ...AMBIGUOUS_BATCH.rows[0],
          match_outcome: "new",
          candidates: [],
          action: "skip",
        },
      ],
    };
    const asked = reads({ people: 1, without_hire_date: 1 });
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
      http.post("*/onboarding/migration/rows", () =>
        HttpResponse.json(COMPLETING, { status: 201 }),
      ),
      http.get("*/onboarding/migration/batches/:batchId", () =>
        HttpResponse.json(COMPLETING),
      ),
    );

    const { container } = renderWithClient(
      <MigrationStep sources="list" canComplete />,
    );
    fireEvent.change(container.querySelector("textarea")!, {
      target: { value: "Abebe Kebede" },
    });
    expect(
      await screen.findByText(/1 person has no hire date/),
    ).toBeInTheDocument();

    fireEvent.click(
      screen.getByLabelText(
        "Complete people who are already here (nobody new is added)",
      ),
    );
    expect(screen.queryByText(/has no hire date/)).not.toBeInTheDocument();

    // The server is told the list completes people, so a sign-in column in
    // it is ignored when the list is read, as it is when it is staged.
    await waitFor(() =>
      expect(asked.at(-1)).toMatchObject({ mode: "complete" }),
    );
    expect(asked[0]).not.toHaveProperty("mode");

    fireEvent.click(screen.getByText("Stage for review"));
    expect(await screen.findByText("1 not found")).toBeInTheDocument();
    expect(screen.queryByText("1 new")).not.toBeInTheDocument();
  });

  it("sends the calendar chosen with the list as written, and says who has no hire date", async () => {
    let body: { grid: string[][]; calendar: string } | null = null;
    const asked = reads({ people: 2, without_hire_date: 1 });
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
      http.post("*/onboarding/migration/rows", async ({ request }) => {
        body = (await request.json()) as typeof body;
        return HttpResponse.json(AMBIGUOUS_BATCH, { status: 201 });
      }),
      http.get("*/onboarding/migration/batches/:batchId", () =>
        HttpResponse.json(AMBIGUOUS_BATCH),
      ),
    );

    const { container } = renderWithClient(<MigrationStep />);
    const textarea = container.querySelector("textarea") as HTMLTextAreaElement;
    expect(textarea.placeholder).toContain("2019-07-07");
    fireEvent.click(screen.getByText("Ethiopian (E.C.)"));
    expect(
      screen.getByText("Ethiopian (E.C.)").closest("button"),
    ).toHaveAttribute("aria-pressed", "true");
    // The example follows the choice: a Gregorian 2019 read as Ethiopian is years ahead.
    expect(textarea.placeholder).toContain("Hire date (E.C.)");
    expect(textarea.placeholder).toContain("2011-10-30");
    fireEvent.change(textarea, {
      target: {
        value: [
          "Name\tHire date",
          "Abebe Kebede\t2011-10-30",
          "Hanna Girma\t",
        ].join("\n"),
      },
    });

    expect(await screen.findByText(/2 people ready/)).toBeInTheDocument();
    expect(
      screen.getByText(/1 person has no hire date. Today will be used/),
    ).toBeInTheDocument();
    expect(asked[asked.length - 1].calendar).toBe("ethiopian");

    fireEvent.click(screen.getByText("Stage for review"));
    await waitFor(() => expect(body).not.toBeNull());
    // The date goes as it was written. The server reads it, once, for
    // every list.
    expect(body!.calendar).toBe("ethiopian");
    expect(body!.grid).toEqual([
      ["Name", "Hire date"],
      ["Abebe Kebede", "2011-10-30"],
      ["Hanna Girma", ""],
    ]);
  });

  // Dates real in one calendar only (2026-11-22): the reader is told which
  // line says so and chooses; nothing is switched for them.
  it("says when the dates look like the other calendar and lets the reader switch", async () => {
    const asked = reads({
      people: 1,
      calendar_hints: [
        {
          column: "hire_date",
          calendar: "ethiopian",
          value: "2016-13-03",
          line: 3,
          from_header: false,
        },
      ],
    });
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
    );

    const { container } = renderWithClient(<MigrationStep />);
    const textarea = container.querySelector("textarea") as HTMLTextAreaElement;
    fireEvent.change(textarea, {
      target: { value: "Name\tHire date\nAbebe Kebede\t2016-13-03" },
    });

    expect(
      await screen.findByText(/a date only the Ethiopian calendar has/),
    ).toHaveTextContent("Line 3 has 2016-13-03");
    expect(asked[asked.length - 1].calendar).toBe("gregorian");

    fireEvent.click(screen.getByText("Read the dates as Ethiopian (E.C.)"));
    await waitFor(() =>
      expect(asked[asked.length - 1].calendar).toBe("ethiopian"),
    );
  });

  it("says the column name decides when it set the calendar the dates disagree with", async () => {
    reads({
      people: 1,
      calendar_hints: [
        {
          column: "hire_date",
          calendar: "gregorian",
          value: "2019-01-31",
          line: 2,
          from_header: true,
        },
      ],
    });
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
    );

    const { container } = renderWithClient(<MigrationStep />);
    const textarea = container.querySelector("textarea") as HTMLTextAreaElement;
    fireEvent.change(textarea, {
      target: { value: "Name\tHire date (E.C.)\nAbebe Kebede\t2019-01-31" },
    });

    expect(
      await screen.findByText(/The column name says the other calendar/),
    ).toBeInTheDocument();
    expect(
      screen.queryByText("Read the dates as Gregorian (G.C.)"),
    ).not.toBeInTheDocument();
  });

  it("keeps the lines of each repeated name together", async () => {
    reads({
      people: 4,
      warnings: [
        {
          line: 2,
          column: "name",
          code: "same_name_in_list",
          message: 'The name "Abebe" is on more than one line.',
        },
        {
          line: 3,
          column: "name",
          code: "same_name_in_list",
          message: 'The name "Hanna" is on more than one line.',
        },
        {
          line: 4,
          column: "name",
          code: "same_name_in_list",
          message: 'The name "Abebe" is on more than one line.',
        },
        {
          line: 5,
          column: "name",
          code: "same_name_in_list",
          message: 'The name "Hanna" is on more than one line.',
        },
      ],
    });
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
    );

    const { container } = renderWithClient(<MigrationStep />);
    const textarea = container.querySelector("textarea") as HTMLTextAreaElement;
    fireEvent.change(textarea, {
      target: { value: "Name\nAbebe\nHanna\nAbebe\nHanna" },
    });

    const abebe = await screen.findByText(
      'The name "Abebe" is on more than one line.',
    );
    expect(abebe.closest("tr")).toHaveTextContent("2, 4");
    expect(
      screen
        .getByText('The name "Hanna" is on more than one line.')
        .closest("tr"),
    ).toHaveTextContent("3, 5");
  });

  it("shows the lines a staging was refused for, until the list changes", async () => {
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
      http.post("*/onboarding/migration/rows", () =>
        HttpResponse.json(
          {
            message: "The given data was invalid.",
            errors: {
              "grid.4": ["Line 5: The email field must be a valid email."],
              "grid.1": ["Line 2: The name field is required."],
            },
          },
          { status: 422 },
        ),
      ),
    );

    const { container } = renderWithClient(<MigrationStep />);
    const textarea = container.querySelector("textarea") as HTMLTextAreaElement;
    fireEvent.change(textarea, { target: { value: "Name\n\nAbebe" } });
    // Before the list has rested and been read, staging is still allowed;
    // the server is the one that decides.
    fireEvent.click(screen.getByText("Stage for review"));

    const first = await screen.findByText(
      "Line 2: The name field is required.",
    );
    expect(first.nextElementSibling).toHaveTextContent(
      "Line 5: The email field must be a valid email.",
    );
    expect(
      screen.getByText("Stage for review").closest("button"),
    ).toBeDisabled();

    fireEvent.change(textarea, { target: { value: "Name\nAbebe" } });
    expect(
      screen.queryByText("Line 2: The name field is required."),
    ).not.toBeInTheDocument();
  });

  it("stages the people who are ready and leaves the bad lines out, when asked to", async () => {
    let body: Record<string, unknown> | null = null;
    reads({
      people: 2,
      header: true,
      columns: ["name", "email"],
      problems: [
        {
          line: 3,
          message: "The email must be a valid email.",
          column: "email",
          code: "email",
        },
      ],
    });
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
      http.post("*/onboarding/migration/rows", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(AMBIGUOUS_BATCH, { status: 201 });
      }),
      http.get("*/onboarding/migration/batches/:batchId", () =>
        HttpResponse.json(AMBIGUOUS_BATCH),
      ),
    );

    const { container } = renderWithClient(<MigrationStep />);
    fireEvent.change(container.querySelector("textarea")!, {
      target: { value: "Name,Email\nAbebe,a@acme.et\nBad,nope\nHanna," },
    });

    fireEvent.click(
      await screen.findByText(
        "Stage the 2 people ready and leave those lines out",
      ),
    );

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toMatchObject({ skip_problems: true });
    expect(body!.grid).toHaveLength(4);
  });

  it("opens a chosen workbook in the browser and puts its people in the list", async () => {
    const asked = reads({ people: 2, header: true, columns: ["name"] });
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
    );

    const workbook = new Workbook();
    const sheet = workbook.addWorksheet("Staff");
    sheet.addRow(["Name", "Staff ID", "Start date"]);
    sheet.addRow(["Abebe Kebede", 1001, new Date(Date.UTC(2019, 6, 8))]);
    sheet.addRow(["Kebede, Hanna", "EMP-2", "2011-10-30"]);
    const file = new File([await workbook.xlsx.writeBuffer()], "staff.xlsx");

    const { container } = renderWithClient(<MigrationStep />);
    fireEvent.change(screen.getByTestId("people-file"), {
      target: { files: [file] },
    });

    const textarea = container.querySelector("textarea") as HTMLTextAreaElement;
    await waitFor(() => expect(textarea.value).toContain("Abebe Kebede"), {
      timeout: 10_000,
    });
    expect(await screen.findByText(/^2 people ready/)).toBeInTheDocument();

    // What the server is asked about is the workbook's cells: a number as
    // typed, a date cell marked Gregorian, a comma kept inside its cell.
    expect(asked[asked.length - 1].grid).toEqual([
      ["Name", "Staff ID", "Start date"],
      ["Abebe Kebede", "1001", "2019-07-08 G.C."],
      ["Kebede, Hanna", "EMP-2", "2011-10-30"],
    ]);
  });

  it("shows which sheet of a workbook with several is read, and reads the one picked", async () => {
    const asked = reads({ people: 1, header: true, columns: ["name"] });
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
    );

    const workbook = new Workbook();
    workbook.addWorksheet("Sheet1").addRow(["Name"]);
    workbook.getWorksheet("Sheet1")!.addRow(["First Sheet Person"]);
    workbook.addWorksheet("Sheet2").addRow(["Name"]);
    workbook.getWorksheet("Sheet2")!.addRow(["Second Sheet Person"]);
    const file = new File([await workbook.xlsx.writeBuffer()], "staff.xlsx");

    const { container } = renderWithClient(<MigrationStep />);
    fireEvent.change(screen.getByTestId("people-file"), {
      target: { files: [file] },
    });

    const textarea = container.querySelector("textarea") as HTMLTextAreaElement;
    await waitFor(
      () => expect(textarea.value).toContain("First Sheet Person"),
      {
        timeout: 10_000,
      },
    );
    expect(
      screen.getByText("This workbook has 2 sheets. Reading: Sheet1."),
    ).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText("Sheet to read"), {
      target: { value: "Sheet2" },
    });

    await waitFor(
      () => expect(textarea.value).toContain("Second Sheet Person"),
      { timeout: 10_000 },
    );
    expect(textarea.value).not.toContain("First Sheet Person");
    expect(
      screen.getByText("This workbook has 2 sheets. Reading: Sheet2."),
    ).toBeInTheDocument();
    await waitFor(() =>
      expect(asked[asked.length - 1].grid).toEqual([
        ["Name"],
        ["Second Sheet Person"],
      ]),
    );
  });

  it("shows nothing about sheets for a workbook with one", async () => {
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
    );

    const workbook = new Workbook();
    workbook.addWorksheet("Staff").addRow(["Abebe Kebede"]);
    const file = new File([await workbook.xlsx.writeBuffer()], "staff.xlsx");

    const { container } = renderWithClient(<MigrationStep />);
    fireEvent.change(screen.getByTestId("people-file"), {
      target: { files: [file] },
    });

    const textarea = container.querySelector("textarea") as HTMLTextAreaElement;
    await waitFor(() => expect(textarea.value).toContain("Abebe Kebede"), {
      timeout: 10_000,
    });
    expect(screen.queryByLabelText("Sheet to read")).not.toBeInTheDocument();
    expect(screen.queryByText(/This workbook has/)).not.toBeInTheDocument();
  });

  it("says a text file was read as Windows-1252 when its bytes were not UTF-8, and reads it", async () => {
    const asked = reads({ people: 1, header: true, columns: ["name"] });
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
    );

    const { container } = renderWithClient(<MigrationStep />);
    // "Name", a new line, "Jos" and 0xE9: an e with an acute accent in
    // Windows-1252, which is not valid UTF-8.
    const bytes = new Uint8Array([
      ...[..."Name\nJos"].map((c) => c.charCodeAt(0)),
      0xe9,
    ]);
    fireEvent.change(screen.getByTestId("people-file"), {
      target: { files: [new File([bytes], "staff.csv", { type: "text/csv" })] },
    });

    await waitFor(() =>
      expect(
        (container.querySelector("textarea") as HTMLTextAreaElement).value,
      ).toBe("Name\nJosé"),
    );
    expect(
      await screen.findByText("This file was read as Windows-1252 text."),
    ).toBeInTheDocument();
    await waitFor(() =>
      expect(asked[asked.length - 1].grid).toEqual([["Name"], ["José"]]),
    );
  });

  it("says nothing about the encoding of a file that is UTF-8", async () => {
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
    );

    const { container } = renderWithClient(<MigrationStep />);
    const bytes = new TextEncoder().encode("Name\nአበበ ከበደ");
    fireEvent.change(screen.getByTestId("people-file"), {
      target: { files: [new File([bytes], "staff.csv", { type: "text/csv" })] },
    });

    await waitFor(() =>
      expect(
        (container.querySelector("textarea") as HTMLTextAreaElement).value,
      ).toContain("አበበ ከበደ"),
    );
    await screen.findByText(/1 person ready/);
    expect(screen.queryByText(/was read as/)).not.toBeInTheDocument();
  });

  it("says where the column names were found below the first line, and sends the line the reader chooses instead", async () => {
    const asked = reads({
      people: 1,
      header: true,
      header_line: 2,
      columns: ["name", "email"],
    });
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
    );
    const lastAsked = () =>
      asked[asked.length - 1] as unknown as Record<string, unknown>;

    const { container } = renderWithClient(<MigrationStep />);
    fireEvent.change(container.querySelector("textarea")!, {
      target: {
        value: "Staff list\nName\tEmail\nAbebe Kebede\tabebe@acme.et",
      },
    });

    expect(
      await screen.findByText("Column names are on line 2."),
    ).toBeInTheDocument();
    // Left to the server, nothing is said about the line.
    expect(lastAsked().header_line).toBeUndefined();

    const select = screen.getByLabelText("Line with the column names");
    expect((select as HTMLSelectElement).value).toBe("2");
    expect(
      within(select).getByRole("option", { name: /^Line 3: Abebe Kebede/ }),
    ).toBeInTheDocument();

    fireEvent.change(select, { target: { value: "3" } });
    await waitFor(() => expect(lastAsked().header_line).toBe(3));

    fireEvent.change(screen.getByLabelText("Line with the column names"), {
      target: { value: "0" },
    });
    await waitFor(() => expect(lastAsked().header_line).toBe(0));
    // The control stays, so the reader can go back.
    expect(
      screen.getByRole("option", { name: "There are no column names" }),
    ).toBeInTheDocument();
  });

  it("lets the reader say where the column names are in a list under a title, when ETHR knows none of their words", async () => {
    const asked = reads({
      people: 2,
      header: false,
      header_line: null,
      header_likely: false,
    });
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
    );
    const lastAsked = () =>
      asked[asked.length - 1] as unknown as Record<string, unknown>;

    const { container } = renderWithClient(<MigrationStep />);
    fireEvent.change(container.querySelector("textarea")!, {
      target: {
        value: "Payroll export\nEmp Nm\tPers Ref\nAbebe Kebede\tP-1042",
      },
    });

    // Only a quiet button until the reader asks.
    await screen.findByText(/1 person ready|2 people ready/);
    expect(
      screen.queryByLabelText("Line with the column names"),
    ).not.toBeInTheDocument();
    fireEvent.click(
      screen.getByRole("button", {
        name: "The column names are on another line",
      }),
    );

    const select = await screen.findByLabelText("Line with the column names");
    expect(
      screen.getByText("Line with the column names", { selector: "label" }),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("button", {
        name: "The column names are on another line",
      }),
    ).not.toBeInTheDocument();
    expect((select as HTMLSelectElement).value).toBe("0");
    expect(lastAsked().header_line).toBeUndefined();

    fireEvent.change(select, { target: { value: "2" } });
    await waitFor(() => expect(lastAsked().header_line).toBe(2));
  });

  it("says nothing about a line when the column names are on the first", async () => {
    reads({ people: 1, header: true, header_line: 1, columns: ["name"] });
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
    );

    const { container } = renderWithClient(<MigrationStep />);
    fireEvent.change(container.querySelector("textarea")!, {
      target: { value: "Name\nAbebe Kebede" },
    });

    await screen.findByText(/1 person ready/);
    expect(
      screen.queryByText(/Column names are on line/),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByLabelText("Line with the column names"),
    ).not.toBeInTheDocument();
  });

  it("names the parts of a name by their words in the columns it read", async () => {
    reads({
      people: 1,
      header: true,
      header_line: 1,
      columns: ["first_name", "father_name", "grandfather_name"],
    });
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
    );

    const { container } = renderWithClient(<MigrationStep />);
    fireEvent.change(container.querySelector("textarea")!, {
      target: {
        value: "First name\tFather name\tLast name\nAbebe\tKebede\tTadesse",
      },
    });

    expect(
      await screen.findByText(
        /Columns: First name, Father's name, Grandfather's name/,
      ),
    ).toBeInTheDocument();
  });

  it("downloads the lines to fix under the column names found below a title, with their own numbers", async () => {
    saved.length = 0;
    reads({
      people: 1,
      header: true,
      header_line: 3,
      columns: ["name", "email"],
      problems: [
        {
          line: 5,
          column: "email",
          code: "email",
          message: "The email must be a valid email.",
        },
      ],
    });
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
    );

    const { container } = renderWithClient(<MigrationStep />);
    fireEvent.change(container.querySelector("textarea")!, {
      target: {
        value: [
          "Staff export",
          "",
          "Staff name\tMail",
          "Abebe Kebede\tabebe@acme.et",
          "Bad Mail\tnope",
        ].join("\n"),
      },
    });

    fireEvent.click(
      await screen.findByRole("button", { name: /Download the lines to fix/ }),
    );

    await waitFor(() => expect(saved).toHaveLength(1), { timeout: 10_000 });
    // The first line of the file to fix is the names, not the title, and
    // it reads back through the same reader as its own header.
    expect(await readWorkbook(await saved[0].blob.arrayBuffer())).toEqual([
      ["Staff name", "Mail", "Problem"],
      ["Bad Mail", "nope", "Line 5: The email must be a valid email."],
    ]);
  });

  it("says an old .xls file is not read, and reads nothing", async () => {
    const { toast } = await import("sonner");
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
    );

    const { container } = renderWithClient(<MigrationStep />);
    fireEvent.change(screen.getByTestId("people-file"), {
      target: { files: [new File(["x"], "staff.xls")] },
    });

    await waitFor(() =>
      expect(toast.error).toHaveBeenCalledWith(
        expect.stringContaining("Old .xls files are not read"),
      ),
    );
    expect(
      (container.querySelector("textarea") as HTMLTextAreaElement).value,
    ).toBe("");
  });

  it("reads a chosen CSV file into the list", async () => {
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
    );

    const { container } = renderWithClient(<MigrationStep />);
    const input = screen.getByTestId("people-file") as HTMLInputElement;
    const file = new File(
      ["Name,Email\nAbebe Kebede,abebe@acme.et"],
      "staff.csv",
      {
        type: "text/csv",
      },
    );
    fireEvent.change(input, { target: { files: [file] } });

    await waitFor(() =>
      expect(
        (container.querySelector("textarea") as HTMLTextAreaElement).value,
      ).toContain("Abebe Kebede"),
    );
    expect(await screen.findByText(/1 person ready/)).toBeInTheDocument();
  });

  it("groups the problems by column and kind, largest first, every group shown, and the line numbers cut after ten", async () => {
    const problem = (
      line: number,
      column: string | null,
      code: string,
      message: string,
    ) => ({ line, column, code, message });
    reads({
      people: 3,
      header: true,
      problems: [
        problem(3, "email", "email", "The email must be a valid email."),
        ...Array.from({ length: 12 }, (_, i) =>
          problem(i + 5, "hire_date", "date", `Not a date: "${i}"`),
        ),
        ...[20, 21, 22].map((line) =>
          problem(
            line,
            "department",
            "relation_not_found",
            `Department "X${line}" was not found.`,
          ),
        ),
        problem(30, null, "too_many", "This list has 2001 people."),
      ],
    });
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
    );

    const { container } = renderWithClient(<MigrationStep />);
    fireEvent.change(container.querySelector("textarea")!, {
      target: { value: "Name\tEmail\nAbebe\tabebe@acme.et" },
    });

    const table = await screen.findByRole("table", { name: "Lines to fix" });
    const rows = within(table)
      .getAllByRole("row")
      .slice(1)
      .map((row) =>
        within(row)
          .getAllByRole("cell")
          .map((cell) => cell.textContent),
      );

    expect(rows).toEqual([
      [
        "Hire date",
        'Not a date: "0"',
        "12",
        "5, 6, 7, 8, 9, 10, 11, 12, 13, 14 and 2 more",
      ],
      ["Department", 'Department "X20" was not found.', "3", "20, 21, 22"],
      ["Email", "The email must be a valid email.", "1", "3"],
      ["Whole list", "This list has 2001 people.", "1", "30"],
    ]);
    expect(
      within(table)
        .getAllByRole("columnheader")
        .map((h) => h.textContent),
    ).toEqual(["Column", "What to fix", "Lines", "Line numbers"]);
  });

  it("downloads only the refused lines, under the header as the reader had it, with the reason in a Problem column", async () => {
    saved.length = 0;
    reads({
      people: 1,
      header: true,
      columns: ["name", "email"],
      problems: [
        {
          line: 2,
          column: "hire_date",
          code: "date",
          message: "The hire date is not a valid date.",
        },
        {
          line: 2,
          column: "email",
          code: "email",
          message: "The email must be a valid email.",
        },
        {
          line: 4,
          column: "email",
          code: "email",
          message: "The email must be a valid email.",
        },
      ],
    });
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
    );

    const { container } = renderWithClient(<MigrationStep />);
    fireEvent.change(container.querySelector("textarea")!, {
      target: {
        value: [
          "Staff name\tMail\tJoined",
          "Bad Date\tbad\t2019-02-30",
          "Abebe Kebede\tabebe@acme.et\t2019-07-08",
          "Bad Mail\tnope\t0012",
        ].join("\n"),
      },
    });

    fireEvent.click(
      await screen.findByRole("button", { name: /Download the lines to fix/ }),
    );

    await waitFor(() => expect(saved).toHaveLength(1), { timeout: 10_000 });
    expect(saved[0].name).toBe("ethr-employees-to-fix.xlsx");
    expect(await readWorkbook(await saved[0].blob.arrayBuffer())).toEqual([
      ["Staff name", "Mail", "Joined", "Problem"],
      [
        "Bad Date",
        "bad",
        "2019-02-30",
        "Line 2: The hire date is not a valid date.; The email must be a valid email.",
      ],
      ["Bad Mail", "nope", "0012", "Line 4: The email must be a valid email."],
    ]);
  });

  it("downloads the lines of a list with no header line under the columns the server read it as", async () => {
    saved.length = 0;
    reads({
      people: 1,
      header: false,
      columns: [
        "name",
        "email",
        "phone",
        "employee_code",
        "hire_date",
        "department_code",
      ],
      problems: [
        {
          line: 2,
          column: "department",
          code: "relation_not_found",
          message: 'Department "Nowhere" was not found.',
        },
      ],
    });
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
    );

    const { container } = renderWithClient(<MigrationStep />);
    fireEvent.change(container.querySelector("textarea")!, {
      target: {
        value: "Abebe Kebede\tabebe@acme.et\nHanna Girma\t\t\t\t\tNowhere",
      },
    });

    fireEvent.click(
      await screen.findByRole("button", { name: /Download the lines to fix/ }),
    );

    await waitFor(() => expect(saved).toHaveLength(1), { timeout: 10_000 });
    expect(await readWorkbook(await saved[0].blob.arrayBuffer())).toEqual([
      [
        "name",
        "email",
        "phone",
        "employee_code",
        "hire_date",
        "department",
        "Problem",
      ],
      [
        "Hanna Girma",
        "",
        "",
        "",
        "",
        "Nowhere",
        'Line 2: Department "Nowhere" was not found.',
      ],
    ]);
  });

  it("offers no lines to fix for a list whose only problem is about the whole list", async () => {
    reads({
      people: 2001,
      header: true,
      problems: [
        {
          line: 2002,
          column: null,
          code: "too_many",
          message: "This list has 2001 people.",
        },
      ],
    });
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
    );

    const { container } = renderWithClient(<MigrationStep />);
    fireEvent.change(container.querySelector("textarea")!, {
      target: { value: "Name\nAbebe" },
    });

    expect(
      await screen.findByRole("table", { name: "Lines to fix" }),
    ).toBeInTheDocument();
    expect(screen.queryByText("Download the lines to fix")).toBeNull();
  });

  it("downloads what happened to each line once the list is added", async () => {
    saved.length = 0;
    const row = (
      id: string,
      name: string,
      over: Partial<MigrationBatch["rows"][number]>,
    ): MigrationBatch["rows"][number] => ({
      ...AMBIGUOUS_BATCH.rows[0],
      public_id: id,
      display_name: name,
      candidates: [],
      match_confidence: null,
      ...over,
    });
    const done = "2026-11-16T09:00:00+03:00";
    const committed: MigrationBatch = {
      ...AMBIGUOUS_BATCH,
      status: "committed",
      totals: { created: 1, merged: 2, updated: 1, skipped: 1 },
      rows: [
        row("r1", "Abebe Kebede", {
          match_outcome: "new",
          action: "create",
          processed_at: done,
          resolved_employee_public_id: "E1",
          resolved_employee_code: "1001",
          warnings: [{ code: "w1", message: "The number A1 is not taken." }],
        }),
        row("r2", "Hanna Girma", {
          match_outcome: "matched",
          action: "merge",
          processed_at: done,
          resolved_employee_code: "1002",
          can_apply_changes: true,
          changes: [
            {
              field: "phone",
              kind: "fill",
              current: null,
              incoming: "0911",
              masked: false,
              accepted: false,
              taken: false,
            },
          ],
        }),
        row("r3", "Dawit Alemu", {
          match_outcome: "matched",
          action: "merge",
          processed_at: done,
          resolved_employee_code: "1003",
        }),
        row("r5", "Not Editable", {
          match_outcome: "matched",
          action: "merge",
          processed_at: done,
          resolved_employee_code: "1005",
          can_apply_changes: false,
          changes: [
            {
              field: "email",
              kind: "fill",
              current: null,
              incoming: "n@acme.et",
              masked: false,
              accepted: false,
              taken: false,
            },
          ],
        }),
        row("r4", "Left Behind", { action: "skip" }),
      ],
    };
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
      http.post("*/onboarding/migration/rows", () =>
        HttpResponse.json(committed, { status: 201 }),
      ),
      http.get("*/onboarding/migration/batches/:batchId", () =>
        HttpResponse.json(committed),
      ),
      http.get("*/onboarding/migration/batches/:batchId/logins", () =>
        HttpResponse.json({ without_login: [], hire_dates_defaulted: 0 }),
      ),
    );

    const { container } = renderWithClient(<MigrationStep />);
    fireEvent.change(container.querySelector("textarea")!, {
      target: { value: "Abebe Kebede" },
    });
    fireEvent.click(screen.getByText("Stage for review"));

    fireEvent.click(await screen.findByText("Download what happened"));

    await waitFor(() => expect(saved).toHaveLength(1), { timeout: 10_000 });
    expect(saved[0].name).toBe("ethr-import-result.xlsx");
    expect(await readWorkbook(await saved[0].blob.arrayBuffer())).toEqual([
      ["Name", "Employee number", "What happened", "Note"],
      ["Abebe Kebede", "1001", "Added", "The number A1 is not taken."],
      ["Hanna Girma", "1002", "Filled in"],
      ["Dawit Alemu", "1003", "Already here, nothing to change"],
      ["Not Editable", "1005", "Already here, nothing to change"],
      ["Left Behind", "", "Left out"],
    ]);
  });
});

describe("what the terminals hold, after a list is added", () => {
  const TERMINALS = {
    visible: true,
    devices: [
      {
        device_public_id: "01HZDEVICE000000000000001",
        device_name: "Front gate",
        adapter_type: "zkteco_adms",
        writable: true,
        receives_only: false,
        queued: 1,
        done: 1,
        failed: 0,
        not_sent: 0,
        last_error: null,
        enrol_by_hand: [],
      },
    ],
    without_number: 0,
    without_branch: 0,
    can_assign: false,
    held: [],
    held_more: 0,
  };

  async function commitAs(mode: "complete" | null) {
    const committed: MigrationBatch = {
      ...AMBIGUOUS_BATCH,
      status: "committed",
      ...(mode ? { mode } : {}),
      totals: { created: 1, merged: 0, updated: 0, skipped: 0 },
      rows: [],
    };
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
      http.post("*/onboarding/migration/rows", () =>
        HttpResponse.json(committed, { status: 201 }),
      ),
      http.get("*/onboarding/migration/batches/:batchId", () =>
        HttpResponse.json(committed),
      ),
      http.get("*/onboarding/migration/batches/:batchId/logins", () =>
        HttpResponse.json({ without_login: [], hire_dates_defaulted: 0 }),
      ),
      http.get("*/onboarding/migration/batches/:batchId/terminals", () =>
        HttpResponse.json(TERMINALS),
      ),
    );
    const { container } = renderWithClient(<MigrationStep />);
    fireEvent.change(container.querySelector("textarea")!, {
      target: { value: "Abebe Kebede" },
    });
    fireEvent.click(screen.getByText("Stage for review"));
  }

  it("shows the terminals under the sign-in section of a list that added people", async () => {
    await commitAs(null);

    expect(
      await screen.findByText("At the attendance terminals"),
    ).toBeInTheDocument();
    expect(screen.getByText("Front gate")).toBeInTheDocument();
    expect(screen.getByText("2 sent or waiting")).toBeInTheDocument();
    expect(screen.getByText("Give them a way to sign in")).toBeInTheDocument();
  });

  it("shows the terminals for a list that completed people already here", async () => {
    await commitAs("complete");

    expect(
      await screen.findByText("At the attendance terminals"),
    ).toBeInTheDocument();
    expect(screen.queryByText("Give them a way to sign in")).toBeNull();
  });
});

describe("the confirmation before a list is added", () => {
  const NEW_ROW: MigrationBatch = {
    ...AMBIGUOUS_BATCH,
    summary: { new: 1 },
    rows: [
      {
        ...AMBIGUOUS_BATCH.rows[0],
        match_outcome: "new",
        match_confidence: null,
        candidates: [],
        action: "create",
      },
    ],
  };

  async function openConfirm(permissions: string[]) {
    server.use(
      http.get("*/auth/me", () =>
        HttpResponse.json({
          user: { public_id: "01JBQ8Z6ZK3M9V2X7Y4W5T6R80", role: "hr_admin" },
          permissions,
          tenant: null,
        }),
      ),
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
      http.post("*/onboarding/migration/rows", () =>
        HttpResponse.json(NEW_ROW, { status: 201 }),
      ),
      http.get("*/onboarding/migration/batches/:batchId", () =>
        HttpResponse.json(NEW_ROW),
      ),
    );
    const { container } = renderWithClient(<MigrationStep />);
    fireEvent.change(container.querySelector("textarea")!, {
      target: { value: "Abebe Kebede" },
    });
    fireEvent.click(screen.getByText("Stage for review"));
    fireEvent.click(await screen.findByText("Commit migration"));
  }

  it("says the additions can be undone to someone who may archive employees", async () => {
    await openConfirm(["employee.delete"]);

    expect(
      await screen.findByText(
        /can be archived again afterwards with Undo this list's additions/,
      ),
    ).toBeInTheDocument();
    expect(
      screen.queryByText(/This cannot be undone from here\./),
    ).not.toBeInTheDocument();
  });

  it("keeps the old sentence for someone who may not", async () => {
    await openConfirm(["employee.create"]);

    expect(
      await screen.findByText(
        "Employees are created and merged as the table shows. This cannot be undone from here.",
      ),
    ).toBeInTheDocument();
    expect(
      screen.queryByText(/can be archived again afterwards/),
    ).not.toBeInTheDocument();
  });
});

describe("a row matched to someone archived", () => {
  const row = (
    id: string,
    restore: NonNullable<MigrationBatch["rows"][number]["restore"]>,
    action: MigrationBatch["rows"][number]["action"],
  ) => ({
    ...AMBIGUOUS_BATCH.rows[0],
    public_id: id,
    display_name: `Person ${id}`,
    match_outcome: "archived" as const,
    candidates: [],
    resolved_employee_public_id: null,
    action,
    restore,
  });

  it("offers bringing them back only where nothing stops it, and never as a new person", () => {
    const free = row(
      "A",
      { possible: true, allowed: true, reasons: [] },
      "restore",
    );
    const used = row(
      "B",
      { possible: false, allowed: true, reasons: ["attendance"] },
      "defer",
    );
    const barred = row(
      "C",
      { possible: true, allowed: false, reasons: [] },
      "defer",
    );

    expect(actionsFor(free, false)).toEqual([
      "merge",
      "restore",
      "skip",
      "defer",
    ]);
    expect(actionsFor(used, false)).toEqual(["merge", "skip", "defer"]);
    expect(actionsFor(barred, false)).toEqual(["merge", "skip", "defer"]);
    // A list that completes people adds and restores nobody.
    expect(actionsFor(free, true)).toEqual(["merge", "skip", "defer"]);
    // A row with no archived match keeps Create.
    expect(actionsFor({ action: "create" }, false)).toEqual([
      "merge",
      "create",
      "skip",
      "defer",
    ]);
  });

  it("says what bringing them back does, and why a person cannot be brought back", async () => {
    const batch: MigrationBatch = {
      ...AMBIGUOUS_BATCH,
      summary: { archived: 2 },
      rows: [
        row("A", { possible: true, allowed: true, reasons: [] }, "restore"),
        row(
          "B",
          { possible: false, allowed: true, reasons: ["attendance"] },
          "defer",
        ),
      ],
    };
    server.use(
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
      http.post("*/onboarding/migration/rows", () =>
        HttpResponse.json(batch, { status: 201 }),
      ),
      http.get("*/onboarding/migration/batches/:batchId", () =>
        HttpResponse.json(batch),
      ),
    );

    const { container } = renderWithClient(<MigrationStep />);
    fireEvent.change(
      container.querySelector("textarea") as HTMLTextAreaElement,
      {
        target: { value: "Abebe Kebede" },
      },
    );
    fireEvent.click(await screen.findByText("Stage for review"));

    expect(
      await screen.findByText(
        "Archived. Adding this list brings them back with this line's details.",
      ),
    ).toBeInTheDocument();
    expect(
      screen.getByText(
        "Cannot be brought back from a list: Has attendance records. Restore them from the archived view if they should return.",
      ),
    ).toBeInTheDocument();
  });
});
