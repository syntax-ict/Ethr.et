# Phase 6 — Executive Dashboard & Reporting (v2.0)

## Prerequisites
- Phase 3 complete (attendance data)
- Phase 4 complete (payroll data)
- Phase 5 complete (portals, notifications)

## Objective
Build executive analytics dashboards with cached data, department/branch comparison, and a dynamic reporting engine with scheduled delivery.

---

## S27 — Executive Dashboard

### Backend
- [ ] `DashboardController` — executive endpoints (requires `dashboard.executive` permission):
  - `GET /api/v1/dashboard/executive` — composite overview:
    - Organization health score (weighted composite: attendance rate 40% + turnover inverse 30% + payroll cost trend 30%)
    - Headcount: total, by status, by department, by branch
    - Attendance rate: today, this week, this month (% present)
    - Absenteeism rate: this month, 3-month trend
    - Payroll cost: this month (cents), previous month, YoY change %
    - Overtime cost: this month, as % of total payroll
    - Turnover rate: this month, this quarter (exits / avg headcount × 100)
    - Workforce growth: hires vs exits 6-month trend data
    - Leave utilization: % of entitled leave used across all employees, by type
  - `GET /api/v1/dashboard/executive/attendance` — attendance deep-dive:
    - Daily attendance rate trend (30 days, line chart data)
    - Attendance rate by department (bar chart data)
    - Attendance rate by branch (bar chart data)
    - Top 10 late employees this month (name, late count, avg late minutes)
    - Attendance source breakdown (pie chart: biometric, mobile, web, QR, etc.)
  - `GET /api/v1/dashboard/executive/payroll` — payroll deep-dive:
    - Monthly payroll cost trend (12 months, area chart data)
    - Payroll cost by department (horizontal bar data)
    - Payroll cost by cost center
    - Average salary by department (bar chart data)
    - Average salary by grade
    - Tax and pension totals (current month + trend)
  - `GET /api/v1/dashboard/executive/workforce` — workforce deep-dive:
    - Headcount trend (12 months, line chart data)
    - Hires and exits by month (grouped bar data)
    - Department headcount comparison
    - Position distribution
    - Grade distribution
    - Gender distribution (donut chart data)
    - Tenure distribution buckets: <1yr, 1-3, 3-5, 5-10, 10+ (bar chart data)
- [ ] Caching: all dashboard data cached in Redis
  - Key: `dashboard:{tenant}:{endpoint}:{date_hash}` (5-min TTL)
  - Invalidated on: new attendance record, payroll approval, employee status change
  - Background refresh on cache miss (serve stale if available)
- [ ] Date range parameter: `?from=2026-01-01&to=2026-06-30` (both Gregorian and Ethiopian accepted if dual calendar enabled)
- [ ] Comparison parameter: `?compare=previous_period` (calculates delta % vs same period last year)
- [ ] Department/branch filter: `?department_id=xxx&branch_id=yyy`

### Frontend
- [ ] Executive dashboard page:
  - KPI cards row (`StatCard`): headcount, attendance rate %, payroll cost (ETB), overtime %, turnover rate
  - Each card: current value, trend indicator (↑/↓ arrow + % change, green/red), sparkline (7-day)
  - Click card → scroll to detail section below
  - Organization health score: `ProgressRing` with score 0-100
- [ ] Attendance analytics section:
  - Line chart: daily attendance rate (30 days) — `ChartWrapper` with semantic colors
  - Horizontal bar chart: department attendance comparison (sorted best to worst)
  - Horizontal bar chart: branch attendance comparison
  - DataTable: top 10 late employees (name, late count, avg late minutes, department)
  - Donut chart: attendance source breakdown
- [ ] Payroll analytics section:
  - Area chart: monthly payroll cost trend (12 months) — ETB formatted axis
  - Horizontal bar: department payroll comparison
  - Stacked bar: earnings vs deductions breakdown (basic, OT, allowances | tax, pension, other)
  - DataTable: average salary by grade (grade, avg basic, avg gross, avg net — `CurrencyDisplay`)
- [ ] Workforce analytics section:
  - Line chart: headcount trend (12 months)
  - Grouped bar: hires vs exits by month
  - Donut charts: gender distribution, employment status distribution
  - Horizontal bar: tenure distribution buckets
  - Treemap or bar: department headcount
- [ ] Filters (sticky header):
  - Date range picker (presets: this month, last month, this quarter, this year, custom)
  - `DualCalendarPicker` for custom range
  - Branch selector (multi-select)
  - Department selector (multi-select with hierarchy)
  - Compare toggle: "vs Previous Period" (shows delta on all metrics)
- [ ] All charts: `ChartWrapper` component ensuring:
  - Semantic color tokens (not raw colors)
  - Dark mode compatible
  - Responsive (full-width on mobile, side-by-side on desktop)
  - ETB currency formatting on axes where applicable
  - Hover tooltips with formatted values
  - Chart.js or Recharts (no paid library)
- [ ] Print / PDF export: "Export Dashboard" button → browser print with print-optimized CSS
- [ ] Loading: skeleton shimmer for each chart/card section independently

### Tests
- [ ] Dashboard endpoint with seeded test data — verify all metrics (Pest)
- [ ] Dashboard caching — second request served from cache (Pest)
- [ ] Cache invalidation — new attendance record invalidates cache (Pest)
- [ ] Authorization — non-executive denied (Pest)
- [ ] Date range filtering — verify correct data window (Pest)
- [ ] Comparison mode — delta calculated correctly (Pest)
- [ ] Department/branch filter — scoped correctly (Pest)
- [ ] KPI StatCards render with trend (Vitest)
- [ ] ChartWrapper renders in dark mode (Vitest)
- [ ] Date range picker with presets (Vitest)
- [ ] QueryBoundary on each section independently (Vitest)

### Exit Criteria
- Executive dashboard loads in < 1.5 seconds (cached)
- All KPIs calculated correctly from real data
- Charts interactive (hover tooltips)
- Drill-down from summary to detail via card click
- Date range, department, and branch filtering
- Comparison with previous period
- Dark mode for all charts
- Print/PDF export functional

---

## S28 — Department & Branch Analytics

### Backend
- [ ] Department comparison: `GET /api/v1/analytics/departments`
  - Per department: headcount, attendance_rate_%, payroll_cost_cents, avg_salary_cents, ot_hours, turnover_rate_%
  - Sortable by any metric
  - Trend data (3-month for each metric)
  - Permission: `reports.view` or `reports.department` (scoped to own dept)
- [ ] Branch comparison: `GET /api/v1/analytics/branches`
  - Per branch: headcount, attendance_rate_%, device_count, device_online_count, payroll_cost_cents, department_count
  - Geographic data (lat/lng for map view)
- [ ] Department detail: `GET /api/v1/analytics/departments/{id}`
  - Department-specific KPIs (attendance, payroll, headcount, OT, turnover)
  - Sub-department breakdown (if hierarchical)
  - Employee list with key metrics (attendance rate, OT hours, leave days used)
  - Attendance pattern analysis (most common late time, average hours worked)
- [ ] Branch detail: `GET /api/v1/analytics/branches/{id}`
  - Branch-specific KPIs
  - Device health summary (online, offline, last sync)
  - Department breakdown within branch
- [ ] Export endpoints: `GET /api/v1/analytics/departments/export?format=csv|xlsx`
  - Same data as list endpoint, downloadable
  - Background job for large datasets

### Frontend
- [ ] Department analytics page:
  - Comparison DataTable: sortable columns (headcount, attendance %, payroll ETB, OT hours, turnover %)
  - Cell coloring: best performing = green, worst = red (within each column)
  - Click department → detail page
  - Radar chart: compare up to 5 departments on multiple metrics simultaneously
  - Export button (CSV/Excel)
- [ ] Branch analytics page:
  - Branch cards or DataTable with key metrics
  - Map view (if coordinates available): markers on map with metric popup
  - Click branch → detail page
- [ ] Department/Branch detail pages:
  - KPI `StatCard` row (department/branch-specific)
  - Charts specific to that entity (attendance trend, payroll trend)
  - Employee DataTable with drill-down to employee detail
- [ ] Export button on each analytics page

### Tests
- [ ] Department comparison endpoint — correct data (Pest)
- [ ] Branch comparison endpoint — correct data (Pest)
- [ ] Department detail — scoped correctly (Pest)
- [ ] Sorting by all metrics (Pest)
- [ ] Permission: department_head sees only own department (Pest)
- [ ] Export generation (Pest)
- [ ] Comparison DataTable with cell coloring (Vitest)
- [ ] Radar chart rendering (Vitest)
- [ ] Detail page drill-down (Vitest)

### Exit Criteria
- Departments and branches comparable on 6+ metrics
- Cell coloring highlights best/worst performers
- Drill-down from comparison to detail
- Export to CSV/Excel
- Radar chart for multi-metric comparison

---

## S29 — Report Builder & Scheduled Reports

### Backend
- [ ] Report builder engine:
  - Data sources: employees, attendance, leave, payroll
  - Available fields per source (defined as JSON schema):
    - Employees: name, code, department, branch, position, grade, status, hire_date, basic_salary
    - Attendance: employee, date, check_in, check_out, worked_hours, status, source, confidence
    - Leave: employee, type, start_date, end_date, days, status, approver
    - Payroll: employee, period, basic, overtime, allowances, gross, tax, pension, deductions, net
  - Filters: date range, department, branch, employee status, specific field conditions (equals, contains, greater than, less than)
  - Aggregations: count, sum, average, min, max
  - Group by: department, branch, month, employee, position, grade
  - Sort by any field (asc/desc)
- [ ] `ReportController`:
  - `GET /api/v1/reports/sources` — available data sources and fields per source
  - `POST /api/v1/reports/generate` — run report with config, return paginated data
  - `POST /api/v1/reports/export` — export as PDF/Excel/CSV (background job for large reports)
  - `POST /api/v1/reports/save` — save report config as template
  - `GET /api/v1/reports/saved` — list saved templates
  - `GET /api/v1/reports/saved/{id}` — get saved template config
  - `DELETE /api/v1/reports/saved/{id}`
  - `POST /api/v1/reports/schedule` — schedule recurring delivery
  - `GET /api/v1/reports/scheduled` — list scheduled reports
  - `DELETE /api/v1/reports/scheduled/{id}` — cancel
  - Permission: `reports.build` for custom reports, `reports.view` for pre-built
- [ ] `saved_reports` table: tenant_id, name, description, config (JSON), created_by, is_public (shared with team)
- [ ] `scheduled_reports` table: tenant_id, saved_report_id, frequency (daily/weekly/monthly), recipients (email JSON array), format (pdf/excel/csv), last_run_at, next_run_at, is_active
- [ ] `GenerateScheduledReportJob`: runs on schedule, generates report, emails to recipients as attachment
- [ ] Export formats:
  - PDF: formatted table layout with headers, totals, company branding (DomPDF)
  - Excel: formatted spreadsheet with column headers, auto-widths, totals row
  - CSV: raw data
- [ ] 8 pre-built report templates (saved_reports with `is_system = true`):
  - Monthly attendance summary
  - Monthly payroll register
  - Leave balance report
  - Employee directory
  - Overtime summary
  - Tax declaration report
  - Pension contribution report
  - New hires / exits report
- [ ] `ReportPolicy`:
  - `reports.build` for building custom reports
  - `reports.view` for running pre-built reports
  - Finance-module reports (payroll, tax, pension) additionally require `payroll.view`

### Frontend
- [ ] Report builder page (wizard-style):
  - Step 1: Select data source (cards: Employees, Attendance, Leave, Payroll)
  - Step 2: Select columns (checkbox list with drag to reorder, search to find columns)
  - Step 3: Add filters (dynamic filter rows: field dropdown + operator dropdown + value input, "Add Filter" button)
  - Step 4: Group by (optional dropdown) + aggregation (optional: count/sum/avg)
  - Step 5: Preview (DataTable with pagination — shows actual data)
  - "Export" button → format selector (PDF/Excel/CSV) → download or notification
  - "Save as Template" button → name + description input + public toggle
- [ ] Saved reports page:
  - DataTable: name, data source, created by, created at, actions (run/edit/delete/schedule)
  - Schedule dialog: frequency selector (daily/weekly/monthly), day-of-week/month, recipient email list, format selector
  - Pre-built reports section: card grid of 8 system templates (icon, name, description)
  - Click pre-built → opens with date range input → run
- [ ] Report preview:
  - Interactive DataTable (sortable, filterable)
  - Summary/totals row at bottom
  - Toggle chart view (if aggregated/grouped data — auto-select chart type)
  - ETB currency formatting via `CurrencyDisplay`
- [ ] Scheduled reports list:
  - DataTable: name, frequency, recipients, next run, status (active/paused), actions (pause/resume/delete)
- [ ] QueryBoundary on all sections

### Tests
- [ ] Report generation from each data source — correct data (Pest)
- [ ] Report filtering — date range, department, status (Pest)
- [ ] Report aggregation — sum, average, count (Pest)
- [ ] Report grouping — by department, by month (Pest)
- [ ] PDF export — correct formatting (Pest)
- [ ] Excel export — correct data and formatting (Pest)
- [ ] CSV export — correct delimiter and encoding (Pest)
- [ ] Save and load report template (Pest)
- [ ] Scheduled report execution — generates and emails (Pest)
- [ ] Permission: finance reports require payroll.view (Pest)
- [ ] Pre-built template — runs correctly (Pest)
- [ ] Report builder steps — navigation (Vitest)
- [ ] Preview DataTable — renders data (Vitest)
- [ ] Filter builder — add/remove filter rows (Vitest)

### Exit Criteria
- Dynamic report builder works with all 4 data sources
- Filters, grouping, and aggregation functional
- Export to PDF, Excel, CSV
- Saved templates reusable and shareable
- Scheduled reports deliver via email
- 8 pre-built reports available
- Permission-based access to reports

---

## Phase 6 Exit Criteria

- [ ] Executive dashboard with all KPIs and charts (< 1.5s load)
- [ ] Department and branch comparison analytics
- [ ] Radar chart for multi-metric comparison
- [ ] Drill-down from summary to detail
- [ ] Dynamic report builder with 4 data sources
- [ ] Export to PDF, Excel, CSV
- [ ] 8 pre-built report templates
- [ ] Scheduled report delivery via email
- [ ] All analytics cached in Redis (5-min TTL)
- [ ] Dark mode for all charts via ChartWrapper
- [ ] All Pest + Vitest tests passing
- [ ] TenantIsolationTest passes
