/**
 * Splits a list of people into cells: pasted from a spreadsheet, typed by
 * hand, or read from a CSV file.
 *
 * Rows copied from Excel or Google Sheets arrive tab-separated, a CSV saved
 * from Excel in some locales uses semicolons, and a hand-typed list uses
 * commas: the separator is taken from the text.
 *
 * That is all the browser decides. What a column name means, how a date is
 * read and whether a line can be staged are decided by the server
 * (`POST /onboarding/migration/read`), from the one definition every list of
 * people goes through. This file used to hold a second table of column names
 * and a second date rule, and they disagreed with the file import's: "Badge"
 * was an employee number here and a badge there (2026-11-11).
 */

export type DateCalendar = "ethiopian" | "gregorian";

/** The most people one staging takes (EmployeeImportGrid::MAX_PEOPLE). */
export const MAX_PEOPLE = 2000;

function separatorOf(lines: string[]): string {
  if (lines.some((l) => l.includes("\t"))) return "\t";
  const first = lines[0] ?? "";
  const semicolons = (first.match(/;/g) ?? []).length;
  const commas = (first.match(/,/g) ?? []).length;
  return semicolons > commas ? ";" : ",";
}

/** Splits one line, honouring double quotes ("Kebede, Abebe" stays one cell). */
function cellsOf(line: string, separator: string): string[] {
  const cells: string[] = [];
  let cell = "";
  let quoted = false;
  for (let i = 0; i < line.length; i++) {
    const ch = line[i];
    if (quoted) {
      if (ch === '"' && line[i + 1] === '"') {
        cell += '"';
        i++;
      } else if (ch === '"') {
        quoted = false;
      } else {
        cell += ch;
      }
    } else if (ch === '"' && cell.trim() === "") {
      quoted = true;
      cell = "";
    } else if (ch === separator) {
      cells.push(cell.trim());
      cell = "";
    } else {
      cell += ch;
    }
  }
  cells.push(cell.trim());
  return cells;
}

/**
 * The text as lines of cells. An empty line stays in its place as an empty
 * row, so a line number the server reports is the one the reader counts.
 * Trailing empty lines are dropped.
 */
export function toGrid(text: string): string[][] {
  const lines = text.replace(/^﻿/, "").split(/\r\n?|\n/);
  while (lines.length > 0 && lines[lines.length - 1].trim() === "") {
    lines.pop();
  }
  if (lines.length === 0) return [];

  const separator = separatorOf(lines.filter((l) => l.trim() !== ""));
  return lines.map((line) =>
    line.trim() === "" ? [] : cellsOf(line, separator),
  );
}

/** The template's columns that hold a date (EmployeeImportRow::DATES). */
const DATE_COLUMNS = ["hire_date", "date_of_birth"];

/**
 * The one employee template, as a CSV header row: the server's columns, with
 * each date column naming its calendar, so the file says how to read itself
 * wherever it is filled in.
 */
export function templateCsv(
  headers: string[],
  calendar: DateCalendar = "gregorian",
): string {
  const mark = calendar === "ethiopian" ? "(E.C.)" : "(G.C.)";
  return (
    headers
      .map((header) =>
        DATE_COLUMNS.includes(header) ? `${header} ${mark}` : header,
      )
      .join(",") + "\r\n"
  );
}

/** What a text file's bytes were read as. */
export type TextEncoding = "utf-8" | "utf-16le" | "utf-16be" | "windows-1252";

/**
 * The text of a CSV or text file, from its bytes. A byte order mark is
 * stripped and, for UTF-16, says which way round the bytes are. Without one
 * the bytes are taken as UTF-8 if they are valid UTF-8, and otherwise as
 * Windows-1252, which is what Excel writes for a "CSV" in a Western locale.
 * `guessed` says the last was the way out, so the screen can say so: a file
 * that is neither is read wrongly, and the reader should be able to see it.
 */
export function decodeText(bytes: Uint8Array): {
  text: string;
  encoding: TextEncoding;
  guessed: boolean;
} {
  if (bytes[0] === 0xff && bytes[1] === 0xfe) {
    return {
      text: new TextDecoder("utf-16le").decode(bytes.subarray(2)),
      encoding: "utf-16le",
      guessed: false,
    };
  }
  if (bytes[0] === 0xfe && bytes[1] === 0xff) {
    return {
      text: new TextDecoder("utf-16be").decode(bytes.subarray(2)),
      encoding: "utf-16be",
      guessed: false,
    };
  }

  const start =
    bytes[0] === 0xef && bytes[1] === 0xbb && bytes[2] === 0xbf ? 3 : 0;
  try {
    return {
      text: new TextDecoder("utf-8", { fatal: true }).decode(
        bytes.subarray(start),
      ),
      encoding: "utf-8",
      guessed: false,
    };
  } catch {
    return {
      text: new TextDecoder("windows-1252").decode(bytes.subarray(start)),
      encoding: "windows-1252",
      guessed: true,
    };
  }
}
