/**
 * Excel workbooks, in the browser: one read into lines of cells, and the
 * employee template built as a workbook with dropdowns.
 *
 * A workbook never reaches the server. The host's PHP is older than the
 * spreadsheet library that writes dropdowns needs, and its zip and XML
 * extensions are unconfirmed (owner-approved plan, 2026-11-10), so the file is
 * opened here and its cells are sent as text, the same `grid` a pasted list
 * is. What a column means and whether a value is acceptable stay the
 * server's to say (EmployeeImportRow).
 *
 * ExcelJS is loaded only when a workbook is chosen or the template is
 * downloaded, so nobody else pays for it.
 */

import type { CellValue, Workbook as ExcelWorkbook } from "exceljs";
import { toEthiopian } from "@/lib/calendar/ethiopian";
import type { DateCalendar } from "./parse-people";

/** The sheet people are typed into. The reader takes this one, or the first sheet that is not ours. */
export const SHEET_PEOPLE = "Employees";
export const SHEET_HELP = "Instructions";
export const SHEET_LISTS = "Lists";
export const SHEET_EXAMPLE = "Example";

/** The order the groups are listed in, on the instructions sheet. */
const GROUP_ORDER = ["core", "person", "contact", "job", "pay", "source"];

/**
 * One light fill per column group on the header row. Pale enough for black
 * text in Excel and LibreOffice, and apart enough to tell the groups at a
 * glance.
 */
const GROUP_FILL: Record<string, string> = {
  core: "FFFFE9A8",
  person: "FFD5E8D4",
  contact: "FFD6E4F5",
  job: "FFE6DCF0",
  pay: "FFF9D9C4",
  source: "FFE3E3E3",
};

/** The most lines one staging takes (StageRowsRequest: `grid` max). */
export const MAX_LINES = 5000;

/** Rows of the template that carry the dropdowns: the most people one list brings. */
const TEMPLATE_ROWS = 2000;

export const XLSX_MIME =
  "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";

/** A workbook with more lines than one staging takes. */
export class TooManyLines extends Error {
  constructor(public readonly lines: number) {
    super(`The sheet has ${lines} lines.`);
    this.name = "TooManyLines";
  }
}

/** A workbook with no sheet of people in it. */
export class NoSheet extends Error {
  constructor() {
    super("The workbook has no sheet to read.");
    this.name = "NoSheet";
  }
}

/** What the server says the template is (POST /employees/import/template). */
export interface TemplateColumn {
  key: string;
  /** The header the template uses, when it differs from `key`. */
  column?: string;
  group: string;
  required: boolean;
}

export interface TemplateSpec {
  /** In template order. Without it the workbook has no groups, notes or example. */
  columns?: TemplateColumn[];
  headers: string[];
  required: string[];
  text_columns: string[];
  date_columns: string[];
  lists: Record<string, string[]>;
}

/** The words the workbook is written in, in the reader's language. */
export interface TemplateText {
  /** First line of the instructions sheet. */
  title: string;
  /** The lines under it. */
  lines: string[];
  /** Column name, then what it takes. */
  columnHeading: string;
  takesHeading: string;
  required: string;
  /** What each column takes, by header. */
  help: Record<string, string>;
  /** Shown when a value is typed that is not in a column's list. */
  notInList: string;
  /** Heading of each column group on the instructions sheet, by group. */
  groups: Record<string, string>;
  /**
   * Two example people, by header. Dates are Gregorian, as `YYYY-MM-DD`; the
   * workbook writes them in the calendar it is built for.
   */
  example: Record<string, string>[];
  /** The line under the example people: nothing on this sheet is read. */
  exampleNote: string;
  /** On the instructions sheet: the example and the header notes exist. */
  exampleLine: string;
}

type Excel = typeof import("exceljs");

async function loadExcel(): Promise<Excel> {
  // The browser build is one bundle with everything on `default`; Node's has
  // the classes as named exports.
  const mod = (await import("exceljs")) as unknown as Excel & {
    default?: Excel;
  };
  return mod.default?.Workbook ? mod.default : mod;
}

/**
 * A number as the person meant it. A number at or over 10^15 has been
 * rounded by the spreadsheet (a 16-digit national id typed into a number
 * cell keeps fifteen digits), and nothing here can know the lost ones. It is
 * written the way the spreadsheet shows it, 1.23e+15, which the server
 * refuses with what to do about it, instead of being passed on looking whole.
 */
function numberText(value: number): string {
  if (!Number.isFinite(value)) return "";
  if (Math.abs(value) >= 1e15) return value.toExponential();
  if (Number.isInteger(value)) return String(value);
  return String(Number(value.toFixed(6)));
}

/**
 * A date cell is a Gregorian day whatever calendar the list is in: a
 * spreadsheet has no other. It says so, so a list of Ethiopian dates typed
 * as text can sit beside it and each is read as what it is.
 */
function dateText(value: Date): string {
  if (Number.isNaN(value.getTime())) return "";
  return `${value.toISOString().slice(0, 10)} G.C.`;
}

/** One cell as text: what the person sees, not how the file stores it. */
export function cellText(value: CellValue | undefined): string {
  if (value === null || value === undefined) return "";
  if (typeof value === "string") return value.trim();
  if (typeof value === "number") return numberText(value);
  if (typeof value === "boolean") return value ? "true" : "false";
  if (value instanceof Date) return dateText(value);
  if ("richText" in value) {
    return value.richText
      .map((part) => part.text)
      .join("")
      .trim();
  }
  if ("result" in value) return cellText(value.result as CellValue);
  if ("text" in value) return cellText(value.text as CellValue);
  return "";
}

/**
 * The workbook's sheet of people as lines of cells. An empty row keeps its
 * place, so a line number the server reports is the row number in Excel.
 */
export async function readWorkbook(data: ArrayBuffer): Promise<string[][]> {
  const { Workbook } = await loadExcel();
  const workbook: ExcelWorkbook = new Workbook();
  await workbook.xlsx.load(data);

  const visible = workbook.worksheets.filter(
    (sheet) => sheet.state === "visible",
  );
  const sheet =
    visible.find((s) => s.name === SHEET_PEOPLE) ??
    visible.find(
      (s) =>
        s.name !== SHEET_HELP &&
        s.name !== SHEET_LISTS &&
        s.name !== SHEET_EXAMPLE,
    );
  if (!sheet) throw new NoSheet();

  const grid: string[][] = [];
  for (let r = 1; r <= sheet.rowCount; r++) {
    const row = sheet.findRow(r);
    const cells: string[] = [];
    if (row) {
      for (let c = 1; c <= row.cellCount; c++) {
        cells.push(cellText(row.getCell(c).value));
      }
    }
    while (cells.length > 0 && cells[cells.length - 1] === "") cells.pop();
    grid.push(cells);
  }
  while (grid.length > 0 && grid[grid.length - 1].length === 0) grid.pop();

  if (grid.length > MAX_LINES) throw new TooManyLines(grid.length);
  return grid;
}

/**
 * Lines of cells as the text the list box holds, tab-separated, so a
 * workbook's people can be seen and corrected like a pasted list. `toGrid`
 * reads it back to the same cells: a cell that would be split or unquoted on
 * the way is quoted here.
 */
export function gridToText(grid: string[][]): string {
  return grid
    .map((cells) =>
      cells
        .map((cell) => {
          const flat = cell.replace(/[\t\r\n]+/g, " ");
          return /^"|[,;]/.test(flat) ? `"${flat.replace(/"/g, '""')}"` : flat;
        })
        .join("\t"),
    )
    .join("\n");
}

/** An example date in the calendar the workbook is for, year first. */
function exampleDate(iso: string, calendar: DateCalendar): string {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);
  if (!match || calendar === "gregorian") return iso;
  const date = toEthiopian(
    Number(match[1]),
    Number(match[2]),
    Number(match[3]),
  );
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${date.year}-${pad(date.month)}-${pad(date.day)}`;
}

/**
 * The employee template as a workbook: the server's columns, a dropdown on
 * each choice column, and the columns a spreadsheet would mangle formatted
 * as text.
 *
 * Dates are text too. An Ethiopian date is not a date Excel can hold, and a
 * Gregorian one typed into a date cell is shown in whatever order the
 * computer's region uses. The column name says the calendar instead.
 *
 * A dropdown warns and lets a typed value through: the import reads more
 * spellings than the list offers (a code for a name, M for male).
 */
export async function buildTemplate(
  spec: TemplateSpec,
  calendar: DateCalendar,
  text: TemplateText,
  // Rows already known, written under the header by column name and the
  // rest left blank: the badges a terminal sent, each waiting for a name.
  rows: Record<string, string>[] = [],
): Promise<ArrayBuffer> {
  const { Workbook } = await loadExcel();
  const workbook: ExcelWorkbook = new Workbook();
  const mark = calendar === "ethiopian" ? "(E.C.)" : "(G.C.)";

  // The server's grouping, when it was sent. Without it the workbook is the
  // plain one: no bands, notes, example or grouped instructions.
  const columns = spec.columns;
  const groupOf = new Map<string, string>();
  for (const column of columns ?? []) {
    groupOf.set(column.column ?? column.key, column.group);
  }

  const people = workbook.addWorksheet(SHEET_PEOPLE, {
    // The name stays in view when a row is filled across to the right.
    views: [
      columns
        ? { state: "frozen", xSplit: 1, ySplit: 1 }
        : { state: "frozen", ySplit: 1 },
    ],
  });
  const example = columns ? workbook.addWorksheet(SHEET_EXAMPLE) : null;
  const help = workbook.addWorksheet(SHEET_HELP);
  const lists = workbook.addWorksheet(SHEET_LISTS, { state: "veryHidden" });
  const validations = (
    people as unknown as {
      dataValidations: { add: (range: string, rule: unknown) => void };
    }
  ).dataValidations;

  // The header row, the same on the people sheet and on the example.
  const writeHeader = (sheet: typeof people, withNotes: boolean) => {
    spec.headers.forEach((header, index) => {
      const isDate = spec.date_columns.includes(header);
      const label = isDate ? `${header} ${mark}` : header;
      const column = sheet.getColumn(index + 1);
      column.width = Math.max(14, label.length + 4);
      if (isDate || spec.text_columns.includes(header)) column.numFmt = "@";

      const cell = sheet.getCell(1, index + 1);
      cell.value = label;
      cell.font = { bold: true };

      const fill = GROUP_FILL[groupOf.get(header) ?? ""];
      if (fill) {
        cell.fill = {
          type: "pattern",
          pattern: "solid",
          fgColor: { argb: fill },
        };
      }

      const words = text.help[header];
      if (withNotes && columns && words) {
        cell.note = spec.required.includes(header)
          ? `${words} (${text.required})`
          : words;
      }
    });
  };
  writeHeader(people, true);

  let listColumn = 0;
  spec.headers.forEach((header, index) => {
    const column = people.getColumn(index + 1);
    const choices = spec.lists[header] ?? [];
    if (choices.length === 0) return;

    listColumn += 1;
    const source = lists.getColumn(listColumn);
    source.values = [header, ...choices];
    validations.add(`${column.letter}2:${column.letter}${TEMPLATE_ROWS + 1}`, {
      type: "list",
      allowBlank: true,
      formulae: [
        `${SHEET_LISTS}!$${source.letter}$2:$${source.letter}$${choices.length + 1}`,
      ],
      showErrorMessage: true,
      errorStyle: "warning",
      errorTitle: header,
      error: text.notInList,
    });
  });

  rows.forEach((row, offset) => {
    spec.headers.forEach((header, index) => {
      const value = row[header];
      if (value !== undefined && value !== "") {
        people.getCell(offset + 2, index + 1).value = value;
      }
    });
  });

  if (example) {
    writeHeader(example, false);
    const grey = { italic: true, color: { argb: "FF6B7280" } };
    text.example.forEach((person, offset) => {
      spec.headers.forEach((header, index) => {
        let value = person[header];
        const choices = spec.lists[header];
        // A list is the organisation's own: an example outside it would not
        // import for them, so it takes the list's first value instead.
        if (choices) {
          if (value === undefined || !choices.includes(value)) {
            value = choices[0];
          }
        } else if (value !== undefined && spec.date_columns.includes(header)) {
          value = exampleDate(value, calendar);
        }
        if (value === undefined || value === "") return;
        const cell = example.getCell(offset + 2, index + 1);
        cell.value = value;
        cell.font = grey;
      });
    });
    const note = example.getCell(text.example.length + 3, 1);
    note.value = text.exampleNote;
    note.font = { ...grey, bold: true };
  }

  help.getColumn(1).width = 26;
  help.getColumn(2).width = 90;
  help.addRow([text.title]).font = { bold: true, size: 14 };
  for (const line of text.lines) help.addRow([line]);
  if (columns) help.addRow([text.exampleLine]);
  help.addRow([]);
  help.addRow([text.columnHeading, text.takesHeading]).font = { bold: true };
  const addColumnRow = (header: string) => {
    const name = spec.required.includes(header)
      ? `${header} (${text.required})`
      : header;
    help.addRow([name, text.help[header] ?? ""]);
  };
  if (columns) {
    const placed = new Set<string>();
    for (const group of GROUP_ORDER) {
      const inGroup = spec.headers.filter((h) => groupOf.get(h) === group);
      if (inGroup.length === 0) continue;
      help.addRow([text.groups[group] ?? group]).font = { bold: true };
      for (const header of inGroup) {
        addColumnRow(header);
        placed.add(header);
      }
    }
    // A column grouped under a name this build does not know.
    for (const header of spec.headers) {
      if (!placed.has(header)) addColumnRow(header);
    }
  } else {
    for (const header of spec.headers) addColumnRow(header);
  }

  return (await workbook.xlsx.writeBuffer()) as ArrayBuffer;
}

/** The heading of the column that says why a line was refused. The server ignores it when the file comes back. */
export const PROBLEM_HEADING = "Problem";

/**
 * A sheet of text and nothing else: every cell is a string formatted `@`, so a
 * leading zero, a long number and a date are what was written.
 */
function writeTextSheet(
  workbook: ExcelWorkbook,
  name: string,
  rows: string[][],
): void {
  const sheet = workbook.addWorksheet(name, {
    views: [{ state: "frozen", ySplit: 1 }],
  });
  const width = rows.reduce((most, row) => Math.max(most, row.length), 0);
  for (let c = 1; c <= width; c++) {
    const longest = rows.reduce(
      (most, row) => Math.max(most, (row[c - 1] ?? "").length),
      0,
    );
    const column = sheet.getColumn(c);
    column.numFmt = "@";
    column.width = Math.min(60, Math.max(12, longest + 2));
  }
  rows.forEach((cells, r) => {
    cells.forEach((text, c) => {
      const cell = sheet.getCell(r + 1, c + 1);
      cell.numFmt = "@";
      cell.value = text;
      if (r === 0) cell.font = { bold: true };
    });
  });
}

/**
 * The lines a list was refused for, as a workbook to correct and bring back:
 * the sheet people are typed into, the list's own header line, each refused
 * line's cells as they were read, and a last column saying why.
 *
 * The lines go back through the same reader, which ignores the `Problem`
 * column, so nothing here needs undoing before the file is used again.
 */
export async function buildFixList(
  header: string[],
  lines: { cells: string[]; problem: string }[],
): Promise<ArrayBuffer> {
  const { Workbook } = await loadExcel();
  const workbook: ExcelWorkbook = new Workbook();

  // The problem column sits after every column any line has.
  const width = lines.reduce(
    (most, line) => Math.max(most, line.cells.length),
    header.length,
  );
  const pad = (cells: string[]) => [
    ...cells,
    ...Array<string>(width - cells.length).fill(""),
  ];

  writeTextSheet(workbook, SHEET_PEOPLE, [
    [...pad(header), PROBLEM_HEADING],
    ...lines.map((line) => [...pad(line.cells), line.problem]),
  ]);

  return (await workbook.xlsx.writeBuffer()) as ArrayBuffer;
}

/** The sheet of a list's result. */
export const SHEET_RESULT = "Result";

/**
 * What became of a list, one row per line, as text: a name, a number, what
 * happened and a note. `headings` are the four column headings in the
 * reader's language.
 */
export async function buildResult(
  headings: [string, string, string, string],
  rows: [string, string, string, string][],
): Promise<ArrayBuffer> {
  const { Workbook } = await loadExcel();
  const workbook: ExcelWorkbook = new Workbook();
  writeTextSheet(workbook, SHEET_RESULT, [headings, ...rows]);
  return (await workbook.xlsx.writeBuffer()) as ArrayBuffer;
}

/** The sheet of people to enrol at a terminal by hand. */
export const SHEET_ENROLMENT = "Enrolment";

/**
 * The people to enrol at a terminal ETHR cannot write to: the number each is
 * to be given there, and the name, as text. `headings` are the two column
 * headings in the reader's language.
 */
export async function buildEnrolmentList(
  headings: [string, string],
  rows: [string, string][],
): Promise<ArrayBuffer> {
  const { Workbook } = await loadExcel();
  const workbook: ExcelWorkbook = new Workbook();
  writeTextSheet(workbook, SHEET_ENROLMENT, [headings, ...rows]);
  return (await workbook.xlsx.writeBuffer()) as ArrayBuffer;
}
