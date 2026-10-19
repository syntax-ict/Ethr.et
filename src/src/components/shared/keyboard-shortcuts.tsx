"use client";

import { useCallback, useEffect, useState } from "react";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { useShortcutLabel } from "@/lib/hooks/usePlatformShortcut";
import { useT } from "@/lib/i18n/useT";

const OPEN_EVENT = "keyboard-shortcuts:open";

/** Open the shortcuts sheet from anywhere, e.g. the account menu. */
export function openKeyboardShortcuts(): void {
  if (typeof window !== "undefined") {
    window.dispatchEvent(new CustomEvent(OPEN_EVENT));
  }
}

/** True while the user is typing into a field, where "?" is just a character. */
function isTypingTarget(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) return false;
  if (target.isContentEditable) return true;
  const tag = target.tagName;
  return tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT";
}

/**
 * The keyboard shortcuts the app actually has, in one place: `?` opens it, as
 * on most web apps, and the account menu links to it. Only shortcuts that
 * work are listed; one added elsewhere belongs here too.
 */
export function KeyboardShortcuts() {
  const { t } = useT();
  const [open, setOpen] = useState(false);
  const searchKey = useShortcutLabel("K");
  const sidebarKey = useShortcutLabel("B");

  const show = useCallback(() => setOpen(true), []);

  useEffect(() => {
    function onKeyDown(e: KeyboardEvent) {
      if (
        e.key === "?" &&
        !e.metaKey &&
        !e.ctrlKey &&
        !e.altKey &&
        !isTypingTarget(e.target)
      ) {
        e.preventDefault();
        setOpen(true);
      }
    }
    document.addEventListener("keydown", onKeyDown);
    window.addEventListener(OPEN_EVENT, show);
    return () => {
      document.removeEventListener("keydown", onKeyDown);
      window.removeEventListener(OPEN_EVENT, show);
    };
  }, [show]);

  const rows: Array<{ keys: string[]; label: string }> = [
    {
      keys: [searchKey],
      label: t("shortcuts.search", "Search people, records and pages"),
    },
    {
      keys: ["/"],
      label: t("shortcuts.search_slash", "Search, from anywhere"),
    },
    {
      keys: [sidebarKey],
      label: t("shortcuts.sidebar", "Collapse or expand the sidebar"),
    },
    { keys: ["?"], label: t("shortcuts.this_sheet", "Show these shortcuts") },
    { keys: ["Esc"], label: t("shortcuts.close", "Close a dialog or menu") },
  ];

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>
            {t("shortcuts.title", "Keyboard shortcuts")}
          </DialogTitle>
          <DialogDescription>
            {t(
              "shortcuts.description",
              "Work faster without leaving the keyboard.",
            )}
          </DialogDescription>
        </DialogHeader>
        <dl className="divide-y divide-border rounded-lg border border-border">
          {rows.map((row) => (
            <div
              key={row.label}
              className="flex items-center justify-between gap-4 px-3 py-2.5"
            >
              <dt className="text-sm text-foreground">{row.label}</dt>
              <dd className="flex shrink-0 gap-1">
                {row.keys.map((key) => (
                  <kbd
                    key={key}
                    className="inline-flex h-6 min-w-6 items-center justify-center rounded border border-border bg-muted px-1.5 font-mono text-xs font-medium text-foreground"
                  >
                    {key}
                  </kbd>
                ))}
              </dd>
            </div>
          ))}
        </dl>
      </DialogContent>
    </Dialog>
  );
}
