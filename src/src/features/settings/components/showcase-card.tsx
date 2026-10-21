"use client";

import { Globe2 } from "lucide-react";
import { toast } from "sonner";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { Switch } from "@/components/ui/switch";
import { Label } from "@/components/ui/label";
import { useT } from "@/lib/i18n/useT";
import { useCalendar } from "@/lib/calendar/calendar-context";
import {
  useShowcaseConsent,
  useUpdateShowcaseConsent,
} from "@/features/settings/api";
import { apiErrorMessage } from "@/lib/api/error-message";
import { ErrorState } from "@/components/shared/error-state";

/**
 * Whether ETHR may name this organisation on its public site, under "Trusted
 * by": its name, its logo and a link to its public page, nothing else. Off
 * until an administrator turns it on; who agreed and when is recorded.
 */
export function ShowcaseCard() {
  const { t } = useT();
  const { formatDate } = useCalendar();
  const { data, isLoading, isError, refetch } = useShowcaseConsent();
  const update = useUpdateShowcaseConsent();

  function toggle(listed: boolean) {
    update.mutate(listed, {
      onSuccess: () =>
        toast.success(
          listed
            ? t(
                "settings.showcase.agreed",
                "Thank you. Your organisation can now appear on the ETHR website.",
              )
            : t(
                "settings.showcase.withdrawn",
                "Your organisation will no longer appear on the ETHR website.",
              ),
        ),
      onError: (err) =>
        toast.error(
          apiErrorMessage(
            err,
            t("settings.showcase.failed", "Your choice could not be saved."),
          ),
        ),
    });
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <Globe2 className="h-4 w-4" aria-hidden />
          {t("settings.showcase.title", "ETHR website")}
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-4">
        {isError ? (
          <ErrorState compact onRetry={() => refetch()} />
        ) : isLoading || !data ? (
          <Skeleton className="h-16 w-full" />
        ) : (
          <>
            <div className="flex items-start justify-between gap-4">
              <div className="space-y-1">
                <Label htmlFor="showcase-listed">
                  {t(
                    "settings.showcase.label",
                    "Show our organisation on the ETHR website",
                  )}
                </Label>
                <p className="text-sm text-muted-foreground">
                  {t(
                    "settings.showcase.description",
                    "Under Trusted by on ethr.et: your organisation's name, its logo and a link to its public page. Nothing else is shown, and you can turn it off at any time.",
                  )}
                </p>
              </div>
              <Switch
                id="showcase-listed"
                checked={data.listed}
                disabled={update.isPending}
                onCheckedChange={toggle}
              />
            </div>
            {data.listed && data.consented_at && (
              <p className="text-xs text-muted-foreground">
                {t("settings.showcase.since", "Agreed on :date.", {
                  date: formatDate(new Date(data.consented_at)),
                })}{" "}
                {data.shown
                  ? t("settings.showcase.shown", "It is shown now.")
                  : !data.active
                    ? t(
                        "settings.showcase.not_active",
                        "It is shown while your trial or subscription is active.",
                      )
                    : t(
                        "settings.showcase.hidden",
                        "ETHR is not showing it at the moment.",
                      )}
              </p>
            )}
          </>
        )}
      </CardContent>
    </Card>
  );
}
