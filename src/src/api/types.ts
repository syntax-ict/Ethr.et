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
  last_login_at: string | null;
  employee_code: string | null;
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
