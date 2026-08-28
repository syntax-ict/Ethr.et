"use client";

import { useState } from "react";
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar";
import { cn } from "@/lib/utils";

/**
 * Derives up to two initials from a display name. Handles Amharic, where a
 * "character" can be more than one UTF-16 code unit, by splitting on grapheme
 * boundaries rather than indexing into the string.
 */
export function initialsFor(name: string): string {
  const words = name.trim().split(/\s+/).filter(Boolean);

  const firstChars = words.map((word) => [...word][0] ?? "");

  return firstChars.slice(0, 2).join("").toUpperCase();
}

interface EmployeeAvatarProps {
  name: string;
  /** Small pre-generated thumbnail — preferred in lists and headers. */
  photoThumbUrl?: string | null;
  /** Full-size photo; used as a fallback for photos stored before thumbnails. */
  photoUrl?: string | null;
  className?: string;
  fallbackClassName?: string;
}

export function EmployeeAvatar({
  name,
  photoThumbUrl,
  photoUrl,
  className,
  fallbackClassName,
}: EmployeeAvatarProps) {
  const sources = [photoThumbUrl, photoUrl].filter(
    (src): src is string => typeof src === "string" && src.length > 0,
  );
  const [failedCount, setFailedCount] = useState(0);
  const src = sources[failedCount];

  return (
    <Avatar className={className}>
      {src ? (
        <AvatarImage
          src={src}
          alt={name}
          loading="lazy"
          className="object-cover"
          // A thumbnail may 404 for a photo uploaded before thumbnails existed;
          // step to the full-size URL, then to initials.
          onError={() => setFailedCount((count) => count + 1)}
        />
      ) : (
        <AvatarFallback className={cn(fallbackClassName)}>
          {initialsFor(name)}
        </AvatarFallback>
      )}
    </Avatar>
  );
}
