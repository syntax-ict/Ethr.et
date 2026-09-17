"use client";

import { Fingerprint, WifiOff, Check, Wallet } from "lucide-react";
import { useT } from "@/lib/i18n/useT";

/**
 * The hero illustration: what ETHR actually does, in one glance.
 *
 * Three beats — an employee checks in, the record survives no network and
 * uploads itself, payroll computes under Ethiopian rules. That sequence is the
 * product's whole differentiator, and the landing page previously asked a
 * visitor to take it on faith from body copy alone.
 *
 * Built as HTML rather than one inline SVG, deliberately:
 *
 *  - every label is real text, so it translates through `useT` (the server
 *    renders Amharic by default) and is selectable, zoomable and reachable by a
 *    screen reader. Text baked into an SVG is none of those things.
 *  - it reflows. An 880-wide SVG storyboard scaled into a 360px phone viewport
 *    renders labels at ~5px; a flex/grid column stacks instead.
 *  - the decorative glyphs stay `aria-hidden`, so assistive tech gets the
 *    three-item list and none of the scaffolding.
 *
 * MOTION CONTRACT — the part that is easy to get wrong here. `globals.css`
 * applies `animation-duration: 0.01ms` and `animation-iteration-count: 1` under
 * `prefers-reduced-motion`, which snaps every animation to its FINAL keyframe.
 * So every keyframe below ends in the completed state and the sequence plays
 * once and settles, rather than looping back to empty. That also rules out a
 * looping hero, which reads as restless on an enterprise page and competes
 * with the call to action.
 *
 * The global block is NOT sufficient on its own, which a screenshot caught and
 * reading did not: it collapses duration but leaves `animation-delay` intact,
 * and this sequence is built almost entirely out of delays. With
 * `fill-mode: both` an element holds its `from` state — opacity 0 — for the
 * whole delay, so a reduced-motion visitor saw step 1, then watched steps 2 and
 * 3 pop in over the next three seconds. `globals.css` therefore zeroes
 * `animation-delay` inside `.ethr-flow` under the same query. Only with that
 * does the diagram actually arrive complete and at once.
 *
 * Meaning lives in the text, never in the motion: the offline→synced pill
 * animates, but step two's description states the same fact, so nothing is lost
 * when the animation is suppressed.
 */
export function ProductFlow() {
  const { t } = useT();

  return (
    <div className="ethr-flow mx-auto w-full max-w-4xl">
      <ol className="flex flex-col items-stretch gap-3 sm:flex-row sm:items-stretch sm:gap-0">
        {/* 1 — Check in */}
        <li className="ethr-flow-step ethr-flow-step-1 flex-1 rounded-2xl border border-border/60 bg-card p-5 text-start shadow-sm">
          <div className="flex items-center gap-3">
            <span
              className="relative flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary/10"
              aria-hidden="true"
            >
              <span className="ethr-flow-ripple absolute inset-0 rounded-xl border-2 border-primary/40" />
              <Fingerprint className="h-5 w-5 text-primary" />
            </span>
            <span
              className="ethr-flow-chip rounded-lg border border-border/60 bg-muted/60 px-2.5 py-1 font-mono text-sm font-medium tabular-nums text-foreground"
              aria-hidden="true"
            >
              08:02
            </span>
          </div>

          <h2 className="mt-4 text-sm font-semibold text-foreground">
            {t("marketing.flow.step_checkin", "Check in, anywhere")}
          </h2>
          <p className="mt-1.5 text-sm leading-relaxed text-muted-foreground">
            {t(
              "marketing.flow.step_checkin_desc",
              "Fingerprint, QR or shared kiosk — on the factory floor, with no network.",
            )}
          </p>
        </li>

        <Connector index={1} />

        {/* 2 — Queue, then sync */}
        <li className="ethr-flow-step ethr-flow-step-2 flex-1 rounded-2xl border border-border/60 bg-card p-5 text-start shadow-sm">
          <div className="flex items-center justify-between gap-3">
            <div className="flex flex-col gap-1" aria-hidden="true">
              {[0, 1, 2].map((i) => (
                <span
                  key={i}
                  className={`ethr-flow-record ethr-flow-record-${i + 1} h-2 rounded-full bg-primary/25`}
                  style={{ width: `${44 - i * 8}px` }}
                />
              ))}
            </div>

            {/* Both pills occupy one cell; offline fades out as synced fades in.
                Under reduced motion both snap to their end state, leaving
                "Synced" — which is why the description below states the offline
                behaviour in words rather than relying on the pill. */}
            <span className="relative inline-grid" aria-hidden="true">
              <span className="ethr-flow-pill-offline col-start-1 row-start-1 inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted px-2.5 py-1 text-xs font-medium text-muted-foreground">
                <WifiOff className="h-3 w-3" />
                {t("attendance.source_offline", "Offline")}
              </span>
              <span className="ethr-flow-pill-synced col-start-1 row-start-1 inline-flex items-center gap-1.5 rounded-full border border-status-success/30 bg-status-success/10 px-2.5 py-1 text-xs font-medium text-status-success">
                <Check className="h-3 w-3" />
                {t("attendance.mobile_page.synced", "Synced")}
              </span>
            </span>
          </div>

          <h2 className="mt-4 text-sm font-semibold text-foreground">
            {t("marketing.flow.step_sync", "Keeps working offline")}
          </h2>
          <p className="mt-1.5 text-sm leading-relaxed text-muted-foreground">
            {t(
              "marketing.flow.step_sync_desc",
              "Records queue on the device and upload themselves the moment the connection returns.",
            )}
          </p>
        </li>

        <Connector index={2} />

        {/* 3 — Payroll */}
        <li className="ethr-flow-step ethr-flow-step-3 flex-1 rounded-2xl border border-border/60 bg-card p-5 text-start shadow-sm">
          <div className="flex items-center gap-3">
            {/* `bg-brand-soft text-brand-on-soft` is the established brand
                pairing (three call sites in the dashboard). Both halves are
                redefined per theme, so it stays AA in light, dark and
                high-contrast — which a raw `--brand-accent` tint would not:
                the gold is 2.08:1 on white and can never carry a foreground. */}
            <span
              className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-soft"
              aria-hidden="true"
            >
              <Wallet className="h-5 w-5 text-brand-on-soft" />
            </span>
            {/* A schematic, not a sample payslip: proportional bars carry
                "gross minus tax leaves net" without inventing figures. */}
            <div className="flex-1 space-y-1.5" aria-hidden="true">
              <PayrollRow
                label={t("payroll.gross", "Gross")}
                width="100%"
                tone="bg-primary/30"
                order={1}
              />
              <PayrollRow
                label={t("payroll_page.payslips_page.income_tax", "Income Tax")}
                width="34%"
                tone="bg-status-warning/40"
                order={2}
              />
              <PayrollRow
                label={t("payroll.net", "Net")}
                width="66%"
                tone="bg-status-success/45"
                order={3}
              />
            </div>
          </div>

          <h2 className="mt-4 text-sm font-semibold text-foreground">
            {t("marketing.flow.step_payroll", "Payroll under Ethiopian law")}
          </h2>
          <p className="mt-1.5 text-sm leading-relaxed text-muted-foreground">
            {t(
              "marketing.flow.step_payroll_desc",
              "Income tax and pension applied automatically, on the Ethiopian calendar.",
            )}
          </p>
        </li>
      </ol>
    </div>
  );
}

function PayrollRow({
  label,
  width,
  tone,
  order,
}: {
  label: string;
  width: string;
  tone: string;
  order: 1 | 2 | 3;
}) {
  return (
    <div className="flex items-center gap-2">
      {/* `text-foreground`, not `text-muted-foreground`: at 10px the muted
          token measured 4.3:1 against white and small text needs 4.5:1.
          Moving the token itself would repaint every muted label in the
          product, so only this one changes. */}
      <span className="w-20 shrink-0 truncate text-[10px] font-medium uppercase tracking-wide text-foreground">
        {label}
      </span>
      <span className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
        <span
          className={`ethr-flow-bar ethr-flow-bar-${order} block h-full rounded-full ${tone}`}
          style={{ width }}
        />
      </span>
    </div>
  );
}

/**
 * Rotated a quarter turn on mobile so the same element reads as a downward step
 * in a stacked column and a rightward step in a row, without a second markup
 * path to keep in sync.
 */
function Connector({ index }: { index: 1 | 2 }) {
  return (
    <li
      className="ethr-flow-connector relative mx-auto h-6 w-px shrink-0 self-center sm:mx-0 sm:h-px sm:w-10 sm:self-start sm:mt-16"
      aria-hidden="true"
    >
      <span
        className={`ethr-flow-line ethr-flow-line-${index} absolute inset-0 bg-border`}
      />
      <span
        className={`ethr-flow-dot ethr-flow-dot-${index} absolute left-1/2 top-0 h-1.5 w-1.5 -translate-x-1/2 rounded-full bg-primary sm:left-0 sm:top-1/2 sm:-translate-y-1/2 sm:translate-x-0`}
      />
    </li>
  );
}
