"use client";

import { useState } from "react";
import { useTheme } from "next-themes";
import { cn } from "@/lib/utils";

/**
 * An organisation's mark on the sign-in pages: its logo when it has an https
 * one that loads, else its initial on the brand colour - the same tile ETHR's
 * own "E" occupies on the generic sign-in.
 *
 * The logo sits on a white tile, so it reads on any background. With a logo
 * for dark surfaces uploaded (`darkLogoUrl`) and the page in dark mode, or
 * the mark placed on a surface known to be dark (`surface="dark"`, the hero
 * over a cover photo), that one is shown instead, on no tile: it was drawn
 * for the surface it is on.
 */
export function OrgMark({
  name,
  logoUrl,
  darkLogoUrl,
  surface,
  className,
  initialClassName,
}: {
  name: string | null;
  logoUrl: string | null | undefined;
  darkLogoUrl?: string | null;
  surface?: "dark";
  className?: string;
  initialClassName?: string;
}) {
  const [broken, setBroken] = useState(false);
  const { resolvedTheme } = useTheme();
  const initial = (name ?? "E").trim().charAt(0).toUpperCase() || "E";
  const onDark =
    Boolean(darkLogoUrl) && (surface === "dark" || resolvedTheme === "dark");
  const src = onDark ? darkLogoUrl : logoUrl;

  if (src && !broken) {
    return (
      <span
        className={cn(
          "flex items-center justify-center overflow-hidden rounded-xl p-1.5",
          onDark ? "bg-transparent" : "bg-white shadow-sm ring-1 ring-border",
          className,
        )}
      >
        {/* A tenant-supplied URL on another origin: next/image would need
            every such host allow-listed at build time. */}
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img
          src={src}
          alt={name ?? ""}
          className="h-full w-full object-contain"
          onError={() => setBroken(true)}
          referrerPolicy="no-referrer"
        />
      </span>
    );
  }

  return (
    <span
      className={cn(
        "flex items-center justify-center rounded-xl bg-primary font-bold text-primary-foreground shadow-sm",
        className,
      )}
      aria-hidden={name ? undefined : true}
    >
      <span className={initialClassName}>{initial}</span>
    </span>
  );
}
