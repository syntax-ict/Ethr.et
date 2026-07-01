'use client';

import { useEffect, useRef } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { initEcho, disconnectEcho } from '@/lib/echo';

interface ReverbProviderProps {
  userId?: string;
  token?: string;
  children?: React.ReactNode;
}

export function ReverbProvider({ userId, token, children }: ReverbProviderProps) {
  const queryClient = useQueryClient();
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const channelRef = useRef<any>(null);

  useEffect(() => {
    if (!userId || !token) return;

    let mounted = true;

    initEcho(token).then((echo) => {
      if (!echo || !mounted) return;

      try {
        channelRef.current = echo.private(`user.${userId}`);
        channelRef.current.listen('.Illuminate\\Notifications\\Events\\BroadcastNotificationCreated', (event: {
          data?: { message?: string };
          id?: string;
        }) => {
          // Invalidate notification queries to refresh badge + list
          queryClient.invalidateQueries({ queryKey: ['notifications'] });

          // Show toast
          const message = event?.data?.message ?? 'You have a new notification';
          toast.info(message, { duration: 5000 });
        });
      } catch {
        // Reverb unavailable (dev without reverb running) — fall back to polling
      }
    });

    return () => {
      mounted = false;
      try {
        channelRef.current?.stopListening('.Illuminate\\Notifications\\Events\\BroadcastNotificationCreated');
      } catch { /* noop */ }
      disconnectEcho();
    };
  }, [userId, token, queryClient]);

  return <>{children}</>;
}
