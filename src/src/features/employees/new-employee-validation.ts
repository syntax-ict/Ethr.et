/**
 * The rules of `StoreEmployeeRequest` that a person can trip over while
 * typing, checked in the browser so the message appears at the field before a
 * round trip. The server stays the authority: each check here is no stricter
 * than its rule, so nothing the API accepts is refused by the form. The rules
 * that need the database (unique code and badge, a relation that exists in
 * this organisation) and the ones with no way to be mistyped are left to the
 * API's 422, which the page already maps onto fields.
 */

type Translate = (
  key: string,
  fallback?: string,
  params?: Record<string, string | number>,
) => string;

export interface ValidatedEmployeeFields {
  name: string;
  name_am: string;
  email: string;
  employee_code: string;
  badge_number: string;
  date_of_birth: string;
  nationality: string;
  national_id: string;
  tin: string;
  hire_date: string;
  probation_end_date: string;
}

const ISO_DAY = /^\d{4}-\d{2}-\d{2}$/;

/** Laravel counts characters, not UTF-16 units: an emoji is one. */
function length(value: string): number {
  return [...value].length;
}

function isoDay(date: Date): string {
  return date.toISOString().slice(0, 10);
}

/** Same-day-next-year-style arithmetic as Carbon: 29 Feb overflows to 1 Mar. */
function addYears(day: string, years: number): string {
  const [y, m, d] = day.split("-").map(Number) as [number, number, number];
  return isoDay(new Date(Date.UTC(y + years, m - 1, d)));
}

/**
 * Today as the server may read it. The API's clock is UTC and the browser's
 * is local, so the two can differ by a day; the later of them is used for
 * every "not in the future" bound, so a date the server would accept is never
 * refused here.
 */
function latestToday(now: Date): string {
  const local = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-${String(now.getDate()).padStart(2, "0")}`;
  const utc = isoDay(now);
  return local > utc ? local : utc;
}

const MAX_LENGTHS: Partial<Record<keyof ValidatedEmployeeFields, number>> = {
  name: 255,
  name_am: 255,
  email: 255,
  employee_code: 30,
  badge_number: 32,
  nationality: 100,
  national_id: 50,
  tin: 20,
};

/** The message for one field, or undefined when it is fine to send. */
export function validateEmployeeField(
  field: keyof ValidatedEmployeeFields,
  form: ValidatedEmployeeFields,
  t: Translate,
  now: Date = new Date(),
): string | undefined {
  const value = form[field].trim();
  const max = MAX_LENGTHS[field];
  if (max !== undefined && length(value) > max) {
    return t("validation.too_long", "This value is too long");
  }
  if (value === "") return undefined;

  switch (field) {
    case "email":
      // `email` in Laravel is RFC based and accepts more than a tidy
      // address; this only catches a missing part either side of the @.
      return /^.+@.+$/.test(value)
        ? undefined
        : t("validation.email", "Enter a valid email address");

    case "badge_number":
      return /^[A-Za-z0-9-]+$/.test(value)
        ? undefined
        : t("validation.badge_format", "Use letters, numbers and hyphens only");

    case "date_of_birth": {
      if (!ISO_DAY.test(value)) return undefined;
      // `before:today` — a birthday today is the server's call, so only a
      // day after both clocks' today is refused here.
      if (value > latestToday(now)) {
        return t(
          "validation.dob_future",
          "The date of birth must be in the past",
        );
      }
      const hire = form.hire_date.trim();
      if (ISO_DAY.test(hire) && addYears(value, 15) > hire) {
        return t(
          "validation.dob_minimum_age",
          "A person must be at least 15 years old on their hire date",
        );
      }
      return undefined;
    }

    case "hire_date":
      if (ISO_DAY.test(value) && value > addYears(latestToday(now), 1)) {
        return t(
          "validation.hire_date_too_far",
          "The hire date cannot be more than a year ahead",
        );
      }
      return undefined;

    case "probation_end_date": {
      const hire = form.hire_date.trim();
      if (ISO_DAY.test(value) && ISO_DAY.test(hire) && value < hire) {
        return t(
          "validation.date_range",
          "The end date cannot fall before the start date",
        );
      }
      return undefined;
    }

    default:
      return undefined;
  }
}

/** Every message for the form as it stands, keyed by the API's field names. */
export function validateEmployeeForm(
  form: ValidatedEmployeeFields,
  t: Translate,
  now: Date = new Date(),
): Partial<Record<keyof ValidatedEmployeeFields, string>> {
  const found: Partial<Record<keyof ValidatedEmployeeFields, string>> = {};
  for (const field of Object.keys(form) as (keyof ValidatedEmployeeFields)[]) {
    const message = validateEmployeeField(field, form, t, now);
    if (message) found[field] = message;
  }
  return found;
}

/** The fields whose message depends on another one, so a change re-checks both. */
export const DEPENDENT_FIELDS: Partial<
  Record<keyof ValidatedEmployeeFields, (keyof ValidatedEmployeeFields)[]>
> = {
  hire_date: ["date_of_birth", "probation_end_date"],
  date_of_birth: [],
  probation_end_date: [],
};
