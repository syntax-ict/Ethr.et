"use client";

import { Loader2, ShieldCheck } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { useT } from "@/lib/i18n/useT";

/**
 * What an employee agrees to before the phone takes an attendance selfie.
 *
 * A photo of a face is biometric, and so sensitive, personal data under the
 * Personal Data Protection Proclamation No. 1321/2024 (Art. 2(5), 2(6)), and
 * sensitive data is processed with consent specific to the purpose, given
 * before processing. So the notice says what is taken, why, who sees it,
 * where it is kept, how to withdraw, and what to do instead. The server's
 * PhotoConsent::VERSION names this text: a material change to it gets a new
 * version, and everyone is asked again.
 */
export function PhotoConsentPoints() {
  const { t } = useT();
  const points = [
    t(
      "attendance.photo_consent.what",
      "What: one photo each time you check in or out with your phone.",
    ),
    t(
      "attendance.photo_consent.why",
      "Why: to confirm the person checking in is you. It is used for nothing else.",
    ),
    t(
      "attendance.photo_consent.who",
      "Who sees it: your organisation's HR and the managers who review your attendance.",
    ),
    t(
      "attendance.photo_consent.where",
      "Where it is kept: with your attendance record, on servers in Ethiopia.",
    ),
    t(
      "attendance.photo_consent.withdraw",
      "Your choice: withdraw at any time under Profile, Preferences. Photos already taken stay with their records; ask HR to erase them.",
    ),
    t(
      "attendance.photo_consent.instead",
      "If you do not agree: check in at a kiosk or with a QR code, where your organisation offers them.",
    ),
  ];

  return (
    <ul className="space-y-2 text-sm leading-relaxed text-foreground">
      {points.map((point) => (
        <li key={point} className="flex gap-2">
          <ShieldCheck
            className="mt-0.5 h-4 w-4 shrink-0 text-primary"
            aria-hidden="true"
          />
          <span>{point}</span>
        </li>
      ))}
    </ul>
  );
}

/** The notice as a question, asked before the camera opens. */
export function PhotoConsentDialog({
  open,
  onOpenChange,
  onAgree,
  agreeing,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onAgree: () => void;
  agreeing: boolean;
}) {
  const { t } = useT();

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>
            {t("attendance.photo_consent.title", "Attendance photo")}
          </DialogTitle>
          <DialogDescription>
            {t(
              "attendance.photo_consent.intro",
              "Your organisation asks for a selfie when you check in on your phone. A photo of your face is sensitive personal data under the Personal Data Protection Proclamation No. 1321/2024, so we ask before taking one.",
            )}
          </DialogDescription>
        </DialogHeader>
        <PhotoConsentPoints />
        <DialogFooter className="gap-2 sm:gap-0">
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
          >
            {t("attendance.photo_consent.not_now", "Not now")}
          </Button>
          <Button type="button" onClick={onAgree} disabled={agreeing}>
            {agreeing && (
              <Loader2
                className="mr-2 h-4 w-4 animate-spin"
                aria-hidden="true"
              />
            )}
            {t("attendance.photo_consent.agree", "I agree")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
