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
    <div className="flex items-center justify-end">
      {/* min-w-0, not shrink-0 (audit N35): a box that cannot shrink keeps its
          one-line width on a phone, and in a justify-end row the overflow
          spills off the left edge, where it cannot be scrolled to — the
          analytics toolbar lost the start of its first two controls. */}
      <div className="flex min-w-0 flex-wrap items-center justify-end gap-2">
        {actions}
      </div>
    </div>
  );
}
