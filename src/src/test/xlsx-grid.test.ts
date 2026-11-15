import { describe, expect, it } from "vitest";
import { Workbook } from "exceljs";
import { toEthiopian } from "@/lib/calendar/ethiopian";
import { toGrid } from "@/features/onboarding/v2/parse-people";
import {
  buildTemplate,
  gridToText,
  MAX_LINES,
  NoSheet,
  readWorkbook,
  SHEET_EXAMPLE,
  SHEET_HELP,
  SHEET_LISTS,
  SHEET_PEOPLE,
  TooManyLines,
  type TemplateSpec,
  type TemplateText,
} from "@/features/onboarding/v2/xlsx-grid";

/*
 * A workbook is opened in the browser and its cells sent as text (One
 * employee identity, PR 7). These pin what a cell becomes on the way: the
 * server decides what it means (ExcelCoercionTest, PeopleListReadTest).
 */

async function bytes(workbook: Workbook): Promise<ArrayBuffer> {
  return (await workbook.xlsx.writeBuffer()) as ArrayBuffer;
}

const SPEC: TemplateSpec = {
  headers: [
    "name",
    "hire_date",
    "employee_code",
    "phone",
    "gender",
    "date_of_birth",
    "department",
    "national_id",
    "status",
  ],
  required: ["name"],
  text_columns: ["employee_code", "phone", "national_id"],
  date_columns: ["hire_date", "date_of_birth"],
  lists: {
    gender: ["male", "female"],
    status: ["hired", "probation", "confirmed"],
    department: ["Finance", "Sales, North", "SAL-S"],
  },
};

const TEXT: TemplateText = {
  title: "ETHR employee template",
  lines: ["Fill in the Employees sheet, one person a row."],
  columnHeading: "Column",
  takesHeading: "What it takes",
  required: "required",
  help: { name: "Full name", phone: "09... or +251..." },
  notInList: "This is not one of the listed values.",
  groups: { core: "Core", person: "Person", job: "Job" },
  example: [
    {
      name: "Abebe Kebede",
      hire_date: "2019-07-08",
      gender: "male",
      department: "Marketing",
      status: "probation",
      national_id: "1234567890123456",
    },
    { name: "Hanna Girma", date_of_birth: "1990-01-15", gender: "other" },
  ],
  exampleNote: "This sheet is only an example. Nothing on it is read.",
};

const COLUMNS: NonNullable<TemplateSpec["columns"]> = [
  { key: "name", group: "core", required: true },
  { key: "hire_date", group: "job", required: false },
  { key: "employee_code", group: "core", required: false },
  { key: "phone", group: "person", required: false },
  { key: "gender", group: "person", required: false },
  { key: "date_of_birth", group: "person", required: false },
  {
    key: "department_code",
    column: "department",
    group: "job",
    required: false,
  },
  { key: "national_id", group: "person", required: false },
  { key: "status", group: "job", required: false },
];
const GROUPED: TemplateSpec = { ...SPEC, columns: COLUMNS };

describe("reading a workbook into cells", () => {
  it("reads each kind of cell as the text a person sees", async () => {
    const workbook = new Workbook();
    const sheet = workbook.addWorksheet("Staff");
    sheet.addRow([
      "Name",
      "Staff ID",
      "Mobile",
      "Start date",
      "Salary",
      "Note",
    ]);
    sheet.addRow([
      "  Abebe Kebede ",
      1001,
      "0911223344",
      new Date(Date.UTC(2019, 6, 8)),
      12500.5,
      { richText: [{ text: "senior " }, { text: "accountant" }] },
    ]);
    sheet.addRow([
      { formula: 'A2&" Jr"', result: "Abebe Kebede Jr" },
      { formula: "B2+1", result: 1002 },
      { text: "0911000000", hyperlink: "tel:0911000000" },
      "2011-10-30",
      true,
      null,
    ]);

    expect(await readWorkbook(await bytes(workbook))).toEqual([
      ["Name", "Staff ID", "Mobile", "Start date", "Salary", "Note"],
      [
        "Abebe Kebede",
        "1001",
        "0911223344",
        // A date cell is Gregorian whatever the list is in, and says so.
        "2019-07-08 G.C.",
        "12500.5",
        "senior accountant",
      ],
      ["Abebe Kebede Jr", "1002", "0911000000", "2011-10-30", "true"],
    ]);
  });

  it("writes a number the spreadsheet has rounded the way the server refuses it", async () => {
    const workbook = new Workbook();
    const sheet = workbook.addWorksheet("Staff");
    sheet.addRow(["Name", "National ID"]);
    // Sixteen digits typed into a number cell: Excel keeps fifteen.
    sheet.addRow(["Abebe Kebede", 1234567890123460]);
    sheet.addRow(["Hanna Girma", 999999999999999]);

    const grid = await readWorkbook(await bytes(workbook));

    expect(grid[1][1]).toMatch(/^\d(\.\d+)?e\+\d+$/);
    expect(grid[2][1]).toBe("999999999999999");
  });

  it("keeps an empty row in its place and drops the empty tail", async () => {
    const workbook = new Workbook();
    const sheet = workbook.addWorksheet("Staff");
    sheet.getCell("A1").value = "Name";
    sheet.getCell("A3").value = "Abebe Kebede";
    sheet.getCell("C3").value = "Finance";
    // A formatted, empty row far below, as a template leaves behind.
    sheet.getCell("A40").numFmt = "@";

    expect(await readWorkbook(await bytes(workbook))).toEqual([
      ["Name"],
      [],
      ["Abebe Kebede", "", "Finance"],
    ]);
  });

  it("reads the Employees sheet, and never the instructions or the hidden lists", async () => {
    const workbook = new Workbook();
    workbook.addWorksheet(SHEET_HELP).addRow(["How to fill this in"]);
    workbook.addWorksheet("Old data", { state: "hidden" }).addRow(["Nobody"]);
    workbook.addWorksheet(SHEET_LISTS, { state: "veryHidden" }).addRow(["x"]);
    workbook.addWorksheet("Other").addRow(["Not this one"]);
    workbook.addWorksheet(SHEET_PEOPLE).addRow(["Name"]);

    expect(await readWorkbook(await bytes(workbook))).toEqual([["Name"]]);

    const renamed = new Workbook();
    renamed.addWorksheet(SHEET_HELP).addRow(["How to fill this in"]);
    renamed.addWorksheet("ሰራተኞች").addRow(["ስም"]);
    expect(await readWorkbook(await bytes(renamed))).toEqual([["ስም"]]);
  });

  it("says when there is no sheet of people, or too many lines", async () => {
    const onlyHelp = new Workbook();
    onlyHelp.addWorksheet(SHEET_HELP).addRow(["How to fill this in"]);
    await expect(readWorkbook(await bytes(onlyHelp))).rejects.toBeInstanceOf(
      NoSheet,
    );

    const long = new Workbook();
    const sheet = long.addWorksheet("Staff");
    for (let i = 0; i <= MAX_LINES; i++) sheet.addRow([`Person ${i}`]);
    await expect(readWorkbook(await bytes(long))).rejects.toBeInstanceOf(
      TooManyLines,
    );
  });
});

describe("a workbook's cells as the list box holds them", () => {
  it("goes to text and back to the same cells", () => {
    const grid = [
      ["Name", "Department", "Note"],
      ["Kebede, Abebe", "Sales; North", '"quoted" start'],
      [],
      ["Hanna Girma", "", 'mid "quote"'],
    ];

    expect(toGrid(gridToText(grid))).toEqual(grid);
  });

  it("keeps a one-column list with commas in it one column", () => {
    const grid = [["Name"], ["Kebede, Abebe"], ["Girma; Hanna"]];

    expect(toGrid(gridToText(grid))).toEqual(grid);
  });

  it("flattens a line break or a tab inside a cell", () => {
    expect(toGrid(gridToText([["Abebe\nKebede", "a\tb"]]))).toEqual([
      ["Abebe Kebede", "a b"],
    ]);
  });
});

describe("the employee template as a workbook", () => {
  async function template(calendar: "ethiopian" | "gregorian") {
    const workbook = new Workbook();
    await workbook.xlsx.load(await buildTemplate(SPEC, calendar, TEXT));
    return workbook;
  }

  it("has the server's columns, each date column naming its calendar, and reads back as its own header", async () => {
    const data = await buildTemplate(SPEC, "ethiopian", TEXT);

    expect(await readWorkbook(data)).toEqual([
      [
        "name",
        "hire_date (E.C.)",
        "employee_code",
        "phone",
        "gender",
        "date_of_birth (E.C.)",
        "department",
        "national_id",
        "status",
      ],
    ]);

    const gregorian = await readWorkbook(
      await buildTemplate(SPEC, "gregorian", TEXT),
    );
    expect(gregorian[0][1]).toBe("hire_date (G.C.)");
  });

  it("writes rows already known under their columns and leaves the rest blank", async () => {
    const data = await buildTemplate(SPEC, "gregorian", TEXT, [
      { employee_code: "7777" },
      { employee_code: "8888", name: "Hana" },
    ]);
    const sheet = (await readWorkbook(data)) as string[][];
    const code = SPEC.headers.indexOf("employee_code");

    expect(sheet).toHaveLength(3);
    expect(sheet[1][code]).toBe("7777");
    expect(sheet[1][0] ?? "").toBe("");
    expect(sheet[2][0]).toBe("Hana");
    expect(sheet[2][code]).toBe("8888");
  });

  it("formats as text the columns a spreadsheet would mangle, dates included", async () => {
    const sheet = (await template("gregorian")).getWorksheet(SHEET_PEOPLE)!;
    const format = (header: string) =>
      sheet.getColumn(SPEC.headers.indexOf(header) + 1).numFmt;

    for (const header of [
      "employee_code",
      "phone",
      "national_id",
      "hire_date",
      "date_of_birth",
    ]) {
      expect(format(header), header).toBe("@");
    }
    expect(format("name")).toBeUndefined();
  });

  it("puts a dropdown on each choice column, from a hidden sheet, that warns and does not block", async () => {
    const workbook = await template("gregorian");
    const sheet = workbook.getWorksheet(SHEET_PEOPLE)!;
    const lists = workbook.getWorksheet(SHEET_LISTS)!;

    expect(lists.state).toBe("veryHidden");
    expect(lists.getColumn(1).values.slice(1)).toEqual([
      "gender",
      "male",
      "female",
    ]);
    // A value with a comma in it would break a list typed into the rule.
    expect(lists.getColumn(2).values.slice(1)).toEqual([
      "department",
      "Finance",
      "Sales, North",
      "SAL-S",
    ]);

    const gender = sheet.getCell(2, SPEC.headers.indexOf("gender") + 1);
    expect(gender.dataValidation).toMatchObject({
      type: "list",
      allowBlank: true,
      errorStyle: "warning",
      formulae: [`${SHEET_LISTS}!$A$2:$A$3`],
    });
    const last = sheet.getCell(2001, SPEC.headers.indexOf("status") + 1);
    expect(last.dataValidation?.formulae).toEqual([`${SHEET_LISTS}!$C$2:$C$4`]);
    expect(
      sheet.getCell(2, SPEC.headers.indexOf("name") + 1).dataValidation,
    ).toBeUndefined();
  });

  it("explains every column on its own sheet, and marks the required one", async () => {
    const help = (await template("gregorian")).getWorksheet(SHEET_HELP)!;
    const rows: string[][] = [];
    help.eachRow((row) => {
      rows.push([
        String(row.getCell(1).value ?? ""),
        String(row.getCell(2).value ?? ""),
      ]);
    });

    expect(rows[0][0]).toBe("ETHR employee template");
    expect(rows).toContainEqual(["name (required)", "Full name"]);
    expect(rows).toContainEqual(["phone", "09... or +251..."]);
    expect(rows.map((r) => r[0])).toEqual(
      expect.arrayContaining(SPEC.headers.filter((h) => h !== "name")),
    );
  });

  describe("with the server's columns", () => {
    async function grouped(calendar: "ethiopian" | "gregorian") {
      const workbook = new Workbook();
      await workbook.xlsx.load(await buildTemplate(GROUPED, calendar, TEXT));
      return workbook;
    }
    const col = (header: string) => SPEC.headers.indexOf(header) + 1;

    it("bands each group's header in its own colour and keeps the header text", async () => {
      const sheet = (await grouped("ethiopian")).getWorksheet(SHEET_PEOPLE)!;
      const fill = (header: string) =>
        (sheet.getCell(1, col(header)).fill as { fgColor?: { argb?: string } })
          .fgColor?.argb;

      expect(fill("name")).toBeTruthy();
      expect(fill("name")).toBe(fill("employee_code"));
      expect(fill("name")).not.toBe(fill("phone"));
      expect(fill("phone")).not.toBe(fill("hire_date"));
      expect(sheet.getCell(1, col("hire_date")).value).toBe("hire_date (E.C.)");
      expect(sheet.getCell(1, col("name")).value).toBe("name");
    });

    it("puts the column's help in a note, and says which is required", async () => {
      const sheet = (await grouped("gregorian")).getWorksheet(SHEET_PEOPLE)!;
      const note = (header: string) => {
        const n = sheet.getCell(1, col(header)).note;
        return typeof n === "string"
          ? n
          : (n?.texts ?? []).map((part) => part.text).join("");
      };

      expect(note("name")).toBe("Full name (required)");
      expect(note("phone")).toBe("09... or +251...");
      expect(note("status")).toBe("");
    });

    it("freezes the first column and the header row", async () => {
      const sheet = (await grouped("gregorian")).getWorksheet(SHEET_PEOPLE)!;
      expect(sheet.views[0]).toMatchObject({
        state: "frozen",
        xSplit: 1,
        ySplit: 1,
      });
    });

    it("has an Example sheet between Employees and Instructions", async () => {
      const workbook = await grouped("gregorian");
      expect(workbook.worksheets.map((s) => s.name)).toEqual([
        SHEET_PEOPLE,
        SHEET_EXAMPLE,
        SHEET_HELP,
        SHEET_LISTS,
      ]);
    });

    it("fills the example with two people, valid for the organisation's lists", async () => {
      const sheet = (await grouped("gregorian")).getWorksheet(SHEET_EXAMPLE)!;
      const cell = (row: number, header: string) =>
        sheet.getCell(row, col(header)).value;

      expect(cell(1, "name")).toBe("name");
      expect(cell(2, "name")).toBe("Abebe Kebede");
      expect(cell(3, "name")).toBe("Hanna Girma");
      // In the list: kept. Not in it: the list's first value.
      expect(cell(2, "gender")).toBe("male");
      expect(cell(3, "gender")).toBe("male");
      expect(cell(2, "department")).toBe("Finance");
      expect(cell(2, "status")).toBe("probation");
      expect(cell(3, "status")).toBe("hired");
      expect(sheet.getCell(2, 1).font?.italic).toBe(true);
      expect(sheet.getCell(1, col("hire_date")).value).toBe("hire_date (G.C.)");
      expect(sheet.getColumn(col("hire_date")).numFmt).toBe("@");
      // Two people, one blank row, then the note.
      expect(sheet.getCell(4, 1).value).toBeNull();
      expect(sheet.getCell(5, 1).value).toBe(TEXT.exampleNote);
    });

    it("writes example dates in the calendar of the workbook, year first", async () => {
      const gregorian = (await grouped("gregorian")).getWorksheet(
        SHEET_EXAMPLE,
      )!;
      expect(gregorian.getCell(2, col("hire_date")).value).toBe("2019-07-08");

      const ethiopian = (await grouped("ethiopian")).getWorksheet(
        SHEET_EXAMPLE,
      )!;
      const e = toEthiopian(2019, 7, 8);
      expect(e).toEqual({ year: 2011, month: 11, day: 1 });
      expect(ethiopian.getCell(2, col("hire_date")).value).toBe("2011-11-01");
      expect(ethiopian.getCell(3, col("date_of_birth")).value).toMatch(
        /^\d{4}-\d{2}-\d{2}$/,
      );
    });

    it("never reads the Example sheet", async () => {
      const data = await buildTemplate(GROUPED, "gregorian", TEXT);
      const grid = await readWorkbook(data);
      expect(grid).toHaveLength(1);

      const only = new Workbook();
      only.addWorksheet(SHEET_EXAMPLE).addRow(["Abebe"]);
      only.addWorksheet(SHEET_HELP).addRow(["How"]);
      await expect(readWorkbook(await bytes(only))).rejects.toBeInstanceOf(
        NoSheet,
      );
    });

    it("lists the instructions under a heading per group, in order", async () => {
      const help = (await grouped("gregorian")).getWorksheet(SHEET_HELP)!;
      const names: string[] = [];
      help.eachRow((row) => names.push(String(row.getCell(1).value ?? "")));
      const at = (n: string) => names.indexOf(n);

      expect(at("Core")).toBeGreaterThan(at("Column"));
      expect(at("Person")).toBeGreaterThan(at("Core"));
      expect(at("Job")).toBeGreaterThan(at("Person"));
      expect(at("name (required)")).toBe(at("Core") + 1);
      expect(at("employee_code")).toBe(at("Core") + 2);
      expect(at("department")).toBeGreaterThan(at("Job"));
      expect(at("phone")).toBeLessThan(at("Job"));
    });

    it("leaves the instructions flat, and adds no example, without columns", async () => {
      const workbook = await template("gregorian");
      expect(workbook.getWorksheet(SHEET_EXAMPLE)).toBeUndefined();
      const names: string[] = [];
      workbook
        .getWorksheet(SHEET_HELP)!
        .eachRow((row) => names.push(String(row.getCell(1).value ?? "")));
      expect(names).not.toContain("Core");
    });
  });
});
