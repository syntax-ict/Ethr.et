// Types for the onboarding v2 backend contract. See FRONTEND.md and
// ONBOARDING_V2.md. Response shapes mirror the Laravel controllers exactly.

export interface Industry {
  key: string;
  label: string;
  label_am: string;
  base: string;
  icon: string;
  group: string;
  /** The workforce profile this industry usually runs on. */
  profile?: string;
  /** The policy profile its rules usually start from. */
  policy?: string;
}

/** A workforce (how people work) or policy (which law) profile. */
export interface ConfigurationProfile {
  key: string;
  label: string;
  label_am: string;
  description: string;
  description_am?: string;
}

export interface IndustryCatalogResponse {
  data: Industry[];
  workforce_profiles?: ConfigurationProfile[];
  policy_profiles?: ConfigurationProfile[];
  catalog_version?: string;
}

export interface ConfigurationReviewNote {
  code: string;
  message: string;
}

export type ConfigurationSource =
  "explicit" | "industry_default" | "heuristic" | "global_fallback";

export interface ConfigurationSection {
  source: ConfigurationSource;
  confidence: number;
  items: unknown;
}

export interface ConfigurationPlan {
  industry: { key: string; label: string; label_am: string; base: string };
  signals: Record<string, unknown>;
  overall_confidence: number;
  sections: Record<string, ConfigurationSection>;
  plan: Record<string, unknown>;
  profiles?: {
    workforce?: { key: string; label: string; label_am: string };
    policy?: { key: string; label: string; label_am: string };
  };
  /** Sensitive defaults the admin confirms before applying. */
  review?: ConfigurationReviewNote[];
  catalog_version?: string;
}

export interface ProvisionTally {
  created: number;
  skipped: number;
  restored: number;
}

export interface ApplyConfigurationResult {
  message: string;
  industry: { key: string; label: string; base: string } | null;
  provisioned: {
    resources: Record<string, ProvisionTally>;
    total_created: number;
    warnings: string[];
  };
}

// ── Access & identity ────────────────────────────────────────────

export type LoginIdentifier = "email" | "phone" | "employee_code" | "username";

export interface AccessPolicy {
  available: LoginIdentifier[];
  login_identifiers: LoginIdentifier[];
  role_defaults: Record<string, string>;
}

// ── Readiness ────────────────────────────────────────────────────

export type ReadinessLevel = "ready" | "needs_attention" | "not_ready";

export interface ReadinessCheck {
  id: string;
  label: string;
  category: string;
  passed: boolean;
  remediation: string;
}

export interface ReadinessReport {
  overall_score: number;
  level: ReadinessLevel;
  categories: Record<string, { score: number; checks: ReadinessCheck[] }>;
  gaps: ReadinessCheck[];
}

export interface GoLiveResult {
  message: string;
  readiness: ReadinessReport;
  redirect: string;
}

// ── Migration ────────────────────────────────────────────────────

export type MatchOutcome = "matched" | "probable" | "ambiguous" | "new";
export type StagingAction = "merge" | "create" | "skip" | "defer" | "pending";

export interface StagingCandidate {
  employee_public_id: string;
  employee_name: string;
  score: number;
  reasons: string[];
}

export interface StagingRow {
  public_id: string;
  display_name: string | null;
  external_identifier: string | null;
  match_outcome: MatchOutcome | null;
  match_confidence: number | null;
  candidates: StagingCandidate[] | null;
  resolved_employee_public_id: string | null;
  action: StagingAction;
  processed_at: string | null;
}

export interface MigrationBatch {
  public_id: string;
  source_type: string;
  source_ref: string | null;
  status: "reviewing" | "committed";
  totals: Record<string, number> | null;
  summary: Record<string, number>;
  rows: StagingRow[];
}

export interface CommitResult extends MigrationLogins {
  message: string;
  totals: Record<string, number>;
  status: string;
}

/** A migrated employee who cannot sign in yet, and how they would be invited. */
export interface PendingLogin {
  employee_public_id: string;
  name: string;
  email: string | null;
  phone: string | null;
  channel: "email" | "sms" | "in_person";
  /** A login exists and was invited; it has not been activated. */
  invited: boolean;
}

export interface MigrationLogins {
  without_login: PendingLogin[];
  /** People created with today as their hire date, because the source had none. */
  hire_dates_defaulted: number;
}

export interface CreateLoginsResult {
  message: string;
  created: number;
  active: number;
  in_person: number;
  email_in_use: number;
  batch: string | null;
  queued: number;
}
