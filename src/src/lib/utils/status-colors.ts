/**
 * Kept as the import path existing screens use. The table lives in
 * `lib/status/registry.ts`, which `StatusBadge` reads too, so the two can no
 * longer disagree about a status (FRONTEND_REDESIGN_PLAN.md, Phase 2).
 */
import {
  statusToneOf,
  toneClasses,
  type StatusTone,
  type ToneClasses,
} from "@/lib/status/registry";

export type SemanticTone = StatusTone;

export function statusTone(tone: SemanticTone): ToneClasses {
  return toneClasses(tone);
}

export function statusToSemanticTone(status: string): SemanticTone {
  return statusToneOf(status);
}

export function statusBadgeClass(status: string): string {
  return `${toneClasses(statusToneOf(status)).badge} border-0`;
}
