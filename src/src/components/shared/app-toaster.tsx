"use client";

import type { CSSProperties } from "react";
import { useTheme } from "next-themes";
import { Toaster } from "sonner";

/**
 * The app's one toaster.
 *
 * It was `<Toaster richColors />` with no theme. Two problems followed
 * (FRONTEND_REDESIGN_PLAN.md, Phase 2):
 * - sonner does not read next-themes, so every toast was drawn light in dark
 *   mode;
 * - rich colours fill the whole toast with saturated green or red, the
 *   loudest surface on any screen for what is usually "Saved".
 *
 * Now a toast sits on the popover surface, the floating-layer token every
 * theme defines with tested contrast, and only its icon carries the status
 * colour. The high-contrast theme is a light theme to sonner.
 */
export function AppToaster() {
  const { resolvedTheme } = useTheme();
  const theme = resolvedTheme === "dark" ? "dark" : "light";

  return (
    <Toaster
      theme={theme}
      position="top-right"
      style={
        {
          "--normal-bg": "var(--popover)",
          "--normal-text": "var(--popover-foreground)",
          "--normal-border": "var(--border)",
        } as CSSProperties
      }
      toastOptions={{
        classNames: {
          description: "!text-muted-foreground",
          success: "[&_[data-icon]]:text-status-success",
          error: "[&_[data-icon]]:text-status-error",
          warning: "[&_[data-icon]]:text-status-warning",
          info: "[&_[data-icon]]:text-status-info",
        },
      }}
    />
  );
}
