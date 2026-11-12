import { describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { MigrationStep } from "@/features/onboarding/v2/components/migration-step";
import type {
  ColumnMatch,
  ImportSource,
  MigrationBatch,
  PeopleRead,
} from "@/features/onboarding/v2/types";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

/*
 * A list from another HR or payroll system names its columns its own way
 * (One employee identity, PR 8). The reader says which is which, the server
 * reads the list as told, and the answer can be saved under the system's
 * name so its next list is read without asking.
 */

const EXPORT = "Emp Nm\tPers Ref\tCell\nAbebe Kebede\tP-1042\t0911223344";

/** What the server says about that list before anybody has matched anything. */
const UNMATCHED: PeopleRead = {
  people: 2,
  header: false,
  columns: [],
  ignored: [],
  calendar: "gregorian",
  calendar_from_header: false,
  without_hire_date: 2,
  problems: [],
  headers: [
    { header: "Emp Nm", column: null, suggested: "name" },
    { header: "Pers Ref", column: null, suggested: null },
    { header: "Cell", column: null, suggested: "phone" },
  ],
  header_likely: true,
  needs_name: false,
  source: null,
  suggested_source: null,
};

const PEACHTREE: ImportSource = {
  public_id: "01HZSOURCE000000000000001",
  name: "Peachtree",
  columns: [
    { header: "Emp Nm", column: "name" },
    { header: "Pers Ref", column: "external_id" },
    { header: "Cell", column: "phone" },
  ],
  calendar: null,
  updated_at: "2026-11-11T08:00:00+00:00",
};

const BATCH: MigrationBatch = {
  public_id: "01HZBATCH0000000000000031",
  source_type: "csv",
  source_ref: null,
  status: "reviewing",
  totals: null,
  summary: { new: 1 },
  rows: [],
};

type Asked = {
  grid: string[][];
  source?: string;
  mapping?: ColumnMatch[];
};

/**
 * The server, as far as this screen is concerned: it reads the list as it is
 * told, and answers with the columns as they were matched.
 */
function serves(saved: ImportSource[] = [], first: Partial<PeopleRead> = {}) {
  const asked: Asked[] = [];
  const staged: Asked[] = [];
  const savedAs: Record<string, unknown>[] = [];
  const sources = [...saved];

  server.use(
    http.get("*/devices", () =>
      HttpResponse.json({ data: [], meta: {}, links: {} }),
    ),
    http.get("*/onboarding/migration/sources", () =>
      HttpResponse.json({ data: sources }),
    ),
    http.post("*/onboarding/migration/sources", async ({ request }) => {
      const body = (await request.json()) as {
        name: string;
        mapping: ColumnMatch[];
      };
      savedAs.push(body);
      const source = {
        ...PEACHTREE,
        name: body.name,
        columns: body.mapping,
      };
      sources.push(source);
      return HttpResponse.json(source, { status: 201 });
    }),
    http.post("*/onboarding/migration/read", async ({ request }) => {
      const body = (await request.json()) as Asked;
      asked.push(body);

      const told =
        body.mapping ??
        sources.find((s) => s.public_id === body.source)?.columns;
      if (!told) {
        return HttpResponse.json({ ...UNMATCHED, ...first });
      }

      const columns = told.map((m) => m.column);
      return HttpResponse.json({
        ...UNMATCHED,
        people: columns.includes("name") ? 1 : 0,
        header: true,
        header_likely: false,
        needs_name: !columns.includes("name"),
        columns: columns.filter((c) => c !== null),
        headers: told.map((m) => ({ ...m, suggested: null })),
        source: body.source ?? null,
      });
    }),
    http.post("*/onboarding/migration/rows", async ({ request }) => {
      staged.push((await request.json()) as Asked);
      return HttpResponse.json(BATCH, { status: 201 });
    }),
    http.get("*/onboarding/migration/batches/:batchId", () =>
      HttpResponse.json(BATCH),
    ),
  );

  return { asked, staged, savedAs };
}

function renderStep() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const view = render(
    <QueryClientProvider client={client}>
      <MigrationStep sources="list" />
    </QueryClientProvider>,
  );
  fireEvent.change(view.container.querySelector("textarea")!, {
    target: { value: EXPORT },
  });
  return view;
}

const last = <T,>(list: T[]): T => list[list.length - 1];

describe("matching another system's columns", () => {
  it("says the first line looks like column names, and opens with its guesses filled in", async () => {
    const { asked } = serves();
    renderStep();

    expect(
      await screen.findByText(/looks like column names ETHR does not know/),
    ).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: "Match columns" }));

    // Every column is answered: the guesses, and "leave out" for the rest.
    await waitFor(() =>
      expect(last(asked).mapping).toEqual([
        { header: "Emp Nm", column: "name" },
        { header: "Pers Ref", column: null },
        { header: "Cell", column: "phone" },
      ]),
    );
    expect(
      await screen.findByRole("combobox", { name: "What Emp Nm is" }),
    ).toHaveValue("name");
    expect(
      screen.getByRole("combobox", { name: "What Pers Ref is" }),
    ).toHaveValue("");
    // A value from the first person, so the column is recognisable.
    expect(screen.getByText("For example: P-1042")).toBeInTheDocument();
    expect(await screen.findByText(/^1 person ready/)).toBeInTheDocument();
  });

  it("reads the list again as each column is chosen, and stages it as matched", async () => {
    const { asked, staged } = serves();
    renderStep();

    fireEvent.click(
      await screen.findByRole("button", { name: "Match columns" }),
    );
    fireEvent.change(
      await screen.findByRole("combobox", { name: "What Pers Ref is" }),
      { target: { value: "external_id" } },
    );

    await waitFor(() =>
      expect(last(asked).mapping?.[1]).toEqual({
        header: "Pers Ref",
        column: "external_id",
      }),
    );
    await waitFor(() =>
      expect(
        screen.getByRole("combobox", { name: "What Pers Ref is" }),
      ).toHaveValue("external_id"),
    );

    fireEvent.click(screen.getByText("Stage for review"));

    await waitFor(() => expect(staged).toHaveLength(1));
    expect(staged[0].mapping).toEqual(last(asked).mapping);
    expect(staged[0].source).toBeUndefined();
  });

  it("will not stage a list whose name column was left out", async () => {
    serves();
    renderStep();

    fireEvent.click(
      await screen.findByRole("button", { name: "Match columns" }),
    );
    fireEvent.change(
      await screen.findByRole("combobox", { name: "What Emp Nm is" }),
      { target: { value: "" } },
    );

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "Choose which column holds the person's name",
    );
    expect(
      screen.getByText("Stage for review").closest("button"),
    ).toBeDisabled();
  });

  it("saves the columns under the system's name, and from then on names the source", async () => {
    const { asked, staged, savedAs } = serves();
    renderStep();

    fireEvent.click(
      await screen.findByRole("button", { name: "Match columns" }),
    );
    await screen.findByRole("combobox", { name: "What Emp Nm is" });

    const save = screen.getByRole("button", { name: "Save" });
    expect(save).toBeDisabled();
    fireEvent.change(
      screen.getByLabelText(
        "Save these columns for the next list from this system",
      ),
      { target: { value: " Peachtree " } },
    );
    fireEvent.click(save);

    await waitFor(() => expect(savedAs).toHaveLength(1));
    expect(savedAs[0]).toEqual({
      name: "Peachtree",
      calendar: "gregorian",
      mapping: [
        { header: "Emp Nm", column: "name" },
        { header: "Pers Ref", column: null },
        { header: "Cell", column: "phone" },
      ],
    });

    // The list is now read as that source, not as columns matched by hand.
    await waitFor(() =>
      expect(last(asked)).toMatchObject({ source: PEACHTREE.public_id }),
    );
    expect(last(asked).mapping).toBeUndefined();
    expect(
      await screen.findByText(/This list is read as Peachtree/),
    ).toBeInTheDocument();

    fireEvent.click(screen.getByText("Stage for review"));
    await waitFor(() => expect(staged).toHaveLength(1));
    expect(staged[0].source).toBe(PEACHTREE.public_id);
  });

  it("offers a saved source whose columns the list has, and reads it that way when asked", async () => {
    const { asked } = serves([PEACHTREE], {
      suggested_source: PEACHTREE.public_id,
    });
    renderStep();

    expect(
      await screen.findByText("These look like the columns of Peachtree."),
    ).toBeInTheDocument();
    // Nothing is read as Peachtree until the reader says so.
    expect(last(asked).source).toBeUndefined();

    fireEvent.click(
      screen.getByRole("button", { name: "Read it as Peachtree" }),
    );

    await waitFor(() => expect(last(asked).source).toBe(PEACHTREE.public_id));
    await waitFor(() =>
      expect(
        screen.queryByText("These look like the columns of Peachtree."),
      ).toBeNull(),
    );
    expect(screen.getByLabelText("This list comes from")).toHaveValue(
      PEACHTREE.public_id,
    );
  });

  it("lets the reader say where a list is from, and take it back", async () => {
    const { asked } = serves([PEACHTREE]);
    renderStep();

    const picker = await screen.findByLabelText("This list comes from");
    fireEvent.change(picker, { target: { value: PEACHTREE.public_id } });
    await waitFor(() => expect(last(asked).source).toBe(PEACHTREE.public_id));

    fireEvent.change(picker, { target: { value: "" } });
    await waitFor(() => expect(last(asked).source).toBeUndefined());
  });

  it("shows no source picker to an organisation that has saved none", async () => {
    serves();
    renderStep();

    await screen.findByRole("button", { name: "Match columns" });
    expect(screen.queryByLabelText("This list comes from")).toBeNull();
  });
});
