import { beforeEach, describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { Workbook } from "exceljs";
import { server } from "./msw/server";
import { MigrationStep } from "@/features/onboarding/v2/components/migration-step";
import type {
  MigrationBatch,
  PeopleRead,
} from "@/features/onboarding/v2/types";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
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
  headers: [],
  header_likely: false,
  needs_name: false,
  source: null,
  suggested_source: null,
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
      problems: [{ line: 3, message: "The hire date is not a valid date." }],
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
    expect(
      screen.getByText("Line 3: The hire date is not a valid date."),
    ).toBeInTheDocument();

    // The browser sent cells, not meanings: the header went as it was typed.
    expect(asked[0].grid[0]).toEqual(["Name", "Hire date", "Salary", "Notes"]);

    const stage = screen.getByText("Stage for review").closest("button")!;
    expect(stage).toBeDisabled();
    fireEvent.click(stage);
    expect(posted).toBe(false);
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
      problems: [{ line: 3, message: "The email must be a valid email." }],
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
});
