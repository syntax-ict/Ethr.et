export interface PaginatedResponse<T> {
  data: T[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number;
    to: number;
  };
  links: {
    first: string;
    last: string;
    prev: string | null;
    next: string | null;
  };
}

export interface Tenant {
  public_id: string;
  name: string;
  subdomain: string;
  type: string | null;
  status: string;
  default_locale: string;
  timezone: string;
  ethiopian_calendar: boolean;
  logo_path: string | null;
  theme: Record<string, string> | null;
  created_at: string;
}

export interface User {
  public_id: string;
  name: string | null;
  name_am: string | null;
  email: string;
  phone: string | null;
  role: string;
  status: string;
  mfa_enabled: boolean;
  locale: string;
  /**
   * `ProfilePreferencesController::present()`. `calendar` is the one in force:
   * the user's own choice, else the organisation's default, else Ethiopian.
   */
  preferences?: {
    locale: string;
    theme: string;
    calendar: string;
  };
  last_login_at: string | null;
  employee_code: string | null;
  /**
   * The caller's own employee record, or null for an account with none (a
   * platform admin). Offline punches are queued under it; the mobile page
   * used to read a nested `employee` that /auth/me never sent (N49).
   */
  employee_public_id?: string | null;
  photo_thumb_url: string | null;
}

export interface Employee {
  public_id: string;
  name: string;
  name_am: string | null;
  email: string | null;
  phone: string | null;
  employee_code: string | null;
  gender: string | null;
  status: string;
  hire_date: string;
  department: { public_id: string; name: string } | null;
  branch: { public_id: string; name: string } | null;
  position: { public_id: string; title: string } | null;
  created_at: string;
}
