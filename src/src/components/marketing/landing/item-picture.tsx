"use client";

import { pickLocalised } from "@/features/marketing/api";
import type { MarketingImage } from "@/lib/marketing/content";
import { cn } from "@/lib/utils";
import { Scene } from "./scenes";

/**
 * A row's picture: the uploaded image when there is one, else the drawn
 * scene for its slug. The uploaded image reserves its box from the stored
 * size so nothing shifts while it loads, and is served at the width the
 * column actually is through `srcset` and `sizes`.
 */
export function ItemPicture({
  kind,
  slug,
  image,
  locale,
  sizes,
  priority = false,
  className,
}: {
  kind: string;
  slug: string;
  image: MarketingImage | null | undefined;
  locale: string;
  sizes: string;
  priority?: boolean;
  className?: string;
}) {
  if (image) {
    const alt = pickLocalised(locale, image.alt_am, image.alt) ?? "";
    return (
      // Served by ETHR, re-encoded on upload, at an immutable URL; next/image
      // adds nothing to a static export.
      // eslint-disable-next-line @next/next/no-img-element
      <img
        src={image.url}
        srcSet={image.srcset}
        sizes={sizes}
        width={image.width}
        height={image.height}
        alt={alt}
        loading={priority ? "eager" : "lazy"}
        decoding={priority ? "sync" : "async"}
        fetchPriority={priority ? "high" : "auto"}
        className={cn("h-auto w-full rounded-2xl object-cover", className)}
      />
    );
  }

  return (
    <div
      className={cn("w-full overflow-hidden rounded-2xl", className)}
      data-scene={`${kind}/${slug}`}
    >
      <Scene kind={kind} slug={slug} />
    </div>
  );
}
