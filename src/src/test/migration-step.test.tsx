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
import { MigrationStep } from "@/features/onboarding/v2/components/migration-step";
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
    reads({ people: 1, without_hire_date: 1 });
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
