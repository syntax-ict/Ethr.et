import type { LucideIcon } from "lucide-react";
import {
  type NavContext,
  phrasesKey,
  QUICK_ACTIONS,
  resolveHref,
  visibleDestinations,
} from "./registry";
import { intentScore, splitPhrases } from "./intent-match";

/**
 * The palette's task and page rows, built from the registry for one person.
 *
 * The command palette draws these and `intent-goals.test.ts` ranks them, so
 * what the test says a goal opens is what the palette offers for it. Pure:
 * the caller passes its translator.
 */

export type Translate = (key: string, fallback?: string) => string;

export interface IntentRow {
  /** cmdk value: unique, never shown. `action:<id>` or `nav:<id>`. */
  value: string;
  kind: "action" | "nav";
  id: string;
  href: string;
  label: string;
  /** The group a child destination belongs to ("Attendance" for "Records"). */
  context?: string;
  /** A quick action's sentence on what opening it does. */
  description?: string;
  /** What the row answers to: its label and the phrases people use for it. */
  phrases: string[];
  icon: LucideIcon;
}

export function actionRows(ctx: NavContext, t: Translate): IntentRow[] {
  return QUICK_ACTIONS.filter((a) => a.show(ctx)).map((a) => {
    const label = t(a.labelKey, a.labelFallback);
    return {
      value: `action:${a.id}`,
      kind: "action",
      id: a.id,
      href: resolveHref(a.href, ctx),
      label,
      description: t(a.descKey, a.descFallback),
      phrases: [label, ...splitPhrases(t(phrasesKey(a.id), ""))],
      icon: a.icon,
    };
  });
}

export function pageRows(ctx: NavContext, t: Translate): IntentRow[] {
  return visibleDestinations(ctx).map((d) => {
    const label = t(d.labelKey, d.labelFallback);
    const context = d.context
      ? t(d.context.labelKey, d.context.labelFallback)
      : undefined;
    return {
      value: `nav:${d.id}`,
      kind: "nav",
      id: d.id,
      href: resolveHref(d.href, ctx),
      label,
      context,
      phrases: [
        context ? `${context} ${label}` : label,
        label,
        ...splitPhrases(t(phrasesKey(d.id), "")),
      ],
      icon: d.icon,
    };
  });
}

/**
 * Every row that answers `query`, best first, by the score the palette's
 * filter gives it. Ties keep the registry's order; cmdk may draw an equal
 * page above an equal task, so a test should judge a goal by where its best
 * rows lead rather than by which of them comes first.
 */
export function rankIntents(
  query: string,
  rows: readonly IntentRow[],
): { row: IntentRow; score: number }[] {
  return rows
    .map((row, index) => ({
      row,
      index,
      score: intentScore(query, row.phrases),
    }))
    .filter((r) => r.score > 0)
    .sort((a, b) => b.score - a.score || a.index - b.index)
    .map(({ row, score }) => ({ row, score }));
}
