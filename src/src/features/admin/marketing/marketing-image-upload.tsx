"use client";

import { useRef } from "react";
import { ImageUp, Loader2, Trash2 } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import {
  useRemoveMarketingImage,
  useUploadMarketingImage,
  type MarketingImageOwner,
} from "@/features/admin/api";
import { useUploadLimit } from "@/features/auth/api";
import { toastError } from "@/lib/errors";
import {
  overServerLimit,
  SERVER_LIMIT_FALLBACK,
  SERVER_LIMIT_KEY,
  shrinkImage,
  sizeLabel,
} from "@/lib/uploads/upload-limit";
import { useT } from "@/lib/i18n/useT";

const ACCEPTED = ["image/png", "image/jpeg", "image/webp"];
const MAX_BYTES = 5 * 1024 * 1024;

/**
 * A picture for a landing-page row or the hero: a preview, choose and
 * remove. The file is shrunk in the browser first to what the server keeps
 * anyway (a JPEG at most 1920 px), because the live host accepts 2 MB per
 * request; the server re-encodes it again and serves it at a URL that
 * changes with every upload.
 */
export function MarketingImageUpload({
  owner,
  url,
  title,
}: {
  owner: MarketingImageOwner;
  url: string | null | undefined;
  title: string;
}) {
  const { t } = useT();
  const input = useRef<HTMLInputElement>(null);
  const upload = useUploadMarketingImage();
  const remove = useRemoveMarketingImage();
  const busy = upload.isPending || remove.isPending;
  const serverLimit = useUploadLimit();

  async function choose(chosen: File | undefined) {
    if (!chosen) return;
    if (!ACCEPTED.includes(chosen.type)) {
      toast.error(
        t("admin_marketing.image_type", "Choose a PNG, JPG or WebP image."),
      );
      return;
    }
    const file = await shrinkImage(chosen, {
      maxEdge: 1920,
      type: "image/jpeg",
    });
    if (file.size > MAX_BYTES) {
      toast.error(
        t(
          "admin_marketing.image_too_large",
          "The image must be 5 MB or smaller.",
        ),
      );
      return;
    }
    const limit = overServerLimit(file, MAX_BYTES, serverLimit);
    if (limit !== null) {
      toast.error(
        t(SERVER_LIMIT_KEY, SERVER_LIMIT_FALLBACK, {
          size: sizeLabel(file.size),
          max: sizeLabel(limit),
        }),
      );
      return;
    }
    upload.mutate(
      { owner, file },
      {
        onSuccess: () =>
          toast.success(
            t("admin_marketing.image_uploaded", "Picture uploaded"),
          ),
        onError: (e) =>
          toastError(
            e,
            t(
              "admin_marketing.image_upload_failed",
              "Could not upload the picture",
            ),
          ),
      },
    );
  }

  return (
    <div className="rounded-lg border border-dashed p-4">
      <div className="flex flex-wrap items-center gap-4">
        <div className="flex h-16 w-24 shrink-0 items-center justify-center overflow-hidden rounded-lg border bg-muted">
          {url ? (
            /* eslint-disable-next-line @next/next/no-img-element */
            <img src={url} alt={title} className="h-full w-full object-cover" />
          ) : (
            <ImageUp
              className="h-6 w-6 text-muted-foreground"
              aria-hidden="true"
            />
          )}
        </div>
        <div className="min-w-0 flex-1">
          <p className="text-sm font-medium text-foreground">{title}</p>
          <p className="text-xs text-muted-foreground">
            {url
              ? t(
                  "admin_marketing.image_in_use",
                  "Shown on the page. Without one, the page draws its own scene.",
                )
              : t(
                  "admin_marketing.image_help",
                  "PNG, JPG or WebP, at least 640 by 360. Without one, the page draws its own scene.",
                )}
          </p>
        </div>
        <div className="flex gap-2">
          <input
            ref={input}
            type="file"
            accept={ACCEPTED.join(",")}
            className="sr-only"
            aria-label={t("admin_marketing.image_file", "Picture file")}
            onChange={(e) => {
              void choose(e.target.files?.[0]);
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
            {busy ? (
              <Loader2
                className="mr-1.5 h-3.5 w-3.5 animate-spin"
                aria-hidden="true"
              />
            ) : (
              <ImageUp className="mr-1.5 h-3.5 w-3.5" aria-hidden="true" />
            )}
            {url
              ? t("admin_marketing.image_replace", "Replace")
              : t("admin_marketing.image_choose", "Choose picture")}
          </Button>
          {url && (
            <Button
              type="button"
              variant="ghost"
              size="sm"
              disabled={busy}
              onClick={() =>
                remove.mutate(owner, {
                  onSuccess: () =>
                    toast.success(
                      t("admin_marketing.image_removed", "Picture removed"),
                    ),
                  onError: (e) =>
                    toastError(
                      e,
                      t(
                        "admin_marketing.image_remove_failed",
                        "Could not remove the picture",
                      ),
                    ),
                })
              }
            >
              <Trash2 className="mr-1.5 h-3.5 w-3.5" aria-hidden="true" />
              {t("admin_marketing.image_remove", "Remove")}
            </Button>
          )}
        </div>
      </div>
    </div>
  );
}
