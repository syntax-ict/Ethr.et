import type { Employee } from "./types";

/** `EmployeeStatus::current()`: the people who work here now. */
export const CURRENT_STATUSES: readonly string[] = [
  "hired",
  "probation",
  "confirmed",
];

/** What the sign-in dialog and badge need to know about a row. */
export type SignInPerson = Pick<
  Employee,
  "public_id" | "email" | "phone" | "sign_in" | "status"
>;

export function isCurrentStaff(status: string | null | undefined): boolean {
  return status != null && CURRENT_STATUSES.includes(status);
}

/**
 * Someone worth giving a way to sign in: current staff with no login, or one
 * invited and never activated. The server would also make a login for a
 * leaver, so the choice is made here.
 */
export function needsSignIn(person: SignInPerson): boolean {
  return (
    isCurrentStaff(person.status) &&
    (person.sign_in === "none" || person.sign_in === "invited")
  );
}
