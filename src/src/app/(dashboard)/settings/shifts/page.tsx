import { RouteRedirect } from "@/components/shared/route-redirect";

/**
 * Shift Rules was a second, older screen for the same shifts as
 * Shifts & Schedules, with fewer actions (no edit, duplicate or usage).
 * There is one shift route now (FRONTEND_REDESIGN_PLAN.md, Phase 4); this
 * address is kept because the onboarding readiness check links to it.
 */
export default function SettingsShiftsMoved() {
  return <RouteRedirect to="/shifts" />;
}
