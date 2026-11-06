"use client";

import type { ReactNode } from "react";
import { useNearViewport } from "@/lib/hooks/useNearViewport";

/**
 * Mounts its children once the reader is about to see them, and a
 * placeholder of the same height until then.
 *
 * The dashboard's charts were the first to wait like this (PR #518: the
 * recharts chunk). The side column's widgets each make a request on mount,
 * and on a phone they sit well below the first screen, so a reader who never
 * scrolls paid for announcements, the pulse question and the activity list
 * they never saw (dashboard review, 2026-11-03, PR 11). The margin is small
 * for a light widget: 600 px, the charts' default, counts nearly a whole
 * desktop page as "near".
 */
export function Deferred({
  children,
  placeholder,
  margin = "200px",
}: {
  children: ReactNode;
  placeholder: ReactNode;
  margin?: string;
}) {
  const { ref, near } = useNearViewport<HTMLDivElement>(margin);

  return <div ref={ref}>{near ? children : placeholder}</div>;
}
