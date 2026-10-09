"use client";

import type { ReactNode } from "react";

interface PageHeaderProps {
  title?: string;
  description?: string;
  actions?: ReactNode;
}

export function PageHeader({ actions }: PageHeaderProps) {
  if (!actions) return null;

  return (
    <div className="flex items-center justify-end-safe">
      {/* On a phone the actions must wrap rather than overflow (audit N35).
          In an end-aligned row, overflow spills off the *left* edge, where it
          cannot be scrolled to: the analytics toolbar began at -13px and the
          shifts one at -83px. So: min-w-0 lets this box shrink; `*:flex-wrap`
          wraps the group a page passes in, which is usually its own
          `flex gap-2`; and `safe` end alignment sends anything that still
          cannot fit to the right, where it can at least be scrolled to. */}
      <div className="flex min-w-0 flex-wrap items-center justify-end-safe gap-2 *:flex-wrap *:justify-end">
        {actions}
      </div>
    </div>
  );
}
