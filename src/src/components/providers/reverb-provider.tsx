"use client";

import { useEffect, useRef } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { initEcho, disconnectEcho } from "@/lib/echo";

interface ReverbProviderProps {
  userId?: string;
  children?: React.ReactNode;
}

export function ReverbProvider({ userId, children }: ReverbProviderProps) {
  const queryClient = useQueryClient();
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const channelRef = useRef<any>(null);

  useEffect(() => {
    if (!userId) return;

    let mounted = true;

    initEcho().then((echo) => {
      if (!echo || !mounted) return;

      try {
        channelRef.current = echo.private(`user.${userId}`);
        channelRef.current.listen(
          ".Illuminate\\Notifications\\Events\\BroadcastNotificationCreated",
          (event: { data?: { message?: string }; id?: string }) => {
            queryClient.invalidateQueries({ queryKey: ["notifications"] });

            const message =
              event?.data?.message ?? "You have a new notification";
            toast.info(message, { duration: 5000 });
          },
        );
      } catch {
        // Reverb unavailable (dev without reverb running) — fall back to polling
      }
    });

    return () => {
      mounted = false;
      try {
        channelRef.current?.stopListening(
          ".Illuminate\\Notifications\\Events\\BroadcastNotificationCreated",
        );
      } catch {
        /* noop */
      }
      disconnectEcho();
    };
  }, [userId, queryClient]);

  return <>{children}</>;
}
