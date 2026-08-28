"use client";

import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";

/**
 * Password strength indicator (PHASE_00 S03 — "on registration and password
 * change"). It previously existed only on the reset page.
 *
 * Deliberately dependency-free rather than pulling in zxcvbn: the score is
 * *advisory feedback*, while the actual gate is the server-side `PasswordPolicy`
 * rule. Shipping a 400 KB dictionary to the browser to duplicate a check the API
 * already performs is not a trade worth making on Ethiopian mobile connections.
 */
export interface PasswordStrength {
  score: 0 | 1 | 2 | 3 | 4;
  labelKey: string;
}

export function scorePassword(password: string): PasswordStrength {
  if (password.length === 0)
    return { score: 0, labelKey: "password.strength_none" };

  let score = 0;
  if (password.length >= 8) score++;
  if (password.length >= 12) score++;
  if (/\p{Lu}/u.test(password) && /\p{Ll}/u.test(password)) score++;
  if (/\d/.test(password)) score++;
  if (/[^\p{L}\p{N}]/u.test(password)) score++;

  // A long password of one repeated character scores well on length alone;
  // collapse it so the meter cannot flatter something trivially guessable.
  if (new Set(password).size <= 3) score = Math.min(score, 1);

  const clamped = Math.min(4, Math.max(1, score)) as 1 | 2 | 3 | 4;

  return {
    score: clamped,
    labelKey: [
      "password.strength_weak",
      "password.strength_fair",
      "password.strength_good",
      "password.strength_strong",
    ][clamped - 1],
  };
}

const BAR_CLASS: Record<number, string> = {
  1: "bg-status-error",
  2: "bg-status-warning",
  3: "bg-status-info",
  4: "bg-status-success",
};

const TEXT_CLASS: Record<number, string> = {
  1: "text-status-error",
  2: "text-status-warning",
  3: "text-status-info",
  4: "text-status-success",
};

export function PasswordStrengthMeter({
  password,
  className,
}: {
  password: string;
  className?: string;
}) {
  const { t } = useT();
  const { score, labelKey } = scorePassword(password);

  if (password.length === 0) return null;

  return (
    <div className={cn("space-y-1.5", className)}>
      <div
        className="flex gap-1"
        role="meter"
        aria-valuenow={score}
        aria-valuemin={1}
        aria-valuemax={4}
        aria-label={t("password.strength_label", "Password strength")}
      >
        {[1, 2, 3, 4].map((step) => (
          <span
            key={step}
            className={cn(
              "h-1 flex-1 rounded-full transition-colors",
              step <= score ? BAR_CLASS[score] : "bg-border-default",
            )}
          />
        ))}
      </div>
      {/* Announced politely so a screen-reader user gets the same feedback the
          bar conveys visually, without interrupting typing. */}
      <p
        className={cn("text-xs font-medium", TEXT_CLASS[score])}
        aria-live="polite"
      >
        {t(labelKey)}
      </p>
    </div>
  );
}
