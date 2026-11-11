import { describe, expect, it } from "vitest";
import { templateCsv, toGrid } from "@/features/onboarding/v2/parse-people";

/*
 * The browser splits a list into cells and nothing more (2026-11-11). What a
 * column name means, how a date is read and which lines can be staged are the
 * server's to say, and are tested there (PeopleListReadTest).
 */
describe("splitting a list of people into cells", () => {
  it("reads rows pasted from Excel: tab-separated, whatever the line endings", () => {
    expect(
      toGrid(
        [
          "Department\tFull name\tStart date\tStaff ID\tNotes",
          "Finance\tAbebe Kebede\t2019/7/8\tEMP-1\tsenior",
          "Sales\tHanna Girma\t\t\t",
        ].join("\r\n"),
      ),
    ).toEqual([
      ["Department", "Full name", "Start date", "Staff ID", "Notes"],
      ["Finance", "Abebe Kebede", "2019/7/8", "EMP-1", "senior"],
      ["Sales", "Hanna Girma", "", "", ""],
    ]);
  });

  it("reads a semicolon CSV with quoted cells and a byte order mark", () => {
    expect(
      toGrid(
        '﻿Name;Department\r\n"Kebede; Abebe";"Say ""hi"""\r\nHanna Girma;Sales',
      ),
    ).toEqual([
      ["Name", "Department"],
      ["Kebede; Abebe", 'Say "hi"'],
      ["Hanna Girma", "Sales"],
    ]);
  });

  it("reads a list typed by hand, with commas and stray spaces", () => {
    expect(toGrid("Abebe Kebede ,  abebe@acme.et, 0911223344\nHanna")).toEqual([
      ["Abebe Kebede", "abebe@acme.et", "0911223344"],
      ["Hanna"],
    ]);
  });

  it("keeps an empty line in its place, so a line number is the one the reader counts", () => {
    expect(toGrid("Name\n\nAbebe Kebede\n   \nHanna Girma\n\n\n")).toEqual([
      ["Name"],
      [],
      ["Abebe Kebede"],
      [],
      ["Hanna Girma"],
    ]);
  });

  it("makes nothing of an empty box", () => {
    expect(toGrid("")).toEqual([]);
    expect(toGrid(" \n\t\n")).toEqual([]);
  });

  it("does not take a tab inside one hand-typed line for the separator of a comma list", () => {
    // A tab anywhere means the list came from a spreadsheet.
    expect(toGrid("Name\tEmail\nAbebe, Kebede\tabebe@acme.et")).toEqual([
      ["Name", "Email"],
      ["Abebe, Kebede", "abebe@acme.et"],
    ]);
  });

  it("writes the template with each date column naming its calendar", () => {
    const headers = ["name", "hire_date", "date_of_birth", "department"];

    expect(toGrid(templateCsv(headers, "ethiopian"))).toEqual([
      ["name", "hire_date (E.C.)", "date_of_birth (E.C.)", "department"],
    ]);
    expect(toGrid(templateCsv(headers, "gregorian"))).toEqual([
      ["name", "hire_date (G.C.)", "date_of_birth (G.C.)", "department"],
    ]);
  });
});
