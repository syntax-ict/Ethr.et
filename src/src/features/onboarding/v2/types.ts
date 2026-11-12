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

export type MatchOutcome =
  "matched" | "probable" | "ambiguous" | "archived" | "new";
export type StagingAction = "merge" | "create" | "skip" | "defer" | "pending";

export interface StagingCandidate {
  employee_public_id: string;
  employee_name: string;
  score: number;
  reasons: string[];
}

/**
 * One thing adding a matched row would change on the employee it matched.
 * A `fill` goes into a blank; a `conflict` is written only once accepted.
 */
export interface StagedChange {
  field: string;
  kind: "fill" | "conflict";
  /** What ETHR holds. Null when blank, masked, or not the viewer's to see. */
  current: string | null;
  /** What the list brings. Null for a national ID, a TIN and a salary. */
  incoming: string | null;
  masked: boolean;
  accepted: boolean;
  /** Another employee already holds this number, so it is never written. */
  taken: boolean;
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
  changes: StagedChange[];
  /** The viewer may edit the matched employee. */
  can_apply_changes: boolean;
  /** The matched employee has a change waiting for a later date. */
  scheduled_action: boolean;
  processed_at: string | null;
}

/** What the server made of a list of people, before anything is staged. */
export interface PeopleRead {
  /** People ready to be staged. */
  people: number;
  /** Whether the first line was column names. */
  header: boolean;
  /** The columns read, by the field each one is. */
  columns: string[];
  /** Column names that mean nothing here, as written. */
  ignored: string[];
  calendar: "ethiopian" | "gregorian";
  /** True when the column name said which calendar, over the choice. */
  calendar_from_header: boolean;
  /** People ready who bring no hire date, so the day they are added is used. */
  without_hire_date: number;
  /** Lines that cannot be staged. `line` counts as the reader sees it. */
  problems: { line: number; message: string }[];
  /**
   * The first line as column names: each as written, the column it is read
   * as, and for one that is not read, what it might be.
   */
  headers: {
    header: string;
    column: string | null;
    suggested: string | null;
  }[];
  /** The first line was read as a person and looks like column names. */
  header_likely: boolean;
  /** The columns were matched and none of them is the name; nothing was read. */
  needs_name: boolean;
  /** The saved source the list was read as. */
  source: string | null;
  /** A saved source whose column names this list has. */
  suggested_source: string | null;
}

/** What one of a file's column names is: an ETHR column, or null for one left out. */
export interface ColumnMatch {
  header: string;
  column: string | null;
}

/** A system the organisation brings lists from, and how it names its columns. */
export interface ImportSource {
  public_id: string;
  name: string;
  columns: ColumnMatch[];
  calendar: string | null;
  updated_at: string | null;
}

/** How a list names its columns: a saved source, columns matched now, or both. */
export interface ListColumns {
  source?: string | null;
  mapping?: ColumnMatch[] | null;
}

/** What a list's new people arrive as. A line naming its own status keeps it. */
export type ArrivalStatus = "hired" | "probation" | "confirmed";

export interface MigrationBatch {
  public_id: string;
  source_type: string;
  source_ref: string | null;
  status: "reviewing" | "committing" | "committed";
  /** Chosen when the list began to be added; null when nothing was. */
  arrival_status?: ArrivalStatus | null;
  totals: Record<string, number> | null;
  summary: Record<string, number>;
  rows: StagingRow[];
}

export interface CommitResult extends MigrationLogins {
  message: string;
  totals: Record<string, number>;
  status: string;
  /** Rows still to be dealt with. The commit is sent again until it is 0. */
  remaining: number;
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
