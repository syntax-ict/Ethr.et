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
