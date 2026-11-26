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
  /**
   * Part of a name, read only to build the name. A list may bring it, a
   * column may be matched to it, and the template does not have it.
   */
  part?: true;
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
  first_name: {
    label: ["setup2.col_first_name", "First name"],
    help: [
      "employees_import_page.col_first_name",
      "Their own name. The name is built from this column and the two after it",
    ],
    part: true,
  },
  father_name: {
    label: ["setup2.col_father_name", "Father's name"],
    help: [
      "employees_import_page.col_father_name",
      "The father's name, or a middle name. Added after the first name",
    ],
    part: true,
  },
  grandfather_name: {
    label: ["setup2.col_grandfather_name", "Grandfather's name"],
    help: [
      "employees_import_page.col_grandfather_name",
      "The grandfather's name, or a last name. Added after the father's name",
    ],
    part: true,
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
  sign_in: {
    label: ["setup2.col_sign_in", "Sign in"],
    help: [
      "employees_import_page.col_sign_in",
      "yes: invited when the list is added. no: this list does not invite them. Blank: the choice on the review screen decides. Only for people the list adds.",
    ],
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
  grade: {
    label: ["profile.grade", "Grade"],
    help: ["employees_import_page.col_grade", "Its name as it appears in ETHR"],
  },
  cost_center: {
    label: ["employee.cost_center", "Cost centre"],
    help: ["employees_import_page.col_cost_center", "Its name or its code"],
  },
  probation_end_date: {
    label: ["recruitment.probation_end", "Probation ends"],
    help: [
      "employees_import_page.col_probation_end_date",
      "Same formats as the hire date. Not before the hire date",
    ],
  },
  contract_type: {
    label: ["employee.contracts.field.type", "Contract type"],
    help: [
      "employees_import_page.col_contract_type",
      "probation, fixed_term, permanent, casual or consultancy",
    ],
  },
  contract_end_date: {
    label: [
      "employees_import_page.label_contract_end_date",
      "Contract end date",
    ],
    help: [
      "employees_import_page.col_contract_end_date",
      "Needed unless the contract is permanent. After the hire date, which is when the contract starts",
    ],
  },
  bank_name: {
    label: ["profile.bank_name", "Bank name"],
    help: [
      "employees_import_page.col_bank_name",
      "The bank. The account number is needed too",
    ],
  },
  bank_branch: {
    label: ["employees_import_page.label_bank_branch", "Bank branch"],
    help: [
      "employees_import_page.col_bank_branch",
      "The bank branch. Optional",
    ],
  },
  bank_account: {
    label: ["employees_import_page.label_bank_account", "Bank account"],
    help: [
      "employees_import_page.col_bank_account",
      "The account number, stored encrypted. The bank name is needed too",
    ],
  },
  emergency_name: {
    label: [
      "employees_import_page.label_emergency_name",
      "Emergency contact name",
    ],
    help: [
      "employees_import_page.col_emergency_name",
      "Someone to call in an emergency. Their relationship and phone are needed too",
    ],
  },
  emergency_relationship: {
    label: ["employee.emergency.relationship", "Relationship"],
    help: [
      "employees_import_page.col_emergency_relationship",
      "How they are related, such as spouse or parent",
    ],
  },
  emergency_phone: {
    label: [
      "employees_import_page.label_emergency_phone",
      "Emergency contact phone",
    ],
    help: ["employees_import_page.col_emergency_phone", "09... or +251..."],
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
 * required. The parts of a name are not in the template: it asks for `name`.
 */
export const FALLBACK_TEMPLATE_KEYS: readonly string[] =
  MAPPABLE_COLUMNS.filter((key) => !EMPLOYEE_COLUMNS[key].part).map(
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

/** A column as the template spells it: `department_code` is `department`. */
export function templateKey(key: string): string {
  return columnWords(key)?.template ?? key;
}
