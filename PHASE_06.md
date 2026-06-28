# Phase 6 — Executive Dashboard & Reporting

## Prerequisites
- Phase 3 complete (attendance data)
- Phase 4 complete (payroll data)
- Phase 5 complete (portals, notifications)

## Objective
Build executive analytics dashboards and a dynamic reporting engine.

---

## S26 — Executive Dashboard

### Backend
- [ ] `DashboardController` — executive endpoints:
  - `GET /api/v1/dashboard/executive` — composite overview:
    - Organization health score (composite of attendance rate, turnover, payroll cost trend)
    - Headcount: total, by status, by department, by branch
    - Attendance rate: today, this week, this month (% present)
    - Absenteeism rate: this month, trend (3-month)
    - Payroll cost: this month, previous month, YoY change
    - Overtime cost: this month, % of total payroll
    - Turnover rate: this month, this quarter (exits / avg headcount)
    - Workforce growth: hires vs exits trend (6-month chart data)
    - Leave utilization: % of entitled leave used, by type
  - `GET /api/v1/dashboard/executive/attendance` — attendance deep-dive:
    - Daily attendance trend (30 days)
    - Attendance by department (comparison)
    - Attendance by branch (comparison)
    - Top late employees (this month)
    - Attendance source breakdown (biometric, mobile, web, etc.)
  - `GET /api/v1/dashboard/executive/payroll` — payroll deep-dive:
    - Monthly payroll cost trend (12 months)
    - Payroll by department
    - Payroll by cost center
    - Average salary by department/grade
    - Tax and pension totals
  - `GET /api/v1/dashboard/executive/workforce` — workforce deep-dive:
    - Headcount trend (12 months)
    - Hires and exits by month
    - Department headcount comparison
    - Position distribution
    - Grade distribution
    - Gender distribution
    - Tenure distribution (buckets: <1yr, 1-3, 3-5, 5-10, 10+)
- [ ] Caching: dashboard data cached in Redis (5-minute TTL, invalidated on new attendance/payroll)
- [ ] Date range parameter: `?from=2026-01-01&to=2026-06-30`
- [ ] Comparison parameter: `?compare=previous_period` (vs same period last year)
- [ ] `ExecutiveDashboardPolicy`: tenant_admin + any role with `dashboard.executive` permission

### Frontend
- [ ] Executive dashboard page:
  - KPI cards row: headcount, attendance rate, payroll cost, overtime %, turnover rate
  - Each card: current value, trend indicator (up/down arrow + %), sparkline
  - Click card -> drill-down to detail section
- [ ] Attendance analytics section:
  - Line chart: daily attendance rate (30 days)
  - Bar chart: department attendance comparison
  - Bar chart: branch attendance comparison
  - Table: top 10 late employees this month
  - Pie chart: attendance source breakdown
- [ ] Payroll analytics section:
  - Area chart: monthly payroll cost trend (12 months)
  - Horizontal bar: department payroll comparison
  - Stacked bar: earnings vs deductions breakdown
  - Table: average salary by grade
- [ ] Workforce analytics section:
  - Line chart: headcount trend (12 months)
  - Grouped bar: hires vs exits by month
  - Donut charts: gender, tenure distribution
  - Tree map or bar: department headcount
- [ ] Filters:
  - Date range picker (with presets: this month, last month, this quarter, this year, custom)
  - Branch selector
  - Department selector
  - Compare toggle (vs previous period)
- [ ] Charts: use Recharts or Chart.js (lightweight, no paid license)
- [ ] Responsive: cards stack on mobile, charts full-width
- [ ] Print / PDF export of dashboard view
- [ ] Dark mode for all charts

### Tests
- [ ] Dashboard endpoint with test data (Pest)
- [ ] Caching behavior (Pest)
- [ ] Authorization (non-executive denied) (Pest)
- [ ] Date range filtering (Pest)
- [ ] KPI cards rendering (Vitest)
- [ ] Chart components (Vitest)

### Exit Criteria
- Executive dashboard loads in < 1.5 seconds
- All KPIs calculated correctly from real data
- Charts interactive (hover for details)
- Drill-down from summary to detail
- Date range and department/branch filtering
- Dark mode for all visualizations

---

## S27 — Department & Branch Analytics

### Backend
- [ ] Department comparison endpoint: `GET /api/v1/analytics/departments`
  - Per department: headcount, attendance rate, payroll cost, avg salary, OT hours, turnover
  - Sortable by any metric
  - Trend data (3-month for each metric)
- [ ] Branch comparison endpoint: `GET /api/v1/analytics/branches`
  - Per branch: headcount, attendance rate, device status, payroll cost, department count
  - Geographic view data (if lat/long available)
- [ ] Department detail: `GET /api/v1/analytics/departments/{id}`
  - Department-specific KPIs
  - Sub-department breakdown (if hierarchical)
  - Employee list with performance indicators
  - Attendance pattern analysis
- [ ] Branch detail: `GET /api/v1/analytics/branches/{id}`
  - Branch-specific KPIs
  - Device health summary
  - Department breakdown within branch
- [ ] Export endpoints: CSV/Excel for all analytics views

### Frontend
- [ ] Department analytics page:
  - Comparison table: sortable columns (headcount, attendance %, payroll, OT, turnover)
  - Highlight best/worst performing (green/red)
  - Click department -> detail page
  - Radar chart: department comparison on multiple metrics
- [ ] Branch analytics page:
  - Branch cards or table with key metrics
  - Map view (if coordinates available): pins on Ethiopian map
  - Click branch -> detail page
- [ ] Detail pages:
  - Focused KPIs for selected department/branch
  - Charts specific to that entity
  - Employee drill-down table
- [ ] Export button on each analytics page

### Tests
- [ ] Department comparison endpoint (Pest)
- [ ] Branch comparison endpoint (Pest)
- [ ] Sorting and filtering (Pest)
- [ ] Comparison table (Vitest)
- [ ] Detail page (Vitest)

### Exit Criteria
- Departments and branches comparable on key metrics
- Drill-down from comparison to detail
- Export to CSV/Excel
- Visual indicators for best/worst performing

---

## S28 — Report Builder & Scheduled Reports

### Backend
- [ ] Report builder engine:
  - Available data sources: employees, attendance, leave, payroll
  - Available fields per source (configurable columns)
  - Filters: date range, department, branch, employee status, etc.
  - Aggregations: count, sum, average, min, max
  - Group by: department, branch, month, employee
  - Sort by any field
- [ ] `ReportController`:
  - `GET /api/v1/reports/sources` — available data sources and fields
  - `POST /api/v1/reports/generate` — run report with config, return data
  - `POST /api/v1/reports/export` — export report as PDF/Excel/CSV
  - `POST /api/v1/reports/save` — save report config as template
  - `GET /api/v1/reports/saved` — list saved report templates
  - `DELETE /api/v1/reports/saved/{id}` — delete saved template
  - `POST /api/v1/reports/schedule` — schedule recurring report delivery
  - `GET /api/v1/reports/scheduled` — list scheduled reports
  - `DELETE /api/v1/reports/scheduled/{id}` — cancel scheduled report
- [ ] `saved_reports` table: tenant_id, name, config (JSON), created_by
- [ ] `scheduled_reports` table: tenant_id, saved_report_id, frequency (daily/weekly/monthly), recipients (email list), last_run, next_run
- [ ] `GenerateScheduledReportJob`: runs on schedule, generates report, emails to recipients
- [ ] Export formats:
  - PDF: formatted table layout with headers and totals (DomPDF)
  - Excel: raw data with formatting (Maatwebsite/Laravel-Excel or PhpSpreadsheet)
  - CSV: raw data
- [ ] Pre-built report templates:
  - Monthly attendance summary
  - Monthly payroll register
  - Leave balance report
  - Employee directory
  - Overtime summary
  - Tax declaration report
  - Pension contribution report
  - New hires / exits report
- [ ] `ReportPolicy`: hr_admin+ can build reports; finance_admin for payroll reports

### Frontend
- [ ] Report builder page:
  - Step 1: Select data source (employees, attendance, leave, payroll)
  - Step 2: Select columns (checkbox list, drag to reorder)
  - Step 3: Add filters (dynamic filter rows: field + operator + value)
  - Step 4: Group by (optional) + aggregation
  - Step 5: Preview (table with pagination)
  - "Export" button (PDF/Excel/CSV selector)
  - "Save as Template" button (name input)
- [ ] Saved reports page:
  - List of saved templates
  - Run / Edit / Delete / Schedule actions
  - Schedule dialog: frequency selector, recipient email list
- [ ] Pre-built reports section:
  - Grid of pre-built report cards
  - Click to run with date range input
  - Direct export
- [ ] Report preview:
  - Interactive data table
  - Total/summary row
  - Chart view toggle (if aggregated data)
- [ ] Scheduled reports list:
  - Active schedules with next run date
  - Recipient list
  - Pause / Resume / Delete

### Tests
- [ ] Report generation from each data source (Pest)
- [ ] Filtering and aggregation (Pest)
- [ ] PDF/Excel/CSV export (Pest)
- [ ] Save and load report template (Pest)
- [ ] Scheduled report execution (Pest)
- [ ] Report builder steps (Vitest)
- [ ] Preview table (Vitest)

### Exit Criteria
- Dynamic report builder works with all data sources
- Filters, grouping, and aggregation functional
- Export to PDF, Excel, CSV
- Saved templates reusable
- Scheduled reports deliver via email
- Pre-built reports available for common needs

---

## Phase 6 Exit Criteria

- [ ] Executive dashboard with all KPIs and charts
- [ ] Department and branch comparison analytics
- [ ] Drill-down from summary to detail
- [ ] Dynamic report builder with 4 data sources
- [ ] Export to PDF, Excel, CSV
- [ ] Scheduled report delivery
- [ ] Pre-built report templates
- [ ] All analytics cached for performance (< 1.5s load)
- [ ] Dark mode for all charts
- [ ] All Pest + Vitest tests passing
