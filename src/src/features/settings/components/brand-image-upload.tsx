"use client";

import { useRef, type ReactNode } from "react";
import { ImageUp, Loader2, Trash2 } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import {
  useRemoveBrandAsset,
  useUpdateCoverFocal,
  useUploadBrandAsset,
  type BrandAssetKind,
  type BrandAssets,
} from "@/features/settings/api";
import { coverPosition } from "@/lib/auth/landing-background";
import { toastError } from "@/lib/errors";
import { useUploadLimit } from "@/features/auth/api";
import {
  overServerLimit,
  SERVER_LIMIT_FALLBACK,
  SERVER_LIMIT_KEY,
  shrinkImage,
  sizeLabel,
} from "@/lib/uploads/upload-limit";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";

const ACCEPTED = ["image/png", "image/jpeg", "image/webp"];
const MAX_BYTES = 5 * 1024 * 1024;

/**
 * Upload one of the organisation's other brand images, beside the logo
 * `LogoUpload` keeps: the same shape (a preview, a title, a help line, choose
 * and remove), with the preview surface and the words set per kind by the
 * card that places it. The server re-encodes what it receives and serves it
 * at a URL that changes with every upload.
 */
export function BrandImageUpload({
  kind,
  url,
  title,
  inUse,
  help,
  fileLabel,
  previewClassName,
  preview,
}: {
  kind: BrandAssetKind;
  url: string | null | undefined;
  title: string;
  inUse: string;
  help: string;
  fileLabel: string;
  previewClassName?: string;
  /** What to show in the preview box once an image exists; the image by default. */
  preview?: ReactNode;
}) {
  const { t } = useT();
  const input = useRef<HTMLInputElement>(null);
  const upload = useUploadBrandAsset(kind);
  const remove = useRemoveBrandAsset(kind);
  const busy = upload.isPending || remove.isPending;
  const serverLimit = useUploadLimit();

  async function choose(chosen: File | undefined) {
    if (!chosen) return;
    if (!ACCEPTED.includes(chosen.type)) {
      toast.error(
        t("settings.brand_image_type", "Choose a PNG, JPG or WebP image."),
      );
      return;
    }
    // Shrunk in the browser first, to what the server keeps anyway: a cover at
    // 1920 px as JPEG, the logo and mark at 1024 px as PNG (transparency kept).
    const file = await shrinkImage(
      chosen,
      kind === "cover"
        ? { maxEdge: 1920, type: "image/jpeg" }
        : { maxEdge: 1024, type: "image/png" },
    );
    if (file.size > MAX_BYTES) {
      toast.error(
        t(
          "settings.brand_image_too_large",
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
    upload.mutate(file, {
      onSuccess: () =>
        toast.success(t("settings.brand_image_uploaded", "Image uploaded")),
      onError: (e) =>
        toastError(
          e,
          t("settings.brand_image_upload_failed", "Could not upload the image"),
        ),
    });
  }

  return (
    <div className="rounded-lg border border-dashed p-4">
      <div className="flex flex-wrap items-center gap-4">
        <div
          className={cn(
            "flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-lg border bg-muted",
            previewClassName,
          )}
        >
          {url ? (
            (preview ?? (
              /* eslint-disable-next-line @next/next/no-img-element */
              <img
                src={url}
                alt={title}
                className="h-full w-full object-contain"
              />
            ))
          ) : (
            <ImageUp
              className="h-6 w-6 text-muted-foreground"
              aria-hidden="true"
            />
          )}
        </div>
        <div className="min-w-[11rem] flex-1 basis-44 space-y-1">
          <p className="text-sm font-medium text-foreground">
            {url ? inUse : title}
          </p>
          <p className="text-xs text-muted-foreground">{help}</p>
        </div>
        <div className="flex gap-2">
          <input
            ref={input}
            type="file"
            accept={ACCEPTED.join(",")}
            className="sr-only"
            aria-label={fileLabel}
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
            {upload.isPending ? (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            ) : (
              <ImageUp className="mr-2 h-4 w-4" />
            )}
            {url
              ? t("settings.logo_replace", "Replace")
              : t("settings.logo_choose", "Choose image")}
          </Button>
          {url && (
            <Button
              type="button"
              variant="ghost"
              size="sm"
              disabled={busy}
              onClick={() =>
                remove.mutate(undefined, {
                  onSuccess: () =>
                    toast.success(
                      t("settings.brand_image_removed", "Image removed"),
                    ),
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

/**
 * The cover photo with its focal point: a click on the photo marks the part
 * to keep in frame, and the page's hero crops around it at every width.
 */
export function CoverUpload({ cover }: { cover: BrandAssets["cover"] }) {
  const { t } = useT();
  const focal = useUpdateCoverFocal();

  function place(e: React.MouseEvent<HTMLButtonElement>) {
    const rect = e.currentTarget.getBoundingClientRect();
    if (rect.width === 0 || rect.height === 0) return;
    const x = Math.min(1, Math.max(0, (e.clientX - rect.left) / rect.width));
    const y = Math.min(1, Math.max(0, (e.clientY - rect.top) / rect.height));
    focal.mutate(
      { x: Number(x.toFixed(3)), y: Number(y.toFixed(3)) },
      {
        onSuccess: () =>
          toast.success(t("settings.cover_focal_saved", "Focal point saved")),
        onError: (err) =>
          toastError(err, t("common.error", "Something went wrong")),
      },
    );
  }

  return (
    <div className="space-y-3">
      <BrandImageUpload
        kind="cover"
        url={cover?.url}
        title={t("settings.cover_title", "Cover photo")}
        inUse={t("settings.cover_in_use", "Your cover photo is in use")}
        help={t(
          "settings.cover_help",
          "A wide photo of your building or your team, at least 960 px wide and up to 5 MB. It is darkened so the text stays readable.",
        )}
        fileLabel={t("settings.cover_file", "Cover photo file")}
        previewClassName="w-28"
        preview={
          cover?.url ? (
            /* eslint-disable-next-line @next/next/no-img-element */
            <img
              src={cover.url}
              alt={t("settings.cover_title", "Cover photo")}
              className="h-full w-full object-cover"
              style={{ objectPosition: coverPosition(cover) }}
            />
          ) : null
        }
      />
      {cover?.url && (
        <div>
          <p className="mb-2 text-xs text-muted-foreground">
            {t(
              "settings.cover_focal_help",
              "Click the photo where its most important part is; the page keeps that point in view when it crops.",
            )}
          </p>
          <button
            type="button"
            onClick={place}
            disabled={focal.isPending}
            aria-label={t("settings.cover_focal_pick", "Set the focal point")}
            className="relative block aspect-[2/1] w-full max-w-md cursor-crosshair overflow-hidden rounded-lg border focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img
              src={cover.url}
              srcSet={cover.srcset}
              sizes="(min-width: 640px) 28rem, 100vw"
              alt=""
              className="h-full w-full object-cover"
            />
            <span
              aria-hidden="true"
              className="pointer-events-none absolute h-6 w-6 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white shadow-[0_0_0_2px_rgba(0,0,0,0.5)]"
              style={{
                left: `${Math.round(cover.focal_x * 100)}%`,
                top: `${Math.round(cover.focal_y * 100)}%`,
              }}
            />
          </button>
        </div>
      )}
    </div>
  );
}
