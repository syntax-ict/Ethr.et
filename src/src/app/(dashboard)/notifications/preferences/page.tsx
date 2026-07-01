'use client';

import { useEffect, useState } from 'react';
import { Mail, Bell, MessageSquare, Save, Loader2, RotateCcw } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { PageHeader } from '@/components/shared/page-header';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/api/client';
import { toast } from 'sonner';

interface PreferencesResponse {
  notification_types: string[];
  channels: string[];
  preferences: Record<string, Record<string, boolean>>;
}

const TYPE_LABELS: Record<string, { label: string; description: string }> = {
  leave_requested: { label: 'Leave Requested', description: 'When a team member submits a leave request' },
  leave_approved: { label: 'Leave Approved', description: 'When your leave request is approved' },
  leave_rejected: { label: 'Leave Rejected', description: 'When your leave request is rejected' },
  attendance_correction: { label: 'Correction Request', description: 'When a correction needs your approval' },
  attendance_anomaly: { label: 'Attendance Anomaly', description: 'Late arrivals, missing punches' },
  payslip_available: { label: 'Payslip Available', description: 'When your payslip is generated' },
  payroll_processed: { label: 'Payroll Processed', description: 'When payroll has been run for the period' },
  announcement: { label: 'Announcements', description: 'Organization-wide announcements' },
  approval_reminder: { label: 'Approval Reminder', description: 'Reminders for items pending your approval' },
};

const CHANNEL_META: Record<string, { label: string; icon: React.ComponentType<{ className?: string }>; alwaysOn?: boolean }> = {
  in_app: { label: 'In-app', icon: Bell, alwaysOn: true },
  email: { label: 'Email', icon: Mail },
  sms: { label: 'SMS', icon: MessageSquare },
};

export default function NotificationPreferencesPage() {
  const queryClient = useQueryClient();
  const [local, setLocal] = useState<Record<string, Record<string, boolean>> | null>(null);
  const [dirty, setDirty] = useState(false);

  const { data, isLoading } = useQuery<PreferencesResponse>({
    queryKey: ['notifications', 'preferences'],
    queryFn: async () => (await apiClient.get('/notifications/preferences')).data,
  });

  // Sync server data → local editable copy the first time it arrives
  useEffect(() => {
    if (data && !local) {
      setLocal(data.preferences);
    }
  }, [data, local]);

  const save = useMutation({
    mutationFn: async () => {
      if (!local) throw new Error('No preferences');
      const { data } = await apiClient.put('/notifications/preferences', { preferences: local });
      return data;
    },
    onSuccess: (result) => {
      queryClient.setQueryData(['notifications', 'preferences'], result);
      setLocal(result.preferences);
      setDirty(false);
      toast.success('Notification preferences saved');
    },
    onError: () => toast.error('Failed to save preferences'),
  });

  function toggle(typeKey: string, channelKey: string) {
    if (CHANNEL_META[channelKey]?.alwaysOn) return;
    setLocal((p) => {
      if (!p) return p;
      return {
        ...p,
        [typeKey]: { ...p[typeKey], [channelKey]: !p[typeKey][channelKey] },
      };
    });
    setDirty(true);
  }

  function reset() {
    if (data) {
      setLocal(data.preferences);
      setDirty(false);
    }
  }

  if (isLoading || !data || !local) {
    return (
      <div className="space-y-6">
        <PageHeader title="Notification Preferences" description="Choose how you want to be notified" />
        <Skeleton className="h-96 w-full" />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Notification Preferences"
        description="Choose how you want to be notified about each event type"
        actions={
          <div className="flex gap-2">
            {dirty && (
              <Button variant="outline" onClick={reset} disabled={save.isPending}>
                <RotateCcw className="mr-2 h-4 w-4" /> Reset
              </Button>
            )}
            <Button onClick={() => save.mutate()} disabled={!dirty || save.isPending}>
              {save.isPending ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Save className="mr-2 h-4 w-4" />}
              Save Changes
            </Button>
          </div>
        }
      />

      <Card>
        <CardHeader><CardTitle className="text-base">Notification Types</CardTitle></CardHeader>
        <CardContent className="p-0">
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead>
                <tr className="border-b bg-muted/50">
                  <th className="px-4 py-3 text-left text-xs font-medium uppercase text-muted-foreground">Event</th>
                  {data.channels.map((ch) => {
                    const meta = CHANNEL_META[ch];
                    const Icon = meta?.icon ?? Bell;
                    return (
                      <th key={ch} className="px-4 py-3 text-center text-xs font-medium uppercase text-muted-foreground">
                        <div className="flex flex-col items-center gap-1">
                          <Icon className="h-4 w-4" />
                          <span>{meta?.label ?? ch}</span>
                        </div>
                      </th>
                    );
                  })}
                </tr>
              </thead>
              <tbody>
                {data.notification_types.map((type) => {
                  const meta = TYPE_LABELS[type] ?? { label: type, description: '' };
                  return (
                    <tr key={type} className="border-b last:border-0 hover:bg-muted/30">
                      <td className="px-4 py-3">
                        <p className="text-sm font-medium text-foreground">{meta.label}</p>
                        <p className="text-xs text-muted-foreground">{meta.description}</p>
                      </td>
                      {data.channels.map((ch) => {
                        const cm = CHANNEL_META[ch];
                        const value = local[type]?.[ch] ?? false;
                        return (
                          <td key={ch} className="px-4 py-3 text-center">
                            <label className="inline-flex cursor-pointer items-center">
                              <input
                                type="checkbox"
                                checked={value}
                                disabled={cm?.alwaysOn}
                                onChange={() => toggle(type, ch)}
                                className="h-5 w-5 rounded cursor-pointer disabled:cursor-not-allowed disabled:opacity-60"
                              />
                            </label>
                          </td>
                        );
                      })}
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          <div className="border-t bg-muted/30 px-4 py-3 text-xs text-muted-foreground">
            In-app notifications cannot be disabled. SMS notifications may incur charges depending on your plan.
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
