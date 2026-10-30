"use client";

import { PauseCircle } from "lucide-react";

import {
  FEATURE_LABEL_FALLBACK,
  featureLabelKey,
  type FeatureKey,
} from "@/features/admin/features";
import { usePausedFeatures } from "@/features/auth/api";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";

/**
 * Tells an organisation's admins what the platform has switched off for now
 * (owner decision D2: a banner, no email, because a switch may be off for ten
 * minutes). Only what the organisation would otherwise have: a feature its
 * plan never included is not news. Employees see the refusal where they meet
 * it, not a banner about the platform.
 */
export function PausedFeaturesBanner() {
  const { t } = useT();
  const { isHrAdmin } = usePermissions();
  const paused = usePausedFeatures();

  if (!isHrAdmin || paused.length === 0) return null;

  const names = paused
    .map((key) =>
      t(
        featureLabelKey(key as FeatureKey),
        FEATURE_LABEL_FALLBACK[key as FeatureKey] ?? key,
      ),
    )
    .join(", ");

  return (
    <div
      role="status"
      className="flex items-center justify-center gap-2 bg-warning-soft px-4 py-1.5 text-xs font-medium text-warning-on-soft"
    >
      <PauseCircle className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
      <span>
        {t(
          "features.paused_banner",
          "ETHR has switched off :features for now. Your records are kept, and nothing needs doing on your side.",
          { features: names },
        )}
      </span>
    </div>
  );
}
