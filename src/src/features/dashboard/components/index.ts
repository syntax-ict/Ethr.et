/**
 * Barrel for the dashboard widgets.
 *
 * `InteractiveCharts` is deliberately **not** re-exported here. It pulls in
 * recharts (~435 KB uncompressed), and because a barrel is a single module,
 * anything importing *any* symbol from this file inherited that cost —
 * `/admin` and `/billing` import only `KpiCard` and were paying for a charting
 * library they never render. Import it from `./interactive-charts` directly,
 * and prefer `next/dynamic` at the call site.
 */
export { WelcomeSection } from "./welcome-section";
export { SetupProgress } from "./setup-progress";
export { KpiCard } from "./kpi-card";
export type { KpiTone } from "./kpi-card";
export { QuickActions } from "./quick-actions";
export { PendingApprovalsPanel } from "./pending-approvals";
export { RecentActivity } from "./recent-activity";
export { AnnouncementsWidget } from "./announcements-widget";
export { CalendarWidget } from "./calendar-widget";
export { TeamOverview } from "./team-overview";
export { LeaveOverview } from "./leave-overview";
