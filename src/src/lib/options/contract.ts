import type { components } from "@/api/generated";

import type { EducationLevelValue } from "./education-level";
import type { EmploymentFrameworkValue } from "./employment-framework";
import type { GenderValue } from "./gender";
import type { MaritalStatusValue } from "./marital-status";
import type { NotificationTypeValue } from "./notification-type";

type Same<A, B> = [A] extends [B] ? ([B] extends [A] ? true : never) : never;

/**
 * The lists the server validates, checked against the contract: when an enum
 * in `api/app/Enums` gains or loses a value, `generated.ts` changes, and one
 * of these lines stops compiling until the list in `lib/options` follows. The
 * relationship list has no line because the server does not enforce it.
 */
export const OPTION_LISTS_MATCH_THE_CONTRACT: [true, true, true, true, true] = [
  true satisfies Same<GenderValue, components["schemas"]["Gender"]>,
  true satisfies Same<
    MaritalStatusValue,
    components["schemas"]["MaritalStatus"]
  >,
  true satisfies Same<
    EducationLevelValue,
    components["schemas"]["EducationLevel"]
  >,
  true satisfies Same<
    NotificationTypeValue,
    components["schemas"]["NotificationType"]
  >,
  true satisfies Same<
    EmploymentFrameworkValue,
    components["schemas"]["EmploymentFramework"]
  >,
];
