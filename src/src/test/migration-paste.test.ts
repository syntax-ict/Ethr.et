import { describe, expect, it } from "vitest";
import {
  parsePeople,
  peopleTemplateCsv,
} from "@/features/onboarding/v2/parse-people";

describe("the guided setup's list of people", () => {
  it("reads the documented order when there is no header, each column but the name optional", () => {
    const { rows, problems, header } = parsePeople(
      [
        "Abebe Kebede, abebe@acme.et, 0911223344, EMP-1, 2019-07-08, Finance",
        "Hanna Girma",
        "Sara Tesfaye, , , EMP-3, , Sales",
      ].join("\n"),
    );

    expect(header).toBeNull();
    expect(problems).toEqual([]);
    expect(rows).toEqual([
      {
        name: "Abebe Kebede",
        email: "abebe@acme.et",
        phone: "0911223344",
        employee_code: "EMP-1",
        hire_date: "2019-07-08",
        department: "Finance",
      },
      { name: "Hanna Girma" },
      { name: "Sara Tesfaye", employee_code: "EMP-3", department: "Sales" },
    ]);
  });

  it("reads rows pasted from Excel: tab-separated, a header row, columns in any order", () => {
    const { rows, problems, header } = parsePeople(
      [
        "Department\tFull name\tStart date\tStaff ID\tNotes",
        "Finance\tAbebe Kebede\t2019/7/8\tEMP-1\tsenior",
        "Sales\tHanna Girma\t\t\t",
      ].join("\r\n"),
    );

    expect(header).toEqual([
      "department",
      "name",
      "hire_date",
      "employee_code",
    ]);
    expect(problems).toEqual([]);
    expect(rows).toEqual([
      {
        department: "Finance",
        name: "Abebe Kebede",
        hire_date: "2019-07-08",
        employee_code: "EMP-1",
      },
      { department: "Sales", name: "Hanna Girma" },
    ]);
  });

  it("reads a semicolon CSV with quoted cells and a byte order mark", () => {
    const { rows } = parsePeople(
      '﻿Name;Email;Phone\n"Kebede; Abebe";abebe@acme.et;"0911 22 33 44"',
    );

    expect(rows).toEqual([
      { name: "Kebede; Abebe", email: "abebe@acme.et", phone: "0911 22 33 44" },
    ]);
  });

  it("names each line it cannot send, counting the header line, instead of guessing", () => {
    const { rows, problems } = parsePeople(
      [
        "Name,Email,Hire date",
        "A,,2019-07-08",
        "B,,08/07/2019",
        ",c@acme.et,",
        "D,not-an-email,",
        "E,,2011 E.C.",
      ].join("\n"),
    );

    expect(rows.map((r) => r.name)).toEqual(["A"]);
    expect(problems).toEqual([
      { line: 3, kind: "bad_date", value: "08/07/2019" },
      { line: 4, kind: "no_name" },
      { line: 5, kind: "bad_email", value: "not-an-email" },
      { line: 6, kind: "bad_date", value: "2011 E.C." },
    ]);
  });

  it("does not take a first person for a header", () => {
    const { header, rows } = parsePeople("Name Person, name@acme.et");

    expect(header).toBeNull();
    expect(rows).toEqual([{ name: "Name Person", email: "name@acme.et" }]);
  });

  it("reads its own template back", () => {
    const { rows, problems, header } = parsePeople(peopleTemplateCsv());

    expect(problems).toEqual([]);
    expect(header).toEqual([
      "name",
      "email",
      "phone",
      "employee_code",
      "hire_date",
      "department",
    ]);
    expect(rows).toHaveLength(1);
  });

  it("names an employee number or email used twice, pointing at the first line", () => {
    const { rows, problems } = parsePeople(
      [
        "Name,Email,Employee number",
        "A,a@acme.et,EMP-1",
        "B,b@acme.et,emp-1",
        "C,A@ACME.ET,EMP-3",
      ].join("\n"),
    );

    expect(rows.map((r) => r.name)).toEqual(["A"]);
    expect(problems).toEqual([
      { line: 3, kind: "duplicate_code", value: "emp-1", firstLine: 2 },
      { line: 4, kind: "duplicate_email", value: "A@ACME.ET", firstLine: 2 },
    ]);
  });

  it("checks departments against the organisation's, by code, name or Amharic name", () => {
    const departments = [
      { code: "FIN", name: "Finance", name_am: "ፋይናንስ" },
      { code: null, name: "Sales", name_am: null },
    ];
    const { rows, problems } = parsePeople(
      [
        "Name,Department",
        "A,fin",
        "B,ፋይናንስ",
        "C, sales ",
        "D,Marketing",
        "E,",
      ].join("\n"),
      departments,
    );

    expect(rows.map((r) => r.name)).toEqual(["A", "B", "C", "E"]);
    expect(problems).toEqual([
      { line: 5, kind: "unknown_department", value: "Marketing" },
    ]);

    // Departments not loaded yet: nothing is checked, the server still is.
    expect(parsePeople("D, , , , , Marketing").problems).toEqual([]);
  });

  it("says when a list is over the limit one staging takes", () => {
    const text = Array.from({ length: 2001 }, (_, i) => `Person ${i}`).join(
      "\n",
    );
    const { problems } = parsePeople(text);

    expect(problems).toEqual([{ line: 2001, kind: "too_many", value: "2001" }]);
  });
});
