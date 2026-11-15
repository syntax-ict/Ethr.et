/**
 * The words for an employee column, in one place.
 *
 * The server owns which columns exist (`EmployeeImportRow`, served by the
 * template as `columns`). This file owns what each is called and what it
 * takes. Four screens kept their own lists of these words and drifted: the
 * guide, the column matcher, the migration step and the staged review. They
 * all read this map now, so a column added on the server needs one entry here
 * and nothing else. A column with no entry still shows, under its own key.
 *
 * Entries are keyed by the internal column key. The template's own spellings
 * (`department`, `branch`, `position`) and the staged `salary_cents` are the
 * same columns and resolve to the same entry.
 */

/** A dictionary key and the English that stands in until it loads. */
export type Words = readonly [key: string, fallback: string];

export interface ColumnWords {
  /** What the column is called on screen. */
  label: Words;
  /** What a spreadsheet cell under it takes. */
  help?: Words;
  /** The spelling the downloadable template uses, when it differs. */
  template?: string;
}

type Translate = (
  key: string,
  fallback?: string,
  params?: Record<string, string | number>,
) => string;

/** In the order of the server's `EmployeeImportRow::MAPPABLE`. */
export const EMPLOYEE_COLUMNS: Record<string, ColumnWords> = {
  name: {
    label: ["setup2.col_name", "Name"],
    help: ["employees_import_page.col_name", "Full name"],
  },
  name_am: {
    label: ["employee.name_am", "Name in Amharic"],
    help: ["employees_import_page.col_name_am", "Name in Amharic"],
  },
  employee_code: {
    label: ["setup2.col_employee_code", "Employee number"],
    help: [
      "employees_import_page.col_employee_code",
      "Leave blank for the next number",
    ],
  },
  email: {
    label: ["setup2.col_email", "Email"],
    help: ["employees_import_page.col_email", "Needed for a login invitation"],
  },
  phone: {
    label: ["setup2.col_phone", "Phone"],
    help: ["employees_import_page.col_phone", "09... or +251..."],
  },
  gender: {
    label: ["common.gender", "Gender"],
    help: ["employees_import_page.col_gender", "male or female, M or F"],
  },
  date_of_birth: {
    label: ["employee.detail.date_of_birth", "Date of birth"],
    help: [
      "employees_import_page.col_date_of_birth",
      "Same formats as the hire date",
    ],
  },
  hire_date: {
    label: ["setup2.col_hire_date", "Hire date"],
    help: [
      "employees_import_page.col_hire_date",
      "2024-03-15 or 15/03/2024, day first, in the calendar chosen above",
    ],
  },
  department_code: {
    label: ["setup2.col_department", "Department"],
    help: [
      "employees_import_page.col_department",
      "Its name or its code in ETHR",
    ],
    template: "department",
  },
  branch_code: {
    label: ["common.branch", "Branch"],
    help: ["employees_import_page.col_branch", "Its name or its code"],
    template: "branch",
  },
  position_code: {
    label: ["common.position", "Position"],
    help: ["employees_import_page.col_position", "Its title or its code"],
    template: "position",
  },
  salary: {
    label: ["employee.detail.salary", "Salary"],
    help: [
      "employees_import_page.col_salary",
      "Monthly, in birr, such as 12,500.00",
    ],
  },
  national_id: {
    label: ["employee.national_id", "National ID"],
    help: [
      "employees_import_page.col_national_id",
      "Fayda number, stored encrypted",
    ],
  },
  tin: {
    label: ["employee.tin", "TIN"],
    help: ["employees_import_page.col_tin", "Taxpayer identification number"],
  },
  badge_number: {
    label: ["setup2.col_badge_number", "Badge number"],
    help: [
      "employees_import_page.col_badge_number",
      "The number used on attendance devices",
    ],
  },
  nationality: {
    label: ["employee.detail.nationality", "Nationality"],
    help: [
      "employees_import_page.col_nationality",
      "Country of citizenship, such as Ethiopian",
    ],
  },
  nationality_status: {
    label: ["employee.nationality_status", "Ethiopian national?"],
    help: [
      "employees_import_page.nationality_status_values",
      "ethiopian, ethiopian_origin or foreign; blank means ethiopian, anything else is refused",
    ],
  },
  marital_status: {
    label: ["employee.detail.marital_status", "Marital status"],
    help: [
      "employees_import_page.col_marital_status",
      "single, married, divorced or widowed",
    ],
  },
  status: {
    label: ["common.status", "Status"],
    help: [
      "employees_import_page.col_status",
      "hired, probation or confirmed, for someone new. Blank means hired",
    ],
  },
  external_id: {
    label: ["setup2.col_external_id", "ID in the other system"],
    help: [
      "employees_import_page.col_external_id",
      "The person's ID in the system the list came from. A later list finds the same person by it",
    ],
  },
};

/** Other names for a column: the template's spellings, and a salary as it is staged. */
const ALIASES: Record<string, string> = {
  ...Object.fromEntries(
    Object.entries(EMPLOYEE_COLUMNS).flatMap(([key, words]) =>
      words.template ? [[words.template, key]] : [],
    ),
  ),
  salary_cents: "salary",
};

/** The columns a file's column may be, in the server's order. */
export const MAPPABLE_COLUMNS: readonly string[] =
  Object.keys(EMPLOYEE_COLUMNS);

/**
 * The template's columns as the browser knows them, for the moment before the
 * server's own list arrives (or when it cannot be fetched). Only the name is
 * required.
 */
export const FALLBACK_TEMPLATE_KEYS: readonly string[] = MAPPABLE_COLUMNS.map(
  (key) => EMPLOYEE_COLUMNS[key].template ?? key,
);

/** The words for a column, by any of its names. */
export function columnWords(key: string): ColumnWords | undefined {
  return EMPLOYEE_COLUMNS[ALIASES[key] ?? key];
}

/** What a column is called; the key itself when nobody has given it words. */
export function columnLabel(key: string, t: Translate): string {
  const words = columnWords(key);
  return words ? t(...words.label) : key;
}

/** What a column takes; undefined when nobody has said. */
export function columnHelp(key: string, t: Translate): string | undefined {
  const help = columnWords(key)?.help;
  return help ? t(...help) : undefined;
}

/** Every column's help, by the template's spelling of it. */
export function columnHelpByTemplateKey(t: Translate): Record<string, string> {
  const out: Record<string, string> = {};
  for (const key of FALLBACK_TEMPLATE_KEYS) {
    const help = columnHelp(key, t);
    if (help !== undefined) out[key] = help;
  }
  return out;
}
