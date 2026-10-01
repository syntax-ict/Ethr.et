/**
 * Client-side CSV generation from already-fetched JSON — no backend export
 * endpoint needed, and nothing leaves the browser except the download.
 *
 * Every CSV the app builds goes through `csvCell`. Before it existed there
 * were six hand-rolled builders, and between them they split amounts like
 * "12,345.67" across two columns (the payroll register and the bank transfer
 * file joined unquoted cells), and none neutralised spreadsheet formulas.
 */

const PLAIN_NUMBER = /^[-+]?\d+(\.\d+)?$/;
const FORMULA_START = /^[=+\-@\t\r]/;

/**
 * One CSV cell. A value Excel or Sheets would evaluate — `=HYPERLINK(...)` in
 * an employee name, say — is prefixed with an apostrophe so it stays text
 * (OWASP "CSV injection"); plain numbers such as "-250.00" are left alone.
 * Quoted when it contains a delimiter, a quote or a line break.
 */
export function csvCell(value: unknown): string {
  let text = value == null ? "" : String(value);
  if (FORMULA_START.test(text) && !PLAIN_NUMBER.test(text)) {
    text = `'${text}`;
  }
  return /[",\n\r]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

/** Header row plus data rows, each cell through `csvCell`. */
export function csvFromRows(
  headers: readonly string[],
  rows: ReadonlyArray<ReadonlyArray<unknown>>,
): string {
  return [headers, ...rows].map((r) => r.map(csvCell).join(",")).join("\n");
}

/** Pure CSV-string builder — kept separate from the DOM side effect so it's testable. */
export function buildCsv(rows: Array<Record<string, unknown>>): string {
  if (rows.length === 0) return "";

  const headers = Object.keys(rows[0]);
  return csvFromRows(
    headers,
    rows.map((row) => headers.map((h) => row[h])),
  );
}

/**
 * A money amount for a CSV cell: two decimals, no grouping. A locale format
 * ("12,345.67") is a second delimiter to a CSV reader.
 */
export function csvAmount(cents: number): string {
  return (cents / 100).toFixed(2);
}

/** Save CSV text as a file. */
export function saveCsv(filename: string, csv: string) {
  if (csv === "") return;

  const blob = new Blob([csv], { type: "text/csv;charset=utf-8;" });
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  link.click();
  URL.revokeObjectURL(url);
}

export function downloadCsv(
  filename: string,
  rows: Array<Record<string, unknown>>,
) {
  saveCsv(filename, buildCsv(rows));
}
