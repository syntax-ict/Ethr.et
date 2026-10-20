"use client";

import {
  useCallback,
  useEffect,
  useRef,
  useState,
  type ReactNode,
} from "react";
import { ConfirmDialog } from "./confirm-dialog";

export interface ConfirmOptions {
  /** The question, whole: "Delete branch "Bole"?" */
  title: string;
  description?: string;
  /** The verb that does it ("Delete"). Defaults to a translated "Confirm". */
  confirmLabel?: string;
  /** Defaults to a translated "Cancel"; change it when the action is itself a cancellation. */
  cancelLabel?: string;
  variant?: "default" | "destructive";
}

/**
 * A translated, keyboard-operable replacement for `window.confirm()`.
 *
 *   const { requestConfirm, confirmDialog } = useConfirm();
 *   async function remove(row) {
 *     if (!(await requestConfirm({ title: t("org.branch.delete_confirm", undefined, { name }) })))
 *       return;
 *     ...
 *   }
 *   return <>{...}{confirmDialog}</>;
 *
 * The browser's own dialog could not be translated, styled or reached by the
 * accessibility tests, and six of the thirteen were literal English
 * (FRONTEND_REDESIGN_PLAN.md, Phase 2; `design-ratchets.test.ts` now holds
 * native `confirm()` at zero).
 *
 * Not called `confirm` on purpose: the ratchet counts any bare `confirm(` call,
 * and a local function of that name would read as the native one to it and to
 * a reviewer alike.
 *
 * The component renders `confirmDialog` itself rather than relying on a
 * provider, so a page works the same in the app and in a unit test, and two
 * pages never share one pending question.
 */
export function useConfirm(): {
  requestConfirm: (options: ConfirmOptions) => Promise<boolean>;
  confirmDialog: ReactNode;
} {
  const [open, setOpen] = useState(false);
  // Kept after closing so the title does not blank out during the close animation.
  const [options, setOptions] = useState<ConfirmOptions | null>(null);
  const resolver = useRef<((answer: boolean) => void) | null>(null);

  const settle = useCallback((answer: boolean) => {
    resolver.current?.(answer);
    resolver.current = null;
    setOpen(false);
  }, []);

  const requestConfirm = useCallback((next: ConfirmOptions) => {
    // A second question while one is open answers the first with "no".
    resolver.current?.(false);
    return new Promise<boolean>((resolve) => {
      resolver.current = resolve;
      setOptions(next);
      setOpen(true);
    });
  }, []);

  // Leaving the page with a question open must not leave its caller waiting.
  useEffect(() => () => resolver.current?.(false), []);

  const confirmDialog = (
    <ConfirmDialog
      open={open}
      onOpenChange={(next) => {
        if (!next) settle(false);
      }}
      title={options?.title ?? ""}
      description={options?.description}
      confirmLabel={options?.confirmLabel}
      cancelLabel={options?.cancelLabel}
      variant={options?.variant}
      onConfirm={() => settle(true)}
    />
  );

  return { requestConfirm, confirmDialog };
}
