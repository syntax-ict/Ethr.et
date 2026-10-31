/**
 * Reads a list of people pasted from a spreadsheet, typed by hand, or loaded
 * from a CSV file, for the guided setup's People step.
 *
 * Rows copied from Excel or Google Sheets arrive tab-separated, a CSV saved
 * from Excel in some locales uses semicolons, and a hand-typed list uses
 * commas: the separator is taken from the text. A first row of column names
 * (Name, Email, Hire date, ...) is recognised and decides which column is
 * which, in any order; without one the columns are read in the documented
 * order. Each line that cannot be sent is reported with its line number and
 * the reason, so it can be fixed before staging rather than after.
 */

export type PersonField =
  "name" | "email" | "phone" | "employee_code" | "hire_date" | "department";

export type PersonRow = Partial<Record<PersonField, string>> & {
  name: string;
};

export type ProblemKind = "no_name" | "bad_email" | "bad_date";

export interface Problem {
  /** 1-based, counted as the reader sees it, the header row included. */
  line: number;
  kind: ProblemKind;
  value?: string;
}

export interface ParsedPeople {
  rows: PersonRow[];
  problems: Problem[];
  /** The columns in the order read, or null when no header row was found. */
  header: PersonField[] | null;
}

export const DEFAULT_ORDER: PersonField[] = [
  "name",
  "email",
  "phone",
  "employee_code",
  "hire_date",
  "department",
];

/** Column names as people write them, compared lower-case without spaces or punctuation. */
const ALIASES: Record<string, PersonField> = {};
const NAMES: Record<PersonField, string[]> = {
  name: [
    "name",
    "full name",
    "employee name",
    "employee",
    "staff name",
    "ስም",
    "ሙሉ ስም",
  ],
  email: ["email", "e-mail", "email address", "mail", "ኢሜይል"],
  phone: [
    "phone",
    "phone number",
    "mobile",
    "mobile number",
    "telephone",
    "tel",
    "ስልክ",
  ],
  employee_code: [
    "employee number",
    "employee no",
    "employee code",
    "employee id",
    "staff id",
    "staff number",
    "code",
    "id",
    "number",
    "badge",
    "badge number",
    "የሰራተኛ ቁጥር",
  ],
  hire_date: [
    "hire date",
    "hired",
    "date hired",
    "start date",
    "joined",
    "date joined",
    "employment date",
    "የተቀጠረበት ቀን",
  ],
  department: ["department", "dept", "division", "unit", "ክፍል"],
};
for (const [field, names] of Object.entries(NAMES) as [
  PersonField,
  string[],
][]) {
  for (const name of names) ALIASES[normalise(name)] = field;
}

function normalise(value: string): string {
  return value.toLowerCase().replace(/[\s._\-/#:()]+/g, "");
}

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/**
 * Gregorian YYYY-MM-DD, also written with slashes or dots. Day-first and
 * month-first forms are refused rather than guessed: 03/04/2019 is a
 * different day in different places, and a wrong hire date moves leave and
 * severance (2026-10-30).
 */
function isoDate(value: string): string | null {
  const m = /^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})$/.exec(value);
  if (!m) return null;
  const [, y, mo, d] = m;
  const month = Number(mo);
  const day = Number(d);
  if (month < 1 || month > 12 || day < 1 || day > 31) return null;
  return `${y}-${mo.padStart(2, "0")}-${d.padStart(2, "0")}`;
}

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

/** A first row is a header when it names the name column and holds no data that looks like a person. */
function headerOf(cells: string[]): (PersonField | null)[] | null {
  const fields = cells.map((c) => ALIASES[normalise(c)] ?? null);
  if (!fields.includes("name")) return null;
  if (cells.some((c) => EMAIL.test(c) || isoDate(c) !== null)) return null;
  return fields;
}

export function parsePeople(text: string): ParsedPeople {
  const raw = text.replace(/^﻿/, "").split(/\r?\n/);
  const numbered = raw
    .map((line, index) => ({ line, number: index + 1 }))
    .filter(({ line }) => line.trim() !== "");
  if (numbered.length === 0) return { rows: [], problems: [], header: null };

  const separator = separatorOf(numbered.map((n) => n.line));
  const header = headerOf(cellsOf(numbered[0].line, separator));
  const columns: (PersonField | null)[] = header ?? DEFAULT_ORDER;
  const body = header ? numbered.slice(1) : numbered;

  const rows: PersonRow[] = [];
  const problems: Problem[] = [];

  for (const { line, number } of body) {
    const cells = cellsOf(line, separator);
    const record: Partial<Record<PersonField, string>> = {};
    columns.forEach((field, i) => {
      if (field === null) return;
      const value = cells[i] ?? "";
      if (value !== "" && record[field] === undefined) record[field] = value;
    });

    if (!record.name) {
      problems.push({ line: number, kind: "no_name" });
      continue;
    }
    if (record.email && !EMAIL.test(record.email)) {
      problems.push({ line: number, kind: "bad_email", value: record.email });
      continue;
    }
    if (record.hire_date) {
      const iso = isoDate(record.hire_date);
      if (iso === null) {
        problems.push({
          line: number,
          kind: "bad_date",
          value: record.hire_date,
        });
        continue;
      }
      record.hire_date = iso;
    }
    rows.push(record as PersonRow);
  }

  return {
    rows,
    problems,
    header: header?.filter((f): f is PersonField => f !== null) ?? null,
  };
}

/** A CSV with the column names the parser reads, and one example person. */
export function peopleTemplateCsv(): string {
  return [
    "Name,Email,Phone,Employee number,Hire date,Department",
    "Abebe Kebede,abebe@example.et,0911223344,EMP-001,2019-07-08,Finance",
  ].join("\r\n");
}
