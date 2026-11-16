"use client";

import { useT } from "@/lib/i18n/useT";
import type { TemplateText } from "@/features/onboarding/v2/xlsx-grid";
import { useEmployeeImportTemplate } from "@/features/employees/api";
import {
  FALLBACK_TEMPLATE_KEYS,
  columnHelpByTemplateKey,
} from "@/features/employees/employee-columns";

/**
 * The words the employee workbook carries: its instructions sheet and the
 * warning on a value outside a column's list. One place, so every screen
 * that hands out the template hands out the same file.
 */
export function employeeTemplateText(
  t: (key: string, fallback?: string) => string,
): TemplateText {
  return {
    title: t("setup2.template_title", "ETHR employee template"),
    lines: [
      t(
        "setup2.template_line_fill",
        "Fill in the Employees sheet, one person on each row. Only the name is required.",
      ),
      t(
        "setup2.template_line_dates",
        "Write a date year first, such as 2019-07-08, in the calendar its column name says.",
      ),
      t(
        "setup2.template_line_lists",
        "A column with a list takes one of its values. A department, branch or position can also be typed by its code.",
      ),
      t(
        "setup2.template_line_upload",
        "Save the file and choose it in ETHR. Everyone is shown to you before anyone is added.",
      ),
    ],
    columnLines: {
      sign_in: t(
        "setup2.template_line_sign_in",
        "The Sign in column says who is invited to sign in when the list is added: yes invites them, no does not, and blank follows the choice on the review screen.",
      ),
    },
    columnHeading: t("setup2.template_column", "Column"),
    takesHeading: t("setup2.template_takes", "What it takes"),
    required: t("employees_import_page.required", "required"),
    help: importColumnHelp(t),
    notInList: t(
      "setup2.template_not_in_list",
      "This is not one of the listed values. It is kept, and checked when the list is read.",
    ),
    groups: {
      core: t("setup2.template_group_core", "Name and number"),
      person: t("setup2.template_group_person", "About the person"),
      contact: t("setup2.template_group_contact", "How to reach them"),
      job: t("setup2.template_group_job", "The job"),
      pay: t("setup2.template_group_pay", "Pay"),
      source: t("setup2.template_group_source", "Other systems"),
    },
    exampleLine: t(
      "setup2.template_line_example",
      "The Example sheet shows two filled lines. Point at a column name to read what it takes.",
    ),
    exampleNote: t(
      "setup2.template_example_note",
      "This sheet is only an example. Nothing on it is read.",
    ),
    // Invented people and numbers. Dates are Gregorian here; the workbook
    // writes them in the calendar it is built for. A column with a list keeps
    // an example only if the organisation's list has it.
    example: [
      {
        name: "Abebe Kebede",
        name_am: "አበበ ከበደ",
        employee_code: "1001",
        email: "abebe.kebede@example.com",
        phone: "0911000001",
        sign_in: "yes",
        gender: "male",
        date_of_birth: "1988-03-14",
        hire_date: "2019-07-08",
        department: "Finance",
        position: "Accountant",
        salary: "15000",
        national_id: "1000000000000001",
        tin: "0000000001",
        badge_number: "9001",
        nationality: "Ethiopian",
        marital_status: "married",
        status: "confirmed",
        external_id: "EX-0001",
        grade: "Grade 5",
        cost_center: "CC-100",
        probation_end_date: "2019-10-08",
        contract_type: "permanent",
        bank_name: "Example Bank",
        bank_branch: "Main branch",
        bank_account: "1000000000001",
        emergency_name: "Almaz Kebede",
        emergency_relationship: "Sister",
        emergency_phone: "0911000011",
      },
      {
        name: "Hanna Girma",
        name_am: "ሃና ግርማ",
        employee_code: "1002",
        email: "hanna.girma@example.com",
        phone: "0911000002",
        gender: "female",
        date_of_birth: "1995-11-02",
        hire_date: "2023-02-20",
        department: "Sales",
        position: "Sales officer",
        salary: "9000",
        national_id: "1000000000000002",
        tin: "0000000002",
        badge_number: "9002",
        nationality: "Ethiopian",
        marital_status: "single",
        status: "probation",
        external_id: "EX-0002",
        grade: "Grade 3",
        cost_center: "CC-200",
        probation_end_date: "2023-05-20",
        contract_type: "fixed_term",
        contract_end_date: "2025-02-19",
        bank_name: "Example Bank",
        bank_branch: "Bole branch",
        bank_account: "1000000000002",
        emergency_name: "Dawit Girma",
        emergency_relationship: "Brother",
        emergency_phone: "0911000022",
      },
    ],
  };
}

/**
 * What each column takes, in the words someone filling a spreadsheet uses.
 * The page's guide and the workbook's instructions sheet say the same thing.
 */
export function importColumnHelp(
  t: (key: string, fallback?: string) => string,
): Record<string, string> {
  return columnHelpByTemplateKey(t);
}

export function ImportColumnGuide() {
  const { t } = useT();
  const help = importColumnHelp(t);
  // The columns the server names, in its order, so one added there appears
  // here unchanged. The browser's own list stands in while that loads.
  const template = useEmployeeImportTemplate();
  const columns: Array<{ key: string; required: boolean }> = template.data
    ?.columns
    ? template.data.columns.map((c) => ({ key: c.key, required: c.required }))
    : FALLBACK_TEMPLATE_KEYS.map((key) => ({
        key,
        required: key === "name",
      }));

  return (
    <div className="rounded-lg border bg-muted/30 p-3">
      <p className="text-xs font-semibold text-foreground">
        {t("employees_import_page.required_columns")}
      </p>
      <p className="mt-1 text-xs text-muted-foreground">
        {t(
          "employees_import_page.loose_headers_hint",
          "Headers such as Full Name, Start Date or Department are recognised, and a file exported from the employee list imports as it is.",
        )}
      </p>
      <dl className="mt-2 space-y-1.5 text-xs">
        {columns.map((column) => (
          <div key={column.key}>
            <dt className="font-mono text-foreground">
              {column.key}
              {column.required && (
                <span className="ml-1 font-sans text-destructive">
                  ({t("employees_import_page.required")})
                </span>
              )}
            </dt>
            <dd className="text-muted-foreground">{help[column.key]}</dd>
          </div>
        ))}
      </dl>
    </div>
  );
}
