let asked = false;

/**
 * Asks the browser not to evict this site's storage under pressure. A punch
 * or a visit waiting on the phone is the only copy; an evicted store loses it.
 * Once per page load, and never a reason to fail: no result is read. Chrome
 * decides silently; Firefox may ask the person, which is why this waits until
 * something is actually being kept.
 */
export function requestPersistentStorage(): void {
  if (asked) return;
  asked = true;
  try {
    void navigator.storage?.persist?.()?.catch?.(() => {});
  } catch {
    // Not supported here; nothing to do.
  }
}
