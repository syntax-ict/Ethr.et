# ETHR — Frontend Conventions

## Stack

- Next.js 15 (App Router)
- React 19
- TypeScript (strict mode)
- Tailwind CSS 4
- shadcn/ui (component library)
- TanStack Query v5 (server state)
- React Hook Form (form management)
- Zod (schema validation)
- Vitest + React Testing Library (tests)
- Prettier (formatting)

---

## Project Structure

```
src/
  app/                          Next.js App Router
    (marketing)/                Public marketing pages (no auth)
      page.tsx                  Landing page
      pricing/page.tsx
      features/page.tsx
      faq/page.tsx
      contact/page.tsx
    (auth)/                     Auth pages (no sidebar)
      login/page.tsx
      register/page.tsx
      verify/page.tsx
      mfa/page.tsx
    (dashboard)/                Authenticated app (with sidebar)
      layout.tsx                Dashboard layout (sidebar + header)
      page.tsx                  Dashboard home
      employees/
        page.tsx                Employee list
        [id]/page.tsx           Employee detail
        new/page.tsx            Create employee
        import/page.tsx         CSV import
      attendance/
        page.tsx                Attendance overview
        corrections/page.tsx    Corrections list
        devices/page.tsx        Device management
      leave/
        page.tsx                Leave management
        calendar/page.tsx       Team calendar
      payroll/
        page.tsx                Payroll runs
        [id]/page.tsx           Run detail
        payslips/page.tsx       My payslips
      reports/
        page.tsx                Report builder
      settings/
        page.tsx                Tenant settings
        organization/page.tsx   Org structure
        shifts/page.tsx         Shift config
        holidays/page.tsx       Holiday management
        payroll/page.tsx        Payroll rules
        leave/page.tsx          Leave policies
      admin/                    Super admin
        page.tsx                Admin dashboard
        tenants/page.tsx        Tenant management

  components/
    ui/                         shadcn/ui primitives (Button, Card, Dialog, etc.)
    shared/                     Shared business components
      DataTable.tsx             Reusable data table with sorting/filtering/pagination
      PageHeader.tsx            Standard page header
      EmptyState.tsx            Empty state with icon + message + action
      LoadingSkeleton.tsx       Skeleton loading states
      ErrorBoundary.tsx         Error boundary with retry
      ConfirmDialog.tsx         Confirmation modal
      FileUpload.tsx            File upload with preview
      DatePicker.tsx            Dual calendar date picker (Gregorian + Ethiopian)
      CurrencyDisplay.tsx       ETB formatting component
      StatusBadge.tsx           Colored status badges
      AvatarGroup.tsx           Employee avatar group
      SearchInput.tsx           Debounced search input
    layouts/
      DashboardLayout.tsx       Sidebar + header + content
      AuthLayout.tsx            Centered card layout
      MarketingLayout.tsx       Marketing nav + footer
      OnboardingLayout.tsx      Wizard layout

  features/                     Feature-scoped modules
    auth/
      components/
        LoginForm.tsx
        MfaForm.tsx
        RegisterForm.tsx
      hooks/
        useAuth.ts              Auth state + actions
      api.ts                    TanStack Query hooks (login, logout, refresh)
      types.ts
    employees/
      components/
        EmployeeCard.tsx
        EmployeeForm.tsx
        EmployeeTimeline.tsx
        ImportWizard.tsx
      api.ts
      types.ts
    attendance/
      components/
        AttendanceTimeline.tsx
        CheckInButton.tsx
        CorrectionForm.tsx
        DeviceStatusCard.tsx
        ShiftCalendar.tsx
      api.ts
      types.ts
    leave/
      components/
        LeaveForm.tsx
        LeaveCalendar.tsx
        BalanceCard.tsx
      api.ts
      types.ts
    payroll/
      components/
        PayrollRunCard.tsx
        PayslipView.tsx
        SalaryBreakdown.tsx
      api.ts
      types.ts
    dashboard/
      components/
        KpiCard.tsx
        AttendanceChart.tsx
        DepartmentComparison.tsx
      api.ts
      types.ts
    notifications/
      components/
        NotificationBell.tsx
        NotificationList.tsx
      api.ts
      types.ts
    onboarding/
      components/
        SetupWizard.tsx
        OrgTemplateSelector.tsx
        DepartmentEditor.tsx
        ShiftConfigurator.tsx
        EmployeeImportStep.tsx
      api.ts
      types.ts
    settings/
      components/
      api.ts
      types.ts

  api/
    client.ts                   Axios instance + interceptors
    types/                      Shared TypeScript types
      common.ts                 PaginatedResponse, ApiError, etc.
      tenant.ts
      user.ts
      employee.ts
      attendance.ts
      leave.ts
      payroll.ts
      billing.ts
      admin.ts

  lib/
    i18n/
      index.ts                  i18n setup
      locales/
        en.json                 English translations
        am.json                 Amharic translations
    calendar/
      ethiopian.ts              Ethiopian calendar conversion
      holidays.ts               Holiday detection
      formatters.ts             Date display formatters
    utils/
      currency.ts               ETB formatting
      phone.ts                  Ethiopian phone formatting
      cn.ts                     Tailwind class merge utility
      date.ts                   Date utilities (UTC <-> EAT)
    hooks/
      useCurrentTenant.ts       Tenant context
      useCurrentUser.ts         User context + permissions
      useMediaQuery.ts          Responsive breakpoint hook
      useDebounce.ts
      useLocalStorage.ts
      useOfflineStatus.ts       Online/offline detection

  styles/
    globals.css                 Tailwind imports + CSS variables
```

---

## API Client

```typescript
// src/api/client.ts
import axios from 'axios';

const api = axios.create({
  baseURL: '/api/v1',
  headers: { 'Accept': 'application/json' },
  withCredentials: true, // send httpOnly refresh cookie
});

// Request interceptor: attach access token
api.interceptors.request.use((config) => {
  const token = getAccessToken(); // from memory (not localStorage)
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// Response interceptor: auto-refresh on 401
api.interceptors.response.use(
  (response) => response,
  async (error) => {
    if (error.response?.status === 401 && !error.config._retry) {
      error.config._retry = true;
      const newToken = await refreshToken();
      error.config.headers.Authorization = `Bearer ${newToken}`;
      return api(error.config);
    }
    return Promise.reject(error);
  }
);

export default api;
```

---

## TanStack Query Conventions

```typescript
// src/features/employees/api.ts
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/api/client';
import type { Employee, EmployeeInput, PaginatedResponse } from './types';

// Query keys — colocated with hooks
export const employeeKeys = {
  all: ['employees'] as const,
  lists: () => [...employeeKeys.all, 'list'] as const,
  list: (filters: Record<string, string>) => [...employeeKeys.lists(), filters] as const,
  details: () => [...employeeKeys.all, 'detail'] as const,
  detail: (id: string) => [...employeeKeys.details(), id] as const,
};

// List hook
export function useEmployees(filters: Record<string, string> = {}) {
  return useQuery({
    queryKey: employeeKeys.list(filters),
    queryFn: () => api.get<PaginatedResponse<Employee>>('/employees', { params: filters }).then(r => r.data),
  });
}

// Detail hook
export function useEmployee(id: string) {
  return useQuery({
    queryKey: employeeKeys.detail(id),
    queryFn: () => api.get<Employee>(`/employees/${id}`).then(r => r.data),
    enabled: !!id,
  });
}

// Mutation hook
export function useCreateEmployee() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: EmployeeInput) => api.post<Employee>('/employees', data).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: employeeKeys.lists() });
    },
  });
}
```

### Rules
- Query keys in a `keys` factory object per feature
- Mutations invalidate related queries on success
- Use `enabled` to conditionally run queries
- Use `select` for data transformation (not in queryFn)
- Stale time: 30s for lists, 60s for details (configurable)

---

## Form Conventions (React Hook Form + Zod)

```typescript
// src/features/employees/components/EmployeeForm.tsx
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';

const employeeSchema = z.object({
  name: z.string().min(1, 'employee.validation.name_required'),
  email: z.string().email('employee.validation.email_invalid').optional(),
  phone: z.string().regex(/^\+251\d{9}$/, 'employee.validation.phone_format').optional(),
  employee_code: z.string().min(1, 'employee.validation.code_required'),
  department_id: z.string().min(1, 'employee.validation.department_required'),
  hire_date: z.string().min(1, 'employee.validation.hire_date_required'),
  salary_cents: z.number().min(0, 'employee.validation.salary_positive'),
});

type EmployeeInput = z.infer<typeof employeeSchema>;

export function EmployeeForm({ onSubmit, defaultValues }: Props) {
  const { register, handleSubmit, formState: { errors, isSubmitting } } = useForm<EmployeeInput>({
    resolver: zodResolver(employeeSchema),
    defaultValues,
  });

  return (
    <form onSubmit={handleSubmit(onSubmit)}>
      {/* form fields using shadcn/ui components */}
    </form>
  );
}
```

### Rules
- Zod schema colocated with form component
- Validation messages use i18n keys
- Match Zod schema to backend FormRequest rules
- Use `isSubmitting` to disable submit button
- Show field-level errors from Zod + server errors from API

---

## Component Conventions

### File Structure

```typescript
// One component per file
// Named export (not default)
// Props type defined inline or colocated

interface EmployeeCardProps {
  employee: Employee;
  onEdit?: () => void;
}

export function EmployeeCard({ employee, onEdit }: EmployeeCardProps) {
  const { t } = useTranslation();

  return (
    <Card>
      {/* ... */}
    </Card>
  );
}
```

### Rules
- Named exports (no `export default`)
- Props interface colocated with component
- All user-facing text via `t()` (i18n)
- Use shadcn/ui primitives (Button, Card, Dialog, Input, Select, Table, etc.)
- Compose with Tailwind — no CSS modules, no styled-components
- Responsive: mobile-first (`sm:`, `md:`, `lg:` breakpoints)

---

## TypeScript Type Conventions

```typescript
// src/features/employees/types.ts

export interface Employee {
  public_id: string;
  name: string;
  email: string | null;
  phone: string | null;
  employee_code: string;
  status: EmployeeStatus;
  department: Department | null;
  branch: Branch | null;
  position: Position | null;
  hire_date: string;          // ISO 8601
  created_at: string;
  updated_at: string;
}

export type EmployeeStatus =
  | 'hired'
  | 'probation'
  | 'confirmed'
  | 'suspended'
  | 'resigned'
  | 'terminated'
  | 'retired';

// Shared types in src/api/types/common.ts
export interface PaginatedResponse<T> {
  data: T[];
  links: {
    first: string;
    last: string;
    prev: string | null;
    next: string | null;
  };
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export interface ApiError {
  type: string;
  title: string;
  status: number;
  detail: string;
  errors?: Record<string, string[]>;
}
```

### Rules
- Interfaces for API response shapes (matches JsonResource output)
- No numeric `id` field — only `public_id`
- Dates as ISO 8601 strings
- Currency as integer cents
- Union types for enums (matches backend string enums)
- `null` not `undefined` for optional API fields

---

## i18n (Internationalization)

```typescript
// src/lib/i18n/index.ts
// Using next-intl or react-i18next

// Translation files: src/lib/i18n/locales/en.json
{
  "common": {
    "save": "Save",
    "cancel": "Cancel",
    "delete": "Delete",
    "edit": "Edit",
    "loading": "Loading...",
    "no_results": "No results found",
    "confirm": "Are you sure?"
  },
  "employee": {
    "title": "Employees",
    "create": "Add Employee",
    "profile": {
      "title": "Employee Profile",
      "personal": "Personal Information",
      "employment": "Employment Details"
    },
    "validation": {
      "name_required": "Employee name is required",
      "email_invalid": "Please enter a valid email address"
    }
  },
  "attendance": {
    "check_in": "Check In",
    "check_out": "Check Out",
    "status": {
      "present": "Present",
      "absent": "Absent",
      "late": "Late",
      "early_leave": "Early Leave"
    }
  }
}
```

### Rules
- All user-facing strings use translation keys
- Ship `en.json` + `am.json` in v1.0
- Keys structured by feature: `feature.section.key`
- Language switcher in header (persisted in user preferences)
- RTL support not required for v1.0 languages
- Direction: LTR for all v1.0 languages

---

## Ethiopian Calendar Integration

```typescript
// src/lib/calendar/ethiopian.ts

export interface EthiopianDate {
  year: number;
  month: number;  // 1-13 (13th month = Pagume, 5-6 days)
  day: number;
}

export function toEthiopian(gregorian: Date): EthiopianDate;
export function toGregorian(ethiopian: EthiopianDate): Date;
export function formatEthiopian(date: Date, locale: 'en' | 'am'): string;
export function ethiopianMonthName(month: number, locale: 'en' | 'am'): string;
```

### Dual Calendar Display

When tenant enables dual calendar:
- Date inputs show both calendars with a toggle
- Date displays show: "June 27, 2026 (Sene 20, 2018)" or "ሰኔ 20, 2018 (June 27, 2026)" depending on primary language
- Date picker allows input in either calendar
- Storage is always Gregorian ISO 8601

---

## Responsive Design

### Breakpoints (Tailwind)

| Prefix | Width | Device |
|---|---|---|
| (none) | 0+ | Mobile (default) |
| `sm:` | 640px+ | Large phone |
| `md:` | 768px+ | Tablet |
| `lg:` | 1024px+ | Desktop |
| `xl:` | 1280px+ | Large desktop |

### Layout Patterns

- **Mobile:** single column, bottom nav, stacked cards
- **Tablet:** two columns, collapsible sidebar
- **Desktop:** full sidebar, multi-column grids, expanded tables

### Rules
- Mobile-first: write base styles for mobile, layer up
- Sidebar: hidden on mobile (hamburger toggle), visible on lg+
- Tables: card layout on mobile, table on md+
- Forms: single column on mobile, multi-column on lg+
- Touch targets: minimum 44x44px on mobile

---

## Dark Mode

- System preference detection (default)
- User toggle (persisted in tenant settings)
- CSS variables for theme colors (defined in `globals.css`)
- shadcn/ui handles dark mode via `dark:` prefix
- All custom components must support dark mode

```css
/* src/styles/globals.css */
@layer base {
  :root {
    --background: 0 0% 100%;
    --foreground: 0 0% 3.9%;
    /* ... shadcn/ui CSS variables */
  }
  .dark {
    --background: 0 0% 3.9%;
    --foreground: 0 0% 98%;
    /* ... */
  }
}
```

---

## PWA Configuration (v1.0)

```json
// public/manifest.json
{
  "name": "ETHR - Ethiopian Workforce OS",
  "short_name": "ETHR",
  "start_url": "/",
  "display": "standalone",
  "theme_color": "#1e40af",
  "background_color": "#ffffff",
  "icons": [...]
}
```

Service worker scope:
- Cache static assets (JS, CSS, images, fonts)
- Cache API responses for employee directory (read-only)
- Queue attendance check-in/out for offline sync
- Background sync for queued attendance records
- Show offline indicator banner when disconnected

---

## Error Handling

### Error Boundary

```typescript
// Wrap each route segment with ErrorBoundary
// Show user-friendly error with retry button
// Log to console (no external service in v1.0)
```

### API Error Display

```typescript
// Parse RFC-7807 errors
function parseApiError(error: AxiosError<ApiError>): string {
  const data = error.response?.data;
  if (data?.errors) {
    return Object.values(data.errors).flat().join(', ');
  }
  return data?.detail ?? t('common.error.unexpected');
}

// Show via toast (shadcn/ui Sonner)
toast.error(parseApiError(error));
```

### State Patterns

| State | Display |
|---|---|
| Loading | Skeleton screen (match final layout shape) |
| Empty | Icon + message + primary action button |
| Error | Error message + retry button |
| Success | Toast notification (auto-dismiss 5s) |
| Offline | Persistent banner at top |

---

## Testing (Vitest)

```typescript
// src/features/employees/components/__tests__/EmployeeCard.test.tsx
import { render, screen } from '@testing-library/react';
import { EmployeeCard } from '../EmployeeCard';

describe('EmployeeCard', () => {
  it('displays employee name and department', () => {
    render(<EmployeeCard employee={mockEmployee} />);

    expect(screen.getByText('John Doe')).toBeInTheDocument();
    expect(screen.getByText('Engineering')).toBeInTheDocument();
  });

  it('shows status badge', () => {
    render(<EmployeeCard employee={{ ...mockEmployee, status: 'probation' }} />);

    expect(screen.getByText('Probation')).toBeInTheDocument();
  });
});
```

### Rules
- Test user-visible behavior (not implementation details)
- Use `screen.getByText`, `getByRole` (not `getByTestId`)
- Mock API calls with MSW
- Test loading, empty, and error states
- Test form validation messages
- One test file per component (in `__tests__/` adjacent directory)
