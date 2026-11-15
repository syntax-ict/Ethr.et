import {
  buildTemplate,
  type TemplateSpec,
  type TemplateText,
} from "@/features/onboarding/v2/xlsx-grid";
import { MAX_PEOPLE, toGrid } from "@/features/onboarding/v2/parse-people";
import type { EmployeeExport } from "./api";

/** The file the list is saved as. */
export const COMPLETE_FILE = "ethr-employees-to-complete.xlsx";

/**
 * The list shows more people than one file can be staged with. The server
 * takes 2,000 at a time, and a file cut short would leave people out without
 * saying so, so none is built.
 */
export class TooManyToComplete extends Error {
  constructor(
    public readonly count: number,
    public readonly max: number = MAX_PEOPLE,
  ) {
    super(`${count} people is more than one file takes (${max}).`);
    this.name = "TooManyToComplete";
  }
}

/** The statuses a list may bring someone in with; anyone who has left has none to give. */
const LIST_STATUSES = ["hired", "probation", "confirmed"];

/**
 * The export puts a quote in front of a cell that starts with = + - or @, so
 * a spreadsheet does not run it. A phone number is text in the workbook, and
 * the quote would show; the import takes it off anyway, so it is not needed.
 */
function unguarded(header: string, value: string, spec: TemplateSpec) {
  return spec.text_columns.includes(header) && /^'[=+\-@]/.test(value)
    ? value.slice(1)
    : value;
}

/**
 * The employee workbook, prefilled with the people an export holds, in the
 * Gregorian calendar the export writes its dates in (the header says so, and
 * the import reads the calendar from it).
 *
 * The export is read with the reader the import uses. What it does not give
 * (a salary, a TIN, a bank account) is a blank cell, and a blank cell changes
 * nothing when the file is brought back. A status the import would refuse
 * (someone who has left) is left blank for the same reason.
 *
 * @throws TooManyToComplete when the export holds more people than one file takes
 */
export async function buildCompleteWorkbook(
  exported: Pick<EmployeeExport, "csv" | "count">,
  spec: TemplateSpec,
  text: TemplateText,
): Promise<ArrayBuffer> {
  if (exported.count > MAX_PEOPLE) {
    throw new TooManyToComplete(exported.count);
  }

  const [names = [], ...lines] = toGrid(exported.csv);
  const rows = lines
    .filter((cells) => cells.some((cell) => cell !== ""))
    .map((cells) => {
      const row: Record<string, string> = {};
      names.forEach((header, index) => {
        const value = unguarded(header, cells[index] ?? "", spec);
        row[header] =
          header === "status" && !LIST_STATUSES.includes(value) ? "" : value;
      });
      return row;
    });

  return buildTemplate(spec, "gregorian", text, rows);
}
