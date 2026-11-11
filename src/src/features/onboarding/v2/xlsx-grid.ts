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
import type { DateCalendar } from "./parse-people";

/** The sheet people are typed into. The reader takes this one, or the first sheet that is not ours. */
export const SHEET_PEOPLE = "Employees";
export const SHEET_HELP = "Instructions";
export const SHEET_LISTS = "Lists";

/** The most lines one staging takes (StageRowsRequest: `grid` max). */
export const MAX_LINES = 5000;

/** Rows of the template that carry the dropdowns: the most people one list brings. */
const TEMPLATE_ROWS = 2000;

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
export interface TemplateSpec {
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
    visible.find((s) => s.name !== SHEET_HELP && s.name !== SHEET_LISTS);
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
): Promise<ArrayBuffer> {
  const { Workbook } = await loadExcel();
  const workbook: ExcelWorkbook = new Workbook();
  const mark = calendar === "ethiopian" ? "(E.C.)" : "(G.C.)";

  const people = workbook.addWorksheet(SHEET_PEOPLE, {
    views: [{ state: "frozen", ySplit: 1 }],
  });
  const help = workbook.addWorksheet(SHEET_HELP);
  const lists = workbook.addWorksheet(SHEET_LISTS, { state: "veryHidden" });
  const validations = (
    people as unknown as {
      dataValidations: { add: (range: string, rule: unknown) => void };
    }
  ).dataValidations;

  let listColumn = 0;
  spec.headers.forEach((header, index) => {
    const column = people.getColumn(index + 1);
    const isDate = spec.date_columns.includes(header);
    const label = isDate ? `${header} ${mark}` : header;

    column.width = Math.max(14, label.length + 4);
    if (isDate || spec.text_columns.includes(header)) column.numFmt = "@";

    const cell = people.getCell(1, index + 1);
    cell.value = label;
    cell.font = { bold: true };

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

  help.getColumn(1).width = 26;
  help.getColumn(2).width = 90;
  help.addRow([text.title]).font = { bold: true, size: 14 };
  for (const line of text.lines) help.addRow([line]);
  help.addRow([]);
  help.addRow([text.columnHeading, text.takesHeading]).font = { bold: true };
  for (const header of spec.headers) {
    const name = spec.required.includes(header)
      ? `${header} (${text.required})`
      : header;
    help.addRow([name, text.help[header] ?? ""]);
  }

  return (await workbook.xlsx.writeBuffer()) as ArrayBuffer;
}
