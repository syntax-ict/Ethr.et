"use client";

import {
  BarChart3,
  Banknote,
  CalendarCheck,
  Fingerprint,
  Users,
} from "lucide-react";
import { useT } from "@/lib/i18n/useT";

const STEPS = [
  { key: "employee", icon: Users },
  { key: "attendance", icon: Fingerprint },
  { key: "leave", icon: CalendarCheck },
  { key: "payroll", icon: Banknote },
  { key: "intelligence", icon: BarChart3 },
] as const;

/**
 * What an organization gains from ETHR, told as a short vertical progression
 * rather than a static dashboard screenshot. Built entirely from the
 * existing `.stagger-children` / `animate-*` utilities in globals.css — no
 * new keyframes, no animation dependency. Those utilities are already
 * neutralised by the site-wide `prefers-reduced-motion: reduce` rule, so a
 * reduced-motion visitor gets the identical layout with the motion removed
 * rather than a special-cased second version.
 */
export function ProductStoryAnimation() {
  const { t } = useT();

  return (
    <div className="mt-10 w-full stagger-children">
      {STEPS.map((step, i) => {
        const Icon = step.icon;
        const isLast = i === STEPS.length - 1;
        return (
          <div key={step.key} className="flex gap-3">
            <div className="flex flex-col items-center">
              <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white/15 text-white ring-1 ring-white/25">
                <Icon className="h-4 w-4" aria-hidden="true" />
              </div>
              {!isLast && (
                <div
                  className="my-1 w-px flex-1 bg-white/20 animate-pulse-subtle"
                  aria-hidden="true"
                />
              )}
            </div>
            <div className={isLast ? "pb-0 text-left" : "pb-5 text-left"}>
              <p className="text-sm font-semibold text-white">
                {t(`auth.story_${step.key}_title`)}
              </p>
              <p className="text-xs text-white/60">
                {t(`auth.story_${step.key}_desc`)}
              </p>
            </div>
          </div>
        );
      })}
    </div>
  );
}
