import { RouteRedirect } from "@/components/shared/route-redirect";

/**
 * Attendance Rules moved into the settings hub on 2026-10-22, so the settings
 * navigation shows beside it (FRONTEND_REDESIGN_PLAN.md, Phase 4). Here, it
 * left an HR admin with no way on to the other settings pages (audit N98).
 */
export default function AttendanceSettingsMoved() {
  return <RouteRedirect to="/settings/attendance" />;
}
