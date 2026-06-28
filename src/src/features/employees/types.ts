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
  salary_cents?: number;
  department: { public_id: string; name: string } | null;
  branch: { public_id: string; name: string } | null;
  position: { public_id: string; name: string } | null;
  created_at: string;
}

export interface EmployeeFormData {
  name: string;
  name_am?: string;
  email: string;
  phone: string;
  employee_code: string;
  gender: string;
  date_of_birth: string;
  nationality: string;
  marital_status: string;
  hire_date: string;
  salary_cents: number;
  department_public_id?: string;
  branch_public_id?: string;
  position_public_id?: string;
}
