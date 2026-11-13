"use client";

import { useT } from "@/lib/i18n/useT";
import type { TemplateText } from "@/features/onboarding/v2/xlsx-grid";

/** The template's columns, in its order. Only the name is required. */
const COLUMNS: Array<{ key: string; required?: boolean }> = [
  { key: "name", required: true },
  { key: "hire_date" },
  { key: "employee_code" },
  { key: "name_am" },
  { key: "email" },
  { key: "phone" },
  { key: "gender" },
  { key: "date_of_birth" },
  { key: "department" },
  { key: "branch" },
  { key: "position" },
  { key: "salary" },
  { key: "national_id" },
  { key: "tin" },
  { key: "badge_number" },
  { key: "nationality_status" },
  { key: "marital_status" },
  { key: "status" },
  { key: "external_id" },
];

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
    columnHeading: t("setup2.template_column", "Column"),
    takesHeading: t("setup2.template_takes", "What it takes"),
    required: t("employees_import_page.required", "required"),
    help: importColumnHelp(t),
    notInList: t(
      "setup2.template_not_in_list",
      "This is not one of the listed values. It is kept, and checked when the list is read.",
    ),
  };
}

/**
 * What each column takes, in the words someone filling a spreadsheet uses.
 * The page's guide and the workbook's instructions sheet say the same thing.
 */
export function importColumnHelp(
  t: (key: string, fallback?: string) => string,
): Record<string, string> {
  return {
    name: t("employees_import_page.col_name", "Full name"),
    hire_date: t(
      "employees_import_page.col_hire_date",
      "2024-03-15 or 15/03/2024, day first, in the calendar chosen. Left empty, the day the list is added is used",
    ),
    employee_code: t(
      "employees_import_page.col_employee_code",
      "Leave blank for the next number",
    ),
    name_am: t("employees_import_page.col_name_am", "Name in Amharic"),
    email: t(
      "employees_import_page.col_email",
      "Needed for a login invitation",
    ),
    phone: t("employees_import_page.col_phone", "09... or +251..."),
    gender: t("employees_import_page.col_gender", "male or female, M or F"),
    date_of_birth: t(
      "employees_import_page.col_date_of_birth",
      "Same formats as the hire date",
    ),
    department: t(
      "employees_import_page.col_department",
      "Its name or its code in ETHR",
    ),
    branch: t("employees_import_page.col_branch", "Its name or its code"),
    position: t("employees_import_page.col_position", "Its title or its code"),
    salary: t(
      "employees_import_page.col_salary",
      "Monthly, in birr, such as 12,500.00",
    ),
    national_id: t(
      "employees_import_page.col_national_id",
      "Fayda number, stored encrypted",
    ),
    tin: t("employees_import_page.col_tin", "Taxpayer identification number"),
    badge_number: t(
      "employees_import_page.col_badge_number",
      "The number used on attendance devices",
    ),
    nationality_status: t("employees_import_page.nationality_status_values"),
    marital_status: t(
      "employees_import_page.col_marital_status",
      "single, married, divorced or widowed",
    ),
    status: t(
      "employees_import_page.col_status",
      "hired, probation or confirmed, for someone new. Blank means hired",
    ),
    external_id: t(
      "employees_import_page.col_external_id",
      "The person's ID in the system the list came from. A later list finds the same person by it",
    ),
  };
}

export function ImportColumnGuide() {
  const { t } = useT();
  const help = importColumnHelp(t);

  return (
    <div className="rounded-lg border bg-muted/30 p-3">
      <p className="text-xs font-semibold text-foreground">
        {t("employees_import_page.required_columns")}
      </p>
      <p className="mt-1 text-xs text-muted-foreground">
        {t(
          "employees_import_page.loose_headers_hint",
          "Headers such as Full Name, Start Date or Department are recognised, and the columns may be in any order.",
        )}
      </p>
      <dl className="mt-2 space-y-1.5 text-xs">
        {COLUMNS.map((column) => (
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
