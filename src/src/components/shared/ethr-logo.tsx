"use client";

import { useState } from "react";
import { cn } from "@/lib/utils";

/**
 * ETHR's own logo tile: the white "E" on the brand blue that the marketing
 * header, the sign-in pages and the installed app's icon all carry, or the
 * platform logo the operator set in the console when there is one that loads.
 *
 * The size comes from `className`, the letter's from `letterClassName`, so the
 * one tile serves a 20px footer mark and a 32px header alike.
 *
 * An operator's logo sits on a white tile for the reason `OrgMark` gives: it
 * was drawn for a light page, and the tile makes it read on any surface,
 * including the sidebar, which is dark in both themes.
 */
export function EthrLogoTile({
  logoUrl,
  className,
  letterClassName,
}: {
  logoUrl?: string | null;
  className?: string;
  letterClassName?: string;
}) {
  const [broken, setBroken] = useState(false);

  if (logoUrl && !broken) {
    return (
      <span
        aria-hidden="true"
        className={cn(
          "flex shrink-0 items-center justify-center overflow-hidden rounded-md bg-white p-0.5",
          className,
        )}
      >
        {/* Operator-supplied and arbitrary, so it cannot be in next.config's
            remotePatterns; the call marketing-footer.tsx makes for this URL. */}
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img
          src={logoUrl}
          alt=""
          className="h-full w-full object-contain"
          onError={() => setBroken(true)}
          referrerPolicy="no-referrer"
        />
      </span>
    );
  }

  return (
    <span
      aria-hidden="true"
      className={cn(
        "flex shrink-0 items-center justify-center rounded-md bg-primary font-bold leading-none text-primary-foreground shadow-sm",
        className,
      )}
    >
      <span className={letterClassName}>E</span>
    </span>
  );
}
