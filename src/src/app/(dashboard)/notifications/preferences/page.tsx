'use client';

import { useState } from 'react';
import { Mail, Bell, MessageSquare, Save, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { PageHeader } from '@/components/shared/page-header';
import { toast } from 'sonner';

interface NotificationType {
  key: string;
  label: string;
  description: string;
}

const NOTIFICATION_TYPES: NotificationType[] = [
  { key: 'leave_requested', label: 'Leave Requested', description: 'When a team member submits a leave request' },
  { key: 'leave_approved', label: 'Leave Approved', description: 'When your leave request is approved' },
  { key: 'leave_rejected', label: 'Leave Rejected', description: 'When your leave request is rejected' },
  { key: 'attendance_correction', label: 'Correction Request', description: 'When a correction needs your approval' },
  { key: 'attendance_anomaly', label: 'Attendance Anomaly', description: 'Late arrivals, missing punches' },
  { key: 'payslip_available', label: 'Payslip Available', description: 'When your payslip is generated' },
  { key: 'payroll_processed', label: 'Payroll Processed', description: 'When payroll has been run for the period' },
  { key: 'announcement', label: 'Announcements', description: 'Organization-wide announcements' },
  { key: 'approval_reminder', label: 'Approval Reminder', description: 'Reminders for items pending your approval' },
];

const CHANNELS = [
  { key: 'in_app', label: 'In-app', icon: Bell, alwaysOn: true },
  { key: 'email', label: 'Email', icon: Mail, alwaysOn: false },
  { key: 'sms', label: 'SMS', icon: MessageSquare, alwaysOn: false },
];

export default function NotificationPreferencesPage() {
  const [prefs, setPrefs] = useState<Record<string, Record<string, boolean>>>(() => {
    const initial: Record<string, Record<string, boolean>> = {};
    NOTIFICATION_TYPES.forEach((t) => {
      initial[t.key] = { in_app: true, email: true, sms: false };
    });
    return initial;
  });
  const [saving, setSaving] = useState(false);

  function toggle(typeKey: string, channelKey: string) {
    setPrefs((p) => ({
      ...p,
      [typeKey]: { ...p[typeKey], [channelKey]: !p[typeKey][channelKey] },
    }));
  }

  function handleSave() {
    setSaving(true);
    setTimeout(() => {
      localStorage.setItem('notification_preferences', JSON.stringify(prefs));
      toast.success('Notification preferences saved');
      setSaving(false);
    }, 500);
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Notification Preferences"
        description="Choose how you want to be notified about each event type"
        actions={
          <Button onClick={handleSave} disabled={saving}>
            {saving ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Save className="mr-2 h-4 w-4" />}
            Save Changes
          </Button>
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
                  {CHANNELS.map((ch) => {
                    const Icon = ch.icon;
                    return (
                      <th key={ch.key} className="px-4 py-3 text-center text-xs font-medium uppercase text-muted-foreground">
                        <div className="flex flex-col items-center gap-1">
                          <Icon className="h-4 w-4" />
                          <span>{ch.label}</span>
                        </div>
                      </th>
                    );
                  })}
                </tr>
              </thead>
              <tbody>
                {NOTIFICATION_TYPES.map((type) => (
                  <tr key={type.key} className="border-b last:border-0 hover:bg-muted/30">
                    <td className="px-4 py-3">
                      <p className="text-sm font-medium text-foreground">{type.label}</p>
                      <p className="text-xs text-muted-foreground">{type.description}</p>
                    </td>
                    {CHANNELS.map((ch) => (
                      <td key={ch.key} className="px-4 py-3 text-center">
                        <label className="inline-flex cursor-pointer items-center">
                          <input
                            type="checkbox"
                            checked={prefs[type.key]?.[ch.key] ?? false}
                            disabled={ch.alwaysOn}
                            onChange={() => toggle(type.key, ch.key)}
                            className="h-5 w-5 rounded cursor-pointer disabled:cursor-not-allowed disabled:opacity-60"
                          />
                        </label>
                      </td>
                    ))}
                  </tr>
                ))}
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
