/**
 * A short buzz for the punch screen, where the phone supports it. Android
 * browsers do; iPhone Safari does not, so this is a no-op there and nothing
 * on the screen depends on it.
 */
export type HapticKind = "tap" | "ready" | "success" | "refused";

const PATTERNS: Record<HapticKind, number | number[]> = {
  tap: 10,
  ready: 15,
  success: [20, 40, 30],
  refused: [30, 60, 30, 60, 30],
};

export function haptic(kind: HapticKind): void {
  try {
    if (typeof navigator === "undefined") return;
    if (typeof navigator.vibrate !== "function") return;
    // Chrome logs an error for a buzz before the person has touched the page.
    const activation = (
      navigator as { userActivation?: { hasBeenActive: boolean } }
    ).userActivation;
    if (activation && !activation.hasBeenActive) return;
    navigator.vibrate(PATTERNS[kind]);
  } catch {
    // A browser that refuses to vibrate is not a reason to fail a punch.
  }
}
