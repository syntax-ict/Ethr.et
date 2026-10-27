import {
  Briefcase,
  CalendarClock,
  CalendarDays,
  CheckSquare,
  Clock,
  Fingerprint,
  Globe,
  Landmark,
  type LucideIcon,
  MapPin,
  Network,
  Phone,
  PhoneCall,
  Receipt,
  Rocket,
  ShieldAlert,
  ShieldCheck,
  Smartphone,
  UserPlus,
  UserRound,
  Users,
  UserX,
  Wallet,
} from "lucide-react";
import type { NextStepId } from "./api";

/**
 * What the client knows about each step the server can return: its icon, and
 * which sentences the copy has. The words live in the locale files under
 * `next_steps.step.<id>.*`; they are read through a computed key, so the i18n
 * gate cannot see them and `next-steps-registry.test.ts` is what catches a
 * missing one.
 *
 * Keyed by the generated `NextStepId`, so a step the API gains without an
 * entry here fails the type check rather than rendering as a blank row.
 */
export interface StepDef {
  icon: LucideIcon;
  /** The title has a `_one` form, used when the count is 1. */
  plural?: boolean;
}

export const STEP_DEFS: Record<NextStepId, StepDef> = {
  // Blockers.
  attendance_blockers: { icon: ShieldAlert, plural: true },
  payroll_failed: { icon: Wallet },
  setup_admin_present: { icon: ShieldCheck },
  setup_admin_active: { icon: ShieldCheck },

  // Work waiting for a decision, and setup that is unfinished.
  integrity: { icon: ShieldAlert, plural: true },
  payroll_approve: { icon: Wallet },
  approvals: { icon: CheckSquare, plural: true },
  contracts_ending: { icon: CalendarClock, plural: true },
  setup_wizard: { icon: Rocket },
  setup_branch: { icon: MapPin },
  setup_departments: { icon: Network },
  setup_positions: { icon: Briefcase },
  setup_shifts: { icon: Clock },
  setup_leave_types: { icon: CalendarDays },
  setup_holidays: { icon: CalendarDays },
  setup_employees: { icon: UserPlus },
  setup_employee_departments: { icon: Users },
  setup_attendance_source: { icon: Smartphone },
  records_missing_tin: { icon: Receipt, plural: true },
  records_missing_bank: { icon: Landmark, plural: true },
  nationality_review: { icon: Globe, plural: true },

  // Worth doing, and nobody is waiting.
  complete_profile: { icon: UserRound },
  records_missing_emergency_contact: { icon: PhoneCall, plural: true },
  records_missing_phone: { icon: Phone, plural: true },
  unmatched_badges: { icon: Fingerprint, plural: true },
  team_absent: { icon: UserX, plural: true },
};

export type StepCopyPart = "title" | "title_one" | "reason" | "cta";

/** The locale key of one sentence of a step. */
export function stepKey(id: NextStepId, part: StepCopyPart): string {
  return `next_steps.step.${id}.${part}`;
}

/** The profile fields a `complete_profile` step can list, in the order the server names them. */
export const PROFILE_ITEMS = [
  "photo",
  "phone",
  "date_of_birth",
  "emergency_contact",
  "tin",
  "bank",
] as const;

export type ProfileItem = (typeof PROFILE_ITEMS)[number];

/** Where each missing profile field is filled in. The photo has its own page; the rest are on the details page. */
export const PROFILE_ITEM_HREF: Record<ProfileItem, string> = {
  photo: "/profile",
  phone: "/profile/personal",
  date_of_birth: "/profile/personal",
  emergency_contact: "/profile/personal",
  tin: "/profile/personal",
  bank: "/profile/personal",
};

export function isProfileItem(value: string): value is ProfileItem {
  return (PROFILE_ITEMS as readonly string[]).includes(value);
}
