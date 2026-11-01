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
 *
 * Hire dates may be written in the Ethiopian or the Gregorian calendar, and
 * the two look the same: 2011-10-30 is a valid date in both, eight years
 * apart. Which one is meant comes from the column name when it says
 * ("Hire date (E.C.)"), otherwise from the choice beside the list, and the
 * dates are sent to the server in Gregorian.
 */

import { daysInEthiopianMonth, toGregorian } from "@/lib/calendar/ethiopian";

export type PersonField =
  "name" | "email" | "phone" | "employee_code" | "hire_date" | "department";

export type PersonRow = Partial<Record<PersonField, string>> & {
  name: string;
};

export type ProblemKind =
  | "no_name"
  | "bad_email"
  | "bad_date"
  | "duplicate_code"
  | "duplicate_email"
  | "unknown_department"
  | "too_many";

export interface Problem {
  /** 1-based, counted as the reader sees it, the header row included. */
  line: number;
  kind: ProblemKind;
  value?: string;
  /** For a duplicate: the line that has the same value first. */
  firstLine?: number;
}

/** The most rows one staging request takes (StageRowsRequest). */
export const MAX_PEOPLE = 2000;

/** A department as the server matches it (DepartmentMatch): code, name or Amharic name. */
export interface KnownDepartment {
  code?: string | null;
  name: string;
  name_am?: string | null;
}

export type DateCalendar = "ethiopian" | "gregorian";

export interface ParsedPeople {
  rows: PersonRow[];
  problems: Problem[];
  /** The columns in the order read, or null when no header row was found. */
  header: PersonField[] | null;
  /** The calendar hire dates were read in: the column name's, else the one chosen. */
  calendar: DateCalendar;
  /** True when the column name said which calendar, overriding the choice. */
  calendarFromHeader: boolean;
  /** Rows ready to send that have no hire date, so the server uses today. */
  withoutHireDate: number;
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

/** "E.C.", "EC", "ዓ.ም" name the Ethiopian calendar; "G.C.", "GC", "AD" the Gregorian. */
const CALENDAR_MARKS: [string, DateCalendar][] = [
  ["ec", "ethiopian"],
  ["ዓም", "ethiopian"],
  ["gc", "gregorian"],
  ["ad", "gregorian"],
];

/** A header cell's field, and the calendar it names, if any ("Hire date (E.C.)"). */
function columnOf(cell: string): {
  field: PersonField | null;
  calendar: DateCalendar | null;
} {
  const key = normalise(cell);
  if (ALIASES[key]) return { field: ALIASES[key], calendar: null };
  for (const [mark, calendar] of CALENDAR_MARKS) {
    if (
      key.endsWith(mark) &&
      ALIASES[key.slice(0, -mark.length)] === "hire_date"
    ) {
      return { field: "hire_date", calendar };
    }
  }
  return { field: null, calendar: null };
}

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

function iso(date: Date): string {
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${String(date.getFullYear()).padStart(4, "0")}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/**
 * YYYY-MM-DD, also written with slashes or dots, in the calendar given, as a
 * Gregorian YYYY-MM-DD. A trailing "E.C." or "G.C." on the value decides for
 * that value. Day-first and month-first forms are refused rather than
 * guessed: 03/04/2019 is a different day in different places, and a wrong
 * hire date moves leave and severance (2026-10-30). A day the month does not
 * have (February 30, Pagume 7) is refused too.
 */
function isoDate(value: string, calendar: DateCalendar): string | null {
  const m =
    /^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})\s*([A-Za-z.\u1200-\u137F]*)$/.exec(
      value.trim(),
    );
  if (!m) return null;
  const [, y, mo, d, mark] = m;
  let used = calendar;
  if (mark !== "") {
    const named = CALENDAR_MARKS.find(([k]) => normalise(mark) === k);
    if (!named) return null;
    used = named[1];
  }
  const year = Number(y);
  const month = Number(mo);
  const day = Number(d);
  if (used === "ethiopian") {
    if (month < 1 || month > 13) return null;
    if (day < 1 || day > daysInEthiopianMonth(year, month)) return null;
    return iso(toGregorian(year, month, day));
  }
  if (month < 1 || month > 12 || day < 1) return null;
  const date = new Date(2000, 0, 1);
  date.setFullYear(year, month - 1, day);
  if (date.getMonth() !== month - 1) return null;
  return iso(date);
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
function headerOf(
  cells: string[],
): { fields: (PersonField | null)[]; calendar: DateCalendar | null } | null {
  const columns = cells.map(columnOf);
  const fields = columns.map((c) => c.field);
  if (!fields.includes("name")) return null;
  if (cells.some((c) => EMAIL.test(c) || isoDate(c, "gregorian") !== null)) {
    return null;
  }
  return {
    fields,
    calendar: columns.find((c) => c.calendar !== null)?.calendar ?? null,
  };
}

/**
 * @param departments The organisation's departments, when known. A department
 *   the organisation does not have is then reported here rather than refused
 *   by the server after staging. Unknown (undefined), no department is checked.
 * @param chosen The calendar hire dates are in, unless the column name says.
 */
export function parsePeople(
  text: string,
  departments?: KnownDepartment[],
  chosen: DateCalendar = "gregorian",
): ParsedPeople {
  const raw = text.replace(/^﻿/, "").split(/\r?\n/);
  const numbered = raw
    .map((line, index) => ({ line, number: index + 1 }))
    .filter(({ line }) => line.trim() !== "");
  if (numbered.length === 0) {
    return {
      rows: [],
      problems: [],
      header: null,
      calendar: chosen,
      calendarFromHeader: false,
      withoutHireDate: 0,
    };
  }

  const separator = separatorOf(numbered.map((n) => n.line));
  const header = headerOf(cellsOf(numbered[0].line, separator));
  const columns: (PersonField | null)[] = header?.fields ?? DEFAULT_ORDER;
  const calendar = header?.calendar ?? chosen;
  const body = header ? numbered.slice(1) : numbered;

  const rows: PersonRow[] = [];
  const problems: Problem[] = [];
  // Employee numbers and emails are unique per organisation, so one used
  // twice would stage and then fail when the people are brought in.
  const codeLines = new Map<string, number>();
  const emailLines = new Map<string, number>();
  const knownDepartments =
    departments === undefined
      ? null
      : new Set(
          departments.flatMap((d) =>
            [d.code, d.name, d.name_am]
              .filter((v): v is string => !!v && v.trim() !== "")
              .map((v) => v.trim().toLowerCase()),
          ),
        );

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
      const gregorian = isoDate(record.hire_date, calendar);
      if (gregorian === null) {
        problems.push({
          line: number,
          kind: "bad_date",
          value: record.hire_date,
        });
        continue;
      }
      record.hire_date = gregorian;
    }
    const code = record.employee_code?.toLowerCase();
    if (code !== undefined && codeLines.has(code)) {
      problems.push({
        line: number,
        kind: "duplicate_code",
        value: record.employee_code,
        firstLine: codeLines.get(code),
      });
      continue;
    }
    const email = record.email?.toLowerCase();
    if (email !== undefined && emailLines.has(email)) {
      problems.push({
        line: number,
        kind: "duplicate_email",
        value: record.email,
        firstLine: emailLines.get(email),
      });
      continue;
    }
    if (
      knownDepartments !== null &&
      record.department &&
      !knownDepartments.has(record.department.trim().toLowerCase())
    ) {
      problems.push({
        line: number,
        kind: "unknown_department",
        value: record.department,
      });
      continue;
    }
    if (code !== undefined) codeLines.set(code, number);
    if (email !== undefined) emailLines.set(email, number);
    rows.push(record as PersonRow);
  }

  if (rows.length > MAX_PEOPLE) {
    problems.push({
      line: body[body.length - 1].number,
      kind: "too_many",
      value: String(rows.length),
    });
  }

  return {
    rows,
    problems,
    header: header?.fields.filter((f): f is PersonField => f !== null) ?? null,
    calendar,
    calendarFromHeader: header?.calendar != null,
    withoutHireDate: rows.filter((r) => !r.hire_date).length,
  };
}

/**
 * A CSV with the column names the parser reads, and one example person. The
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
