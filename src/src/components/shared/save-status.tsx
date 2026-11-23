"use client";

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from "react";
import { apiErrorMessage } from "@/lib/api/error-message";
import { useT } from "@/lib/i18n/useT";

/**
 * One hidden status line for a whole area (the settings pages), so a screen
 * reader hears "Settings saved" or the reason a save failed. The visible toasts
 * stay as they are; they are not reliably announced, and they leave before a
 * slow reader reaches them.
 */
type SaveStatus = {
  /** Announce a save that went through. Defaults to "Settings saved". */
  announceSaved: (message?: string) => void;
  /** Announce why a save failed: the server's reason, else the fallback. */
  announceError: (error: unknown, fallback?: string) => void;
};

const NOOP: SaveStatus = {
  announceSaved: () => {},
  announceError: () => {},
};

// The default is a no-op, not a throw: a card that is also rendered outside the
// provider (a different page, a test) simply does not announce.
const SaveStatusContext = createContext<SaveStatus>(NOOP);

export function SaveStatusProvider({ children }: { children: ReactNode }) {
  const { t } = useT();
  const [message, setMessage] = useState("");
  const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

  useEffect(() => () => clearTimeout(timer.current), []);

  const announce = useCallback((text: string) => {
    clearTimeout(timer.current);
    // Cleared first: the same words announced twice in a row ("Saved", then
    // "Saved") are only read the second time if the region changed in between.
    setMessage("");
    timer.current = setTimeout(() => setMessage(text), 50);
  }, []);

  const value = useMemo<SaveStatus>(
    () => ({
      announceSaved: (text) =>
        announce(text ?? t("settings.saved", "Settings saved")),
      announceError: (error, fallback) =>
        announce(
          apiErrorMessage(
            error,
            fallback ?? t("settings.save_failed", "Failed to save settings"),
          ),
        ),
    }),
    [announce, t],
  );

  return (
    <SaveStatusContext.Provider value={value}>
      {children}
      <div
        role="status"
        aria-live="polite"
        aria-atomic="true"
        className="sr-only"
        data-testid="save-status"
      >
        {message}
      </div>
    </SaveStatusContext.Provider>
  );
}

export function useSaveStatus(): SaveStatus {
  return useContext(SaveStatusContext);
}
