/**
 * Client-side CSV generation from already-fetched dashboard JSON — no backend
 * export endpoint needed, and nothing leaves the browser except the download.
 * Deliberately simple: flat rows only, no charting/formatting concerns.
 */

/** Pure CSV-string builder — kept separate from the DOM side effect so it's testable. */
export function buildCsv(rows: Array<Record<string, string | number>>): string {
  if (rows.length === 0) return "";

  const headers = Object.keys(rows[0]);
  const escape = (value: string | number) => {
    const s = String(value);
    return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
  };

  const lines = [
    headers.join(","),
    ...rows.map((row) => headers.map((h) => escape(row[h] ?? "")).join(",")),
  ];

  return lines.join("\n");
}

export function downloadCsv(
  filename: string,
  rows: Array<Record<string, string | number>>,
) {
  const csv = buildCsv(rows);
  if (csv === "") return;

  const blob = new Blob([csv], { type: "text/csv;charset=utf-8;" });
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  link.click();
  URL.revokeObjectURL(url);
}
