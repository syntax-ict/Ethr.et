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
      <div className="flex shrink-0 flex-wrap items-center gap-2">
        {actions}
      </div>
    </div>
  );
}
