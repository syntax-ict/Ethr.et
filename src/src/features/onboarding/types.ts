export interface OnboardingProgress {
  current_step: number;
  completed_steps: number[];
  step_data: Record<string, unknown>;
  completed_at: string | null;
}

export interface OrganizationTemplate {
  public_id: string;
  name: string;
  slug: string;
  description: string | null;
  icon: string | null;
  template_data: TemplateData;
  sort_order: number;
}

export interface TemplateData {
  departments?: string[];
  positions?: string[];
  shifts?: ShiftTemplate[];
  leave_types?: LeaveTypeTemplate[];
}

export interface ShiftTemplate {
  name: string;
  start_time: string;
  end_time: string;
  break_minutes: number;
}

export interface LeaveTypeTemplate {
  name: string;
  days_per_year: number;
  is_paid: boolean;
}

export const WIZARD_STEPS = [
  { number: 1, title: 'Organization Profile', key: 'org_profile' },
  { number: 2, title: 'Organization Structure', key: 'org_structure' },
  { number: 3, title: 'Work Schedule', key: 'work_schedule' },
  { number: 4, title: 'Leave Policies', key: 'leave_policies' },
  { number: 5, title: 'Payroll Configuration', key: 'payroll_config' },
  { number: 6, title: 'Employee Import', key: 'employee_import' },
  { number: 7, title: 'Review & Launch', key: 'review_launch' },
] as const;
