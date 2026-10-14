"use client";

import { Loader2 } from "lucide-react";
import { useLandingAnnouncement } from "@/features/auth/landing-feed";
import type { TenantRequest } from "@/lib/auth/use-auth-host-context";
import { useT } from "@/lib/i18n/useT";
import { formatDate } from "@/lib/utils/date";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";

const TIME_ZONE = "Africa/Addis_Ababa";

/** One landing announcement in full, opened from its card. */
export function AnnouncementDialog({
  request,
  publicId,
  onClose,
}: {
  request: TenantRequest | null;
  publicId: string | null;
  onClose: () => void;
}) {
  const { t } = useT();
  const { data, isLoading, isError } = useLandingAnnouncement(
    request,
    publicId,
  );

  return (
    <Dialog
      open={publicId !== null}
      onOpenChange={(open) => !open && onClose()}
    >
      <DialogContent className="print-surface max-h-[85vh] overflow-y-auto sm:max-w-lg">
        {isLoading || !data ? (
          <div className="flex justify-center py-10">
            {isError ? (
              <p className="text-sm text-muted-foreground">
                {t(
                  "landing.announcement_gone",
                  "This announcement is no longer available.",
                )}
              </p>
            ) : (
              <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
            )}
            <DialogTitle className="sr-only">
              {t("landing.news", "Latest news")}
            </DialogTitle>
          </div>
        ) : (
          <>
            <DialogHeader>
              <DialogTitle>{data.title}</DialogTitle>
              {data.published_at && (
                <DialogDescription>
                  {formatDate(data.published_at, TIME_ZONE)}
                </DialogDescription>
              )}
            </DialogHeader>
            <p className="whitespace-pre-line text-sm text-foreground">
              {data.body}
            </p>
          </>
        )}
      </DialogContent>
    </Dialog>
  );
}
