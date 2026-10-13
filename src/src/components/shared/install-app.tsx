"use client";

import { useState } from "react";
import { Download, Share, SquarePlus, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { DropdownMenuItem } from "@/components/ui/dropdown-menu";
import { promptInstall, useInstallPrompt } from "@/lib/hooks/useInstallPrompt";
import { useT } from "@/lib/i18n/useT";

const DISMISS_KEY = "ethr:install-banner-dismissed";

function readDismissed(): boolean {
  try {
    return window.localStorage.getItem(DISMISS_KEY) === "1";
  } catch {
    return false;
  }
}

/** The steps for Safari, which has no install prompt of its own. */
function IosInstallDialog({
  open,
  onOpenChange,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { t } = useT();

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-sm">
        <DialogHeader>
          <DialogTitle>{t("pwa.install_title", "Install ETHR")}</DialogTitle>
          <DialogDescription>
            {t(
              "pwa.ios_intro",
              "Add ETHR to your Home Screen to open it like an app and check in with one tap.",
            )}
          </DialogDescription>
        </DialogHeader>
        <ol className="space-y-3 text-sm">
          <li className="flex items-center gap-3">
            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-primary-soft text-primary-on-soft">
              <Share className="h-4 w-4" aria-hidden="true" />
            </span>
            {t("pwa.ios_step_share", "Tap the Share button in Safari.")}
          </li>
          <li className="flex items-center gap-3">
            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-primary-soft text-primary-on-soft">
              <SquarePlus className="h-4 w-4" aria-hidden="true" />
            </span>
            {t("pwa.ios_step_add", "Choose “Add to Home Screen”, then Add.")}
          </li>
        </ol>
      </DialogContent>
    </Dialog>
  );
}

/** "Install app" in the account menu, when installing is possible. */
export function InstallAppMenuItem() {
  const { t } = useT();
  const { canPrompt, installed, isIos } = useInstallPrompt();
  const [iosOpen, setIosOpen] = useState(false);

  if (installed || (!canPrompt && !isIos)) return null;

  return (
    <>
      <DropdownMenuItem
        className="cursor-pointer gap-2"
        onSelect={(event) => {
          if (isIos) {
            event.preventDefault();
            setIosOpen(true);
          } else {
            void promptInstall();
          }
        }}
      >
        <Download className="h-4 w-4 text-muted-foreground" />
        {t("pwa.install_app", "Install app")}
      </DropdownMenuItem>
      <IosInstallDialog open={iosOpen} onOpenChange={setIosOpen} />
    </>
  );
}

/**
 * A one-time banner on phones: punching in is the reason most employees open
 * ETHR, and an installed app opens straight to it from the home screen.
 * Dismissal is remembered on this device.
 */
export function InstallAppBanner() {
  const { t } = useT();
  const { canPrompt, installed, isIos } = useInstallPrompt();
  const [dismissed, setDismissed] = useState(readDismissed);
  const [iosOpen, setIosOpen] = useState(false);

  if (installed || dismissed || (!canPrompt && !isIos)) return null;

  function dismiss() {
    setDismissed(true);
    try {
      window.localStorage.setItem(DISMISS_KEY, "1");
    } catch {
      // Private browsing: the banner simply returns next visit.
    }
  }

  return (
    <div className="flex items-center gap-3 rounded-xl border border-border bg-card p-3 shadow-xs lg:hidden">
      <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground">
        <Download className="h-5 w-5" aria-hidden="true" />
      </span>
      <div className="min-w-0 flex-1">
        <p className="text-sm font-medium text-foreground">
          {t("pwa.install_title", "Install ETHR")}
        </p>
        <p className="text-xs text-muted-foreground">
          {t(
            "pwa.install_benefit",
            "Open it from your home screen and check in with one tap.",
          )}
        </p>
      </div>
      <Button
        size="sm"
        onClick={() => (isIos ? setIosOpen(true) : void promptInstall())}
      >
        {t("pwa.install", "Install")}
      </Button>
      <Button
        variant="ghost"
        size="icon"
        className="h-8 w-8 shrink-0"
        onClick={dismiss}
        aria-label={t("common.dismiss", "Dismiss")}
      >
        <X className="h-4 w-4" />
      </Button>
      <IosInstallDialog open={iosOpen} onOpenChange={setIosOpen} />
    </div>
  );
}
