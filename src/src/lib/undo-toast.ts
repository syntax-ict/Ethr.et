import { toast } from "sonner";

/**
 * Toast for an optimistic action per CLAUDE.md's Optimistic UI Policy:
 * show success immediately, offer a 5-second undo window, and let the
 * caller roll back the optimistic change if the user clicks Undo.
 */
export function showUndoToast(
  message: string,
  onUndo: () => void,
  durationMs = 5000,
) {
  toast.success(message, {
    duration: durationMs,
    action: {
      label: "Undo",
      onClick: onUndo,
    },
  });
}
