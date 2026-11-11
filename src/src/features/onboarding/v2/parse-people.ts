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

/**
 * A CSV with column names the server reads, and one example person. The
 * hire date column names its calendar, so the file says how to read itself
 * wherever it is filled in.
 */
export function peopleTemplateCsv(
  calendar: DateCalendar = "gregorian",
): string {
  return [
    calendar === "ethiopian"
      ? "Name,Email,Phone,Employee number,Hire date (E.C.),Department"
      : "Name,Email,Phone,Employee number,Hire date (G.C.),Department",
    `Abebe Kebede,abebe@example.et,0911223344,EMP-001,${calendar === "ethiopian" ? "2011-10-30" : "2019-07-07"},Finance`,
  ].join("\r\n");
}
