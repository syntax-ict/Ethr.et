"use client";

import { useRef } from "react";
import { ImageUp, Loader2, Trash2 } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { useRemoveLogo, useUploadLogo } from "@/features/settings/api";
import { toastError } from "@/lib/errors";
import { useT } from "@/lib/i18n/useT";

const ACCEPTED = ["image/png", "image/jpeg", "image/webp"];
const MAX_BYTES = 1024 * 1024;

/**
 * Upload the organisation's own logo.
 *
 * The logo could only be an https link, and most organisations have nowhere to
 * host one, so their sign-in page could not carry their mark. The server
 * re-encodes what it receives (PNG, at most 512 px, metadata dropped) and
 * serves it at a URL that changes with every upload. An uploaded logo is used
 * instead of a link; removing it falls back to the link.
 */
export function LogoUpload({
  uploadedLogoUrl,
}: {
  uploadedLogoUrl: string | null | undefined;
}) {
  const { t } = useT();
  const input = useRef<HTMLInputElement>(null);
  const upload = useUploadLogo();
  const remove = useRemoveLogo();
  const busy = upload.isPending || remove.isPending;

  function choose(file: File | undefined) {
    if (!file) return;
    if (!ACCEPTED.includes(file.type)) {
      toast.error(t("settings.logo_type", "Choose a PNG, JPG or WebP image."));
      return;
    }
    if (file.size > MAX_BYTES) {
      toast.error(
        t("settings.logo_too_large", "The image must be 1 MB or smaller."),
      );
      return;
    }
    upload.mutate(file, {
      onSuccess: () =>
        toast.success(t("settings.logo_uploaded", "Logo uploaded")),
      onError: (e) =>
        toastError(
          e,
          t("settings.logo_upload_failed", "Could not upload the logo"),
        ),
    });
  }

  return (
    <div className="rounded-lg border border-dashed p-4">
      <div className="flex flex-wrap items-center gap-4">
        <div className="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-lg border bg-muted">
          {uploadedLogoUrl ? (
            /* eslint-disable-next-line @next/next/no-img-element */
            <img
              src={uploadedLogoUrl}
              alt={t("settings.uploaded_logo", "Uploaded logo")}
              className="h-full w-full object-contain"
            />
          ) : (
            <ImageUp
              className="h-6 w-6 text-muted-foreground"
              aria-hidden="true"
            />
          )}
        </div>
        <div className="min-w-[11rem] flex-1 basis-44 space-y-1">
          <p className="text-sm font-medium text-foreground">
            {uploadedLogoUrl
              ? t("settings.logo_in_use", "Your uploaded logo is in use")
              : t("settings.logo_upload_title", "Upload your logo")}
          </p>
          <p className="text-xs text-muted-foreground">
            {t(
              "settings.logo_upload_help",
              "PNG, JPG or WebP, up to 1 MB. A square or wide image works best; we resize it to 512 px.",
            )}
          </p>
        </div>
        <div className="flex gap-2">
          <input
            ref={input}
            type="file"
            accept={ACCEPTED.join(",")}
            className="sr-only"
            aria-label={t("settings.logo_file", "Logo image file")}
            onChange={(e) => {
              choose(e.target.files?.[0]);
              e.target.value = "";
            }}
          />
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={busy}
            onClick={() => input.current?.click()}
          >
            {upload.isPending ? (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            ) : (
              <ImageUp className="mr-2 h-4 w-4" />
            )}
            {uploadedLogoUrl
              ? t("settings.logo_replace", "Replace")
              : t("settings.logo_choose", "Choose image")}
          </Button>
          {uploadedLogoUrl && (
            <Button
              type="button"
              variant="ghost"
              size="sm"
              disabled={busy}
              onClick={() =>
                remove.mutate(undefined, {
                  onSuccess: () =>
                    toast.success(t("settings.logo_removed", "Logo removed")),
                  onError: (e) =>
                    toastError(e, t("common.error", "Something went wrong")),
                })
              }
            >
              <Trash2 className="mr-2 h-4 w-4" />
              {t("settings.logo_remove", "Remove")}
            </Button>
          )}
        </div>
      </div>
    </div>
  );
}
