# ETHR — Design System (v2.0)

## Design Philosophy

ETHR is an Ethiopian enterprise product. It must project visual authority and cultural identity while maintaining the information density that HR professionals need. It is not a consumer app and it is not a generic SaaS template.

Three principles guide every design decision:

1. **Density over decoration.** HR power users manage hundreds of employees. Every pixel of whitespace must earn its place.
2. **Ethiopian identity, not Ethiopian kitsch.** One cultural element (tibeb pattern), Ethiopian gold accent, and Amharic typography. No flags, no coffee imagery, no maps.
3. **Mobile-first for employees, desktop-first for admins.** Employees check attendance on their phones. HR managers process payroll on desktops. Design for both realities.

---

## Color System

### Brand Colors

| Token | Hex | Usage |
|---|---|---|
| Primary | `#0F4C75` | Sidebar background, primary buttons, active states |
| Primary Light | `#3282B8` | Interactive elements, links, hover states |
| Primary Dark | `#0A2E4A` | Sidebar, headers, emphasis |
| Accent | `#E8A838` | Ethiopian Gold — badges, highlights, active indicators |

### Status Colors

| Token | Light Mode | Dark Mode | Usage |
|---|---|---|---|
| Success | `#059669` | `#34D399` | Confirmed, approved, present |
| Warning | `#D97706` | `#FBBF24` | Late, pending, expiring |
| Error | `#DC2626` | `#F87171` | Rejected, absent, failed |
| Info | `#0284C7` | `#38BDF8` | Informational, on leave |

### Neutral Scale

| Token | Light Mode | Dark Mode |
|---|---|---|
| Neutral 50 | `#F8FAFC` | `#0F172A` |
| Neutral 100 | `#F1F5F9` | `#1E293B` |
| Neutral 200 | `#E2E8F0` | `#334155` |
| Neutral 300 | `#CBD5E1` | `#475569` |
| Neutral 500 | `#64748B` | `#94A3B8` |
| Neutral 700 | `#334155` | `#CBD5E1` |
| Neutral 900 | `#0F172A` | `#F1F5F9` |

### Semantic Token Map

All components reference semantic tokens, never raw hex values:

| Semantic Token | Purpose | Light | Dark |
|---|---|---|---|
| `--color-surface-primary` | Page background | `#FFFFFF` | `#0F172A` |
| `--color-surface-secondary` | Card background | `#F8FAFC` | `#1E293B` |
| `--color-surface-elevated` | Modal/dropdown bg | `#FFFFFF` | `#1E293B` |
| `--color-surface-sidebar` | Sidebar | `#0A2E4A` | `#020617` |
| `--color-text-primary` | Headings, body | `#0F172A` | `#F1F5F9` |
| `--color-text-secondary` | Labels, captions | `#64748B` | `#94A3B8` |
| `--color-text-inverse` | Text on primary bg | `#FFFFFF` | `#0F172A` |
| `--color-border-default` | Card borders | `#E2E8F0` | `#334155` |
| `--color-interactive-primary` | Buttons, links | `#0F4C75` | `#3282B8` |
| `--color-interactive-hover` | Hover states | `#3282B8` | `#60A5FA` |

### Status Soft Containers

Tinted alert banners, KPI tiles and status badges use a three-token set per family
instead of raw palette shades. Each token repaints per theme, so **consumers never
write a `dark:` variant** for status colour.

| Utility | Purpose |
|---|---|
| `bg-{family}-soft` | Tinted container background |
| `text-{family}-on-soft` | Text/icon colour that sits on that tint |
| `border-{family}-edge` | Border of the tinted container |

Families: `success`, `warning`, `destructive`, `info`, `brand` (Ethiopian gold),
`neutral`. Every family/theme pair is ≥ 5.7:1 contrast (most ≥ 7:1) in Light, Dark
and High Contrast.

```tsx
// before
<div className="border border-amber-300 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/30">
  <p className="text-amber-900 dark:text-amber-300">Offline</p>
</div>

// after
<div className="border border-warning-edge bg-warning-soft">
  <p className="text-warning-on-soft">Offline</p>
</div>
```

For a *solid* status fill (dots, pills, selected toggles) use `bg-success` /
`bg-warning` / `bg-destructive` / `bg-info` / `bg-brand-accent` with the matching
`text-{family}-foreground`, or `text-text-inverse` — never `text-white`, which
inverts wrongly once the status colour lightens in dark mode.

**Rule:** `text-blue-600` or `bg-slate-100` is banned in components. Use the semantic
utilities above (or `text-[var(--color-interactive-primary)]`). Enforced by
`src/test/semantic-color-tokens.test.ts`, which fails the suite if any raw Tailwind
palette class reappears under `src/`.

---

## Typography

### Font Stack

| Role | Family | Weights | Usage |
|---|---|---|---|
| Display | Inter | 600, 700 | Page titles, stat values, headings |
| Body | Inter | 400, 500 | Body text, labels, descriptions |
| Amharic | Noto Sans Ethiopic | 400, 500, 600, 700 | All Amharic text |
| Mono | JetBrains Mono | 400, 500 | Employee codes, amounts, timestamps |

### Type Scale

| Name | Size | Weight | Line Height | Usage |
|---|---|---|---|---|
| display-lg | 30px | 700 | 1.2 | Marketing hero |
| display | 24px | 600 | 1.25 | Page titles |
| heading | 20px | 600 | 1.3 | Section headers |
| subheading | 16px | 600 | 1.4 | Card headers |
| body | 14px | 400 | 1.5 | Default body text |
| body-medium | 14px | 500 | 1.5 | Labels, emphasis |
| small | 12px | 400 | 1.5 | Captions, timestamps |
| mono | 13px | 400 | 1.4 | Codes, amounts |

### Amharic Typography Rules

- **Line height:** 1.6–1.8 for Amharic text (characters clip at 1.5)
- **Column widths:** Amharic characters are wider than Latin — allow 120% width for Amharic columns in DataTable
- **Truncation:** `text-overflow: ellipsis` works but test with actual Amharic data
- **Word break:** Amharic does not hyphenate — use `overflow-wrap: break-word` not `word-break: break-all`
- **Sorting:** MariaDB `utf8mb4_unicode_ci` collation handles Amharic sorting
- **Search:** FULLTEXT index tokenizes Amharic correctly (test and verify)
- **Number formatting:** Ethiopia uses comma as thousands separator and period for decimal (same as US): `1,234.56 ETB`

---

## Spacing

Base unit: 4px

| Token | Value | Usage |
|---|---|---|
| space-1 | 4px | Tight padding (badge, tag) |
| space-2 | 8px | Inline spacing, icon gaps |
| space-3 | 12px | Input padding, small gaps |
| space-4 | 16px | Card padding, component spacing |
| space-5 | 20px | Section padding |
| space-6 | 24px | Large component spacing |
| space-8 | 32px | Section gaps |
| space-10 | 40px | Page margins |
| space-12 | 48px | Touch target minimum (mobile) |
| space-16 | 64px | Large section gaps |
| space-20 | 80px | Marketing section spacing |
| space-24 | 96px | Hero section padding |

---

## Elevation (Shadows)

| Level | Shadow | Usage |
|---|---|---|
| 0 | none | Flat cards in content area |
| 1 | `0 1px 3px rgba(0,0,0,0.08)` | Dropdowns, popovers, tooltips |
| 2 | `0 4px 12px rgba(0,0,0,0.10)` | Modals, dialogs, drawers |
| 3 | `0 8px 24px rgba(0,0,0,0.12)` | Command palette, toast stack |

Dark mode: reduce shadow opacity by 50% (shadows are less effective on dark backgrounds).

---

## Border Radius

| Token | Value | Usage |
|---|---|---|
| radius-sm | 4px | Inputs, buttons |
| radius-md | 6px | Cards |
| radius-lg | 8px | Modals, dialogs |
| radius-xl | 12px | Panels, large cards |
| radius-full | 9999px | Pills, badges, avatars |

---

## Signature Element: Ethiopian Tibeb Pattern

A geometric border pattern derived from Ethiopian traditional textile weaving (tilf). Used as:

- Sidebar footer decoration (subtle, 4px height, low opacity)
- Marketing page section dividers (16px height, medium opacity)
- Login page card border accent (bottom border only)
- Payslip PDF decorative header border

The pattern is an SVG consisting of repeating diamond and cross geometric shapes in `--color-accent` (Ethiopian Gold) with 20-40% opacity. It must be subtle — a cultural whisper, not a shout.

---

## Component Library

### Layout Components

| Component | Description | Mobile Behavior |
|---|---|---|
| `DashboardLayout` | Sidebar + header + content | Hamburger → overlay sidebar |
| `MobileTabBar` | Bottom tab navigation | Visible for employee role < 768px |
| `AuthLayout` | Centered card, professional bg | Full-width card |
| `MarketingLayout` | Marketing nav + footer + tibeb | Responsive nav → hamburger |
| `OnboardingLayout` | Progress bar + content | Stacked progress bar |
| `KioskLayout` | Full-screen, no nav | Always full-screen |

### Data Display

| Component | Props | Notes |
|---|---|---|
| `DataTable` | columns, data, query, config | TanStack Table v8 foundation. Keyboard nav, virtual scroll, column pinning/resizing/hiding, row expansion, inline edit, skeleton loading, empty state, export. |
| `StatCard` | value, label, trend, sparkline | KPI display. Trend: ↑/↓ arrow + % in green/red. |
| `StatusBadge` | status, size | Colored pill. Maps status strings to semantic colors. |
| `Timeline` | events[] | Vertical timeline with icons, timestamps, descriptions. |
| `ApprovalChain` | steps[], currentStep | Dot stepper with names and status per step. |
| `ComparisonCard` | before, after, label | Side-by-side before/after display. |
| `ProgressRing` | value, max, label | Circular progress indicator. |
| `CurrencyDisplay` | amountCents | Formats integer cents as `1,234.56 ETB`. |
| `AvatarGroup` | users[], max | Overlapping avatars with +N indicator. |
| `EmployeeAvatar` | name, photoThumbUrl, photoUrl, className, fallbackClassName | The only way to render a person. Falls back thumbnail → full-size → initials. Initials split on grapheme boundaries so Amharic syllables stay intact. Never hand-roll `name.split(" ").map(n => n[0])`. |
| `ChartWrapper` | type, data, options | Consistent chart styling: semantic colors, dark mode, currency axes. |

### Form Components

| Component | Props | Notes |
|---|---|---|
| `CurrencyInput` | value, onChange | ETB-formatted input. Stores integer cents. Shows `ETB` prefix. |
| `PhoneInput` | value, onChange | Ethiopian format: `+251 XX XXX XXXX`. |
| `DualCalendarPicker` | value, onChange, showEthiopian | Gregorian + Ethiopian side-by-side when tenant enables dual display. |
| `AddressForm` | value, onChange | Ethiopian address: region, city, subcity, woreda, kebele. |
| `FileUpload` | accept, maxSize, onUpload | Drag-and-drop, preview, progress, client-side type/size validation. |
| `SearchInput` | value, onChange, placeholder | Debounced (300ms), clear button, search icon. |

### Feedback Components

| Component | Props | Notes |
|---|---|---|
| `QueryBoundary` | query, loading, empty, error, children | Wraps TanStack Query. Renders correct state (skeleton/empty/error/success). **Required on every data-fetching component.** |
| `EmptyState` | icon, title, description, action | CTA-driven empty state. Used inside QueryBoundary. |
| `ErrorPanel` | error, onRetry | Inline error with retry button. Used inside QueryBoundary. |
| `LoadingSkeleton` | variant | Pre-defined shapes: card, table-row, stat-card, profile-header. |
| `ConfirmDialog` | title, description, onConfirm, variant | Destructive action confirmation. Variant: `danger` (red) or `warning` (amber). |
| `UndoToast` | message, onUndo, duration | Success toast with 5-second "Undo" button for optimistic actions. |
| `OfflineBanner` | — | Persistent banner when offline. Not a disappearing toast. |

### Navigation Components

| Component | Props | Notes |
|---|---|---|
| `CommandPalette` | — | ⌘K / Ctrl+K. Search employees, navigate pages, quick actions. Role-filtered. |
| `PageHeader` | title, breadcrumbs, actions | Page title with breadcrumb trail and action buttons. |
| `FilePreview` | url, type | Lightbox for PDF/image preview. |
| `PrintButton` | — | Triggers print with print-optimized CSS (forces light mode). |

---

## Mobile Patterns

### Employee Mobile Experience (< 768px)

- **Navigation:** `MobileTabBar` bottom tabs (Home, Attendance, Leave, Payslips, More)
- **Interactions:** Pull-to-refresh on all list views, swipe actions on approval items
- **Modals:** Bottom sheets (slide up from bottom) instead of centered modals
- **Touch targets:** Minimum 48px × 48px
- **Haptics:** Vibration API on check-in/check-out button press
- **Offline:** Persistent `OfflineBanner` when disconnected (not a disappearing toast)
- **Install:** PWA install prompt after 3rd visit

### Breakpoints

| Breakpoint | Target | Sidebar | Navigation |
|---|---|---|---|
| 375px | iPhone SE | Hidden (hamburger overlay) | MobileTabBar |
| 390px | iPhone 14 | Hidden (hamburger overlay) | MobileTabBar |
| 768px | iPad portrait | Collapsed (icons only) | Sidebar |
| 1024px | iPad landscape | Collapsed (icons only) | Sidebar |
| 1280px | Laptop | Full (icons + labels) | Sidebar |
| 1440px+ | Desktop | Full (icons + labels) | Sidebar |

---

## Dark Mode

### Implementation

- Toggle: system preference detection + manual toggle in sidebar
- Storage: `data-theme="dark"` attribute on `<html>` element
- All components use semantic CSS variables (never raw colors)
- Charts: `ChartWrapper` handles dark mode automatically
- Print: force light mode via `@media print` stylesheet

### Dark Mode Checklist (Per Component)

- [ ] Text readable against dark surface
- [ ] Borders visible (use `--color-border-default`)
- [ ] Status badges use dark-mode variant colors
- [ ] Form inputs: borders visible, placeholder readable
- [ ] Shadows reduced (50% opacity reduction)
- [ ] No hardcoded colors anywhere
- [ ] Charts render with dark-mode semantic colors

---

## Accessibility (WCAG 2.1 AA)

### Keyboard

- All interactive elements focusable
- Tab order matches visual order
- Custom focus ring: `2px solid var(--color-interactive-focus)`, `2px offset`
- Skip navigation link (hidden until focused)
- DataTable: arrow keys navigate cells, Enter edits, Escape cancels
- Modal/drawer: focus trapped, Escape closes
- Command palette: full keyboard navigation

### ARIA

- `aria-label` on icon-only buttons
- `aria-live="polite"` on notification toast container
- `aria-live="assertive"` on error messages
- `aria-expanded` on collapsible elements
- `aria-selected` on tabs
- `aria-current="page"` on active nav item
- `role="alert"` on form error summaries

### Color

- Contrast ratio: 4.5:1 for normal text, 3:1 for large text
- Status never conveyed by color alone (always paired with icon or text)
- Charts include text alternatives (data tables or descriptions)

### Language

- `lang` attribute on `<html>` switches with locale
- `lang="am"` on inline Amharic text when page is in English mode
