"use client";

import Link from "next/link";
import { useState, type ReactNode } from "react";
import { Landmark, Receipt, TrendingUp, UserX } from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import { formatETB } from "@/lib/utils/currency";
import type {
  MissingTin,
  NoAttendance,
  PayChanges,
  SharedBankAccounts,
} from "../api";

/**
 * What an approver should look at before releasing a run, found by rule
 * (PreApprovalChecks, 2026-11-22). None of it blocks approval or changes
 * pay: each list says what was found and why, and the approver decides.
 */

type Person = { employee_public_id: string | null; employee_name: string };

function PersonName({ person }: { person: Person }) {
  return person.employee_public_id ? (
    <Link
      href={`/employees/${person.employee_public_id}`}
      className="font-medium underline underline-offset-2"
    >
      {person.employee_name}
    </Link>
  ) : (
    <span className="font-medium">{person.employee_name}</span>
  );
}

function CheckBanner({
  label,
  icon,
  children,
}: {
  label: string;
  icon: ReactNode;
  children: ReactNode;
}) {
  return (
    <section
      aria-label={label}
      className="rounded-lg border border-warning-edge bg-warning-soft p-4 text-sm text-warning-on-soft"
    >
      <div className="flex gap-2">
        <span className="mt-0.5 shrink-0" aria-hidden="true">
          {icon}
        </span>
        <div className="min-w-0 space-y-2">{children}</div>
      </div>
    </section>
  );
}

/** How many of a list are shown before "Show all". */
export const SHOWN = 10;

/**
 * The first few of a list, and a button for the rest: a run of 4,000 people
 * with no TIN is not 4,000 lines on the page.
 */
function ShortList<T>({
  items,
  render,
}: {
  items: T[];
  render: (item: T, index: number) => ReactNode;
}) {
  const { t } = useT();
  const [all, setAll] = useState(false);
  const shown = all ? items : items.slice(0, SHOWN);

  return (
    <>
      <ul className="list-inside list-disc space-y-0.5">{shown.map(render)}</ul>
      {items.length > SHOWN && (
        <button
          type="button"
          aria-expanded={all}
          onClick={() => setAll(!all)}
          className="font-medium underline underline-offset-2"
        >
          {all
            ? t("payroll_checks.show_fewer", "Show fewer")
            : t("payroll_checks.show_all", "Show all :count", {
                count: items.length,
              })}
        </button>
      )}
    </>
  );
}

/** A signed amount: "+ETB 3,500.00" or "-ETB 1,200.00". */
function signed(cents: number): string {
  return `${cents > 0 ? "+" : "-"}${formatETB(Math.abs(cents))}`;
}

/** Net pay that moved past the organisation's thresholds since the last released run. */
export function PayChangesBanner({ changes }: { changes: PayChanges }) {
  const { t } = useT();
  if (changes.count === 0) return null;

  const part = (key: string): string =>
    ({
      basic_cents: t("payroll_checks.part_basic", "Basic"),
      overtime_cents: t("payroll_checks.part_overtime", "Overtime"),
      allowances_cents: t("payroll_checks.part_allowances", "Allowances"),
      lateness_cents: t("payroll_checks.part_lateness", "Lateness deducted"),
      income_tax_cents: t("payroll_checks.part_income_tax", "Income tax"),
      employee_pension_cents: t(
        "payroll_checks.part_pension",
        "Employee pension",
      ),
      loan_cents: t("payroll_checks.part_loan", "Loan"),
      cost_sharing_cents: t("payroll_checks.part_cost_sharing", "Cost sharing"),
    })[key] ?? key;

  return (
    <CheckBanner
      label={t("payroll_checks.pay_changes_title", "Net pay that changed")}
      icon={<TrendingUp className="h-4 w-4" />}
    >
      <p className="font-medium">
        {t(
          "payroll_checks.pay_changes_summary",
          ":count employee(s) have net pay at least :percent% and :min away from :period.",
          {
            count: changes.count,
            percent: changes.percent,
            min: formatETB(changes.min_cents),
            period: changes.previous_period ?? "",
          },
        )}
      </p>
      <p>
        {t(
          "payroll_checks.pay_changes_hint",
          "A change can be right, such as a raise or overtime. Check each one is expected before approving.",
        )}
      </p>
      <ShortList
        items={changes.employees}
        render={(e, i) => (
          <li key={e.employee_public_id ?? i}>
            <PersonName person={e} />
            {": "}
            {t(
              "payroll_checks.pay_change_employee",
              ":before to :after (:change)",
              {
                before: formatETB(e.previous_net_cents),
                after: formatETB(e.net_cents),
                change:
                  e.change_percent === null
                    ? signed(e.change_cents)
                    : `${signed(e.change_cents)}, ${e.change_percent > 0 ? "+" : ""}${e.change_percent}%`,
              },
            )}
            {e.reasons.length > 0 && (
              <span>
                {". "}
                {t("payroll_checks.pay_change_reasons", "Moved most: :parts", {
                  parts: e.reasons
                    .map((r) => `${part(r.part)} ${signed(r.change_cents)}`)
                    .join(", "),
                })}
              </span>
            )}
          </li>
        )}
      />
    </CheckBanner>
  );
}

/** Two or more people of the run paid into one account. */
export function SharedBankAccountsBanner({
  shared,
}: {
  shared: SharedBankAccounts;
}) {
  const { t } = useT();
  if (shared.support_session) {
    return (
      <CheckBanner
        label={t("payroll_checks.shared_title", "Accounts paid more than once")}
        icon={<Landmark className="h-4 w-4" />}
      >
        <p>
          {t(
            "payroll_checks.shared_support_session",
            "Who is paid into which account is not shown in a support session. The organisation sees this check on its own.",
          )}
        </p>
      </CheckBanner>
    );
  }
  if (shared.count === 0 && shared.pending_hashes === 0) return null;

  return (
    <CheckBanner
      label={t("payroll_checks.shared_title", "Accounts paid more than once")}
      icon={<Landmark className="h-4 w-4" />}
    >
      {shared.count > 0 && (
        <>
          <p className="font-medium">
            {t(
              "payroll_checks.shared_summary",
              ":count bank account(s) receive the pay of more than one employee.",
              { count: shared.count },
            )}
          </p>
          <p>
            {t(
              "payroll_checks.shared_hint",
              "Two people paid into one account can be a mistake in their bank details, or pay going to someone it should not. Check each before approving.",
            )}
          </p>
          <ShortList
            items={shared.groups}
            render={(group, i) => (
              <li key={i}>
                {group.employees.map((e, j) => (
                  <span key={e.employee_public_id ?? j}>
                    {j > 0 && ", "}
                    <PersonName person={e} />
                  </span>
                ))}
                {group.banks.length > 0 && (
                  <span>
                    {" ("}
                    {group.banks.join(", ")}
                    {")"}
                  </span>
                )}
              </li>
            )}
          />
        </>
      )}
      {shared.pending_hashes > 0 && (
        <p>
          {t(
            "payroll_checks.shared_pending",
            "The bank details of :count employee(s) are still being prepared for this check. Look again in an hour.",
            { count: shared.pending_hashes },
          )}
        </p>
      )}
    </CheckBanner>
  );
}

/** People paid in the run with no TIN on record. */
export function MissingTinBanner({ missing }: { missing: MissingTin }) {
  const { t } = useT();
  if (missing.count === 0) return null;

  return (
    <CheckBanner
      label={t("payroll_checks.tin_title", "Paid with no TIN")}
      icon={<Receipt className="h-4 w-4" />}
    >
      <p className="font-medium">
        {t(
          "payroll_checks.tin_summary",
          ":count employee(s) are paid in this run and have no TIN on record.",
          { count: missing.count },
        )}
      </p>
      <p>
        {t(
          "payroll_checks.tin_hint",
          "The monthly tax schedule needs each employee's TIN. Add it on their profile.",
        )}
      </p>
      <ShortList
        items={missing.employees}
        render={(e, i) => (
          <li key={e.employee_public_id ?? i}>
            <PersonName person={e} />
          </li>
        )}
      />
    </CheckBanner>
  );
}

/** People paid who were expected at work and have no attendance at all. */
export function NoAttendanceBanner({ none }: { none: NoAttendance }) {
  const { t } = useT();
  if (none.count === 0) return null;

  return (
    <CheckBanner
      label={t("payroll_checks.no_attendance_title", "Paid with no attendance")}
      icon={<UserX className="h-4 w-4" />}
    >
      <p className="font-medium">
        {t(
          "payroll_checks.no_attendance_summary",
          ":count employee(s) are paid in this run and have no attendance at all in the period.",
          { count: none.count },
        )}
      </p>
      <p>
        {t(
          "payroll_checks.no_attendance_hint",
          "They were expected at work and nobody recorded them. It may be work away from site, or someone who no longer works here. Check each before approving.",
        )}
      </p>
      <ShortList
        items={none.employees}
        render={(e, i) => (
          <li key={e.employee_public_id ?? i}>
            <PersonName person={e} />
            {": "}
            {t(
              "payroll_checks.no_attendance_employee",
              ":days expected workday(s)",
              { days: e.days },
            )}
          </li>
        )}
      />
    </CheckBanner>
  );
}
