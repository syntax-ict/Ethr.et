"use client";

import { useState } from "react";
import { cn } from "@/lib/utils";

/**
 * An organisation's mark on the sign-in pages: its logo when it has an https
 * one that loads, else its initial on the brand colour - the same tile ETHR's
 * own "E" occupies on the generic sign-in.
 */
export function OrgMark({
  name,
  logoUrl,
  className,
  initialClassName,
}: {
  name: string | null;
  logoUrl: string | null | undefined;
  className?: string;
  initialClassName?: string;
}) {
  const [broken, setBroken] = useState(false);
  const initial = (name ?? "E").trim().charAt(0).toUpperCase() || "E";

  if (logoUrl && !broken) {
    return (
      <span
        className={cn(
          "flex items-center justify-center overflow-hidden rounded-xl bg-white p-1.5 shadow-sm ring-1 ring-border",
          className,
        )}
      >
        {/* A tenant-supplied URL on another origin: next/image would need
            every such host allow-listed at build time. */}
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img
          src={logoUrl}
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
