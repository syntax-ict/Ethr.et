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

/**
 * Mirrors the `template_data` the backend seeder writes and
 * OrganizationProvisioner consumes. Departments, positions, and leave types
 * accept either shorthand strings or fully-specified objects.
 */
export interface TemplateData {
  departments?: Array<string | { name: string }>;
  positions?: Array<string | { title?: string; name?: string }>;
  grades?: GradeTemplate[];
  shifts?: ShiftTemplate[];
  leave_types?: Array<string | LeaveTypeTemplate>;
  settings?: Record<string, unknown>;
}

export interface ShiftTemplate {
  name: string;
  start: string;
  end: string;
  days: string;
  break_minutes?: number;
  grace_minutes?: number;
}

export interface GradeTemplate {
  name: string;
  min_salary_cents: number;
  max_salary_cents: number;
  sort_order?: number;
}

export interface LeaveTypeTemplate {
  code: string;
  name?: string;
  default_days?: number;
  is_paid?: boolean;
}

/** Per-resource tally of what applying a template actually changed. */
export interface ProvisioningTally {
  created: number;
  skipped: number;
  restored: number;
}

export interface ApplyTemplateResponse {
  message: string;
  template: { public_id: string; name: string; slug: string };
  provisioned: {
    resources: Record<string, ProvisioningTally>;
    total_created: number;
    warnings: string[];
  };
  data: TemplateData;
}

export const WIZARD_STEPS = [
  {
    number: 1,
    title: "Organization Profile",
    titleKey: "onboarding.step.org_profile",
    key: "org_profile",
  },
  {
    number: 2,
    title: "Organization Structure",
    titleKey: "onboarding.step.org_structure",
    key: "org_structure",
  },
  {
    number: 3,
    title: "Work Schedule",
    titleKey: "onboarding.step.work_schedule",
    key: "work_schedule",
  },
  {
    number: 4,
    title: "Leave Policies",
    titleKey: "onboarding.step.leave_policies",
    key: "leave_policies",
  },
  {
    number: 5,
    title: "Payroll Configuration",
    titleKey: "onboarding.step.payroll_config",
    key: "payroll_config",
  },
  {
    number: 6,
    title: "Employee Import",
    titleKey: "onboarding.step.employee_import",
    key: "employee_import",
  },
  {
    number: 7,
    title: "Review & Launch",
    titleKey: "onboarding.step.review_launch",
    key: "review_launch",
  },
] as const;
