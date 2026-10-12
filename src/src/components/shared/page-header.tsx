"use client";

import type { ReactNode } from "react";
import { createPortal } from "react-dom";
import { usePageActionsSlot } from "@/components/layouts/page-actions-slot";

interface PageHeaderProps {
  title?: string;
  description?: string;
  actions?: ReactNode;
}

/**
 * A page's header actions. The title and breadcrumb come from the shell's
 * `PageTitleBar`; the actions are drawn on its right through
 * `page-actions-slot`, or in place when the page has no title bar.
 */
export function PageHeader({ actions }: PageHeaderProps) {
  const slot = usePageActionsSlot();

  if (!actions) return null;

  const group = (
    // On a phone the actions must wrap rather than overflow (audit N35).
    // In an end-aligned row, overflow spills off the *left* edge, where it
    // cannot be scrolled to: the analytics toolbar began at -13px and the
    // shifts one at -83px. So: min-w-0 lets this box shrink; `*:flex-wrap`
    // wraps the group a page passes in, which is usually its own `flex
    // gap-2`; and `safe` end alignment sends anything that still cannot fit
    // to the right, where it can at least be scrolled to.
    <div className="flex min-w-0 flex-wrap items-center justify-end-safe gap-2 *:flex-wrap *:justify-end">
      {actions}
    </div>
  );

  if (slot) return createPortal(group, slot);

  return <div className="flex items-center justify-end-safe">{group}</div>;
}
