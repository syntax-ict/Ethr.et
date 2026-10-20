"use client";

import { Camera, Loader2 } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { PhotoConsentPoints } from "@/features/attendance/components/photo-consent-notice";
import {
  useGivePhotoConsent,
  usePhotoConsent,
  useWithdrawPhotoConsent,
} from "@/features/profile/api";
import { useCalendar } from "@/lib/calendar/calendar-context";
import { useT } from "@/lib/i18n/useT";
import { apiErrorMessage } from "@/lib/api/error-message";

/**
 * The employee's own answer to the attendance photo notice: read the notice,
 * agree, or withdraw. Withdrawing stops new selfies from then on.
 */
export function PhotoConsentCard() {
  const { t, locale } = useT();
  // The date in the employee's own calendar, as every other date in the app.
  const { formatDate } = useCalendar();
  const consent = usePhotoConsent();
  const give = useGivePhotoConsent();
  const withdraw = useWithdrawPhotoConsent();
  const given = consent.data?.given ?? false;
  const busy = give.isPending || withdraw.isPending;

  function change(next: boolean) {
    (next ? give : withdraw).mutate(undefined, {
      onSuccess: () =>
        toast.success(
          next
            ? t(
                "profile.photo_consent_given",
                "You agreed to the attendance photo.",
              )
            : t(
                "profile.photo_consent_withdrawn",
                "Withdrawn. No new attendance photo will be taken.",
              ),
        ),
      onError: (err) =>
        toast.error(
          apiErrorMessage(
            err,
            t("common.action_failed", "That didn't work. Try again."),
          ),
        ),
    });
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <Camera className="h-4 w-4 text-muted-foreground" />
          {t("attendance.photo_consent.title", "Attendance photo")}
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-4">
        <p className="text-sm text-foreground">
          {given && consent.data?.given_at
            ? t("profile.photo_consent_status_given", "You agreed on :date.", {
                date: formatDate(new Date(consent.data.given_at), locale),
              })
            : t(
                "profile.photo_consent_status_none",
                "You have not agreed. No selfie is taken when you check in on your phone.",
              )}
        </p>
        <details className="rounded-lg border border-border-default px-4 py-3">
          <summary className="cursor-pointer text-sm font-medium text-foreground">
            {t("profile.photo_consent_read", "Read the notice")}
          </summary>
          <div className="mt-3">
            <PhotoConsentPoints />
          </div>
        </details>
        <Button
          type="button"
          variant={given ? "outline" : "default"}
          disabled={busy || consent.isLoading}
          onClick={() => change(!given)}
        >
          {busy && (
            <Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden="true" />
          )}
          {given
            ? t("profile.photo_consent_withdraw", "Withdraw consent")
            : t("attendance.photo_consent.agree", "I agree")}
        </Button>
      </CardContent>
    </Card>
  );
}
