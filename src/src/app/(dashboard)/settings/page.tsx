'use client';

import { useState } from 'react';
import { Save, Loader2, Shield, Clock, CalendarDays, Building2, Wallet } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { PageHeader } from '@/components/shared/page-header';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/api/client';
import { toast } from 'sonner';

export default function SettingsPage() {
  const queryClient = useQueryClient();

  const { data, isLoading } = useQuery({
    queryKey: ['settings'],
    queryFn: async () => {
      const { data } = await apiClient.get('/settings');
      return data;
    },
  });

  const [dirty, setDirty] = useState<Record<string, unknown>>({});

  const updateSettings = useMutation({
    mutationFn: async (settings: Record<string, unknown>) => {
      const { data } = await apiClient.put('/settings', { settings });
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['settings'] });
      setDirty({});
      toast.success('Settings saved');
    },
    onError: () => toast.error('Failed to save settings'),
  });

  function updateField(key: string, value: unknown) {
    setDirty((p) => ({ ...p, [key]: value }));
  }

  function getValue(key: string, fallback: unknown = ''): string {
    if (key in dirty) return String(dirty[key]);
    const parts = key.split('.');
    let val: unknown = data;
    for (const p of parts) {
      val = (val as Record<string, unknown>)?.[p];
    }
    return String(val ?? fallback);
  }

  if (isLoading) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-8 w-48" />
        <Skeleton className="h-10 w-80" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  const hasDirty = Object.keys(dirty).length > 0;

  return (
    <div className="space-y-6">
      <PageHeader
        title="Settings"
        description="Configure your organization"
        actions={
          hasDirty && (
            <Button onClick={() => updateSettings.mutate(dirty)} disabled={updateSettings.isPending}>
              {updateSettings.isPending ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Save className="mr-2 h-4 w-4" />}
              Save Changes
            </Button>
          )
        }
      />

      <Tabs defaultValue="general" className="w-full">
        <TabsList className="grid w-full grid-cols-2 sm:grid-cols-5">
          <TabsTrigger value="general">General</TabsTrigger>
          <TabsTrigger value="attendance">Attendance</TabsTrigger>
          <TabsTrigger value="leave">Leave</TabsTrigger>
          <TabsTrigger value="payroll">Payroll</TabsTrigger>
          <TabsTrigger value="security">Security</TabsTrigger>
        </TabsList>

        <TabsContent value="general" className="mt-6">
          <Card>
            <CardHeader>
              <div className="flex items-center gap-2">
                <Building2 className="h-4 w-4 text-muted-foreground" />
                <CardTitle className="text-base">Organization</CardTitle>
              </div>
            </CardHeader>
            <CardContent className="grid gap-4 sm:grid-cols-2">
              <div>
                <Label>Organization Name</Label>
                <Input value={data?.organization?.name ?? ''} disabled className="mt-1" />
                <p className="mt-1 text-xs text-muted-foreground">Contact support to change</p>
              </div>
              <div>
                <Label>Subdomain</Label>
                <div className="mt-1 flex items-center gap-2">
                  <Input value={data?.organization?.subdomain ?? ''} disabled />
                  <span className="shrink-0 text-sm text-muted-foreground">.ethr.et</span>
                </div>
              </div>
              <div>
                <Label>Timezone</Label>
                <Input value={data?.organization?.timezone ?? 'Africa/Addis_Ababa'} disabled className="mt-1" />
              </div>
              <div>
                <Label>Language</Label>
                <Select value={getValue('locale', 'en')} onValueChange={(v) => updateField('locale', v)}>
                  <SelectTrigger className="mt-1">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="en">English</SelectItem>
                    <SelectItem value="am">Amharic</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="attendance" className="mt-6">
          <Card>
            <CardHeader>
              <div className="flex items-center gap-2">
                <Clock className="h-4 w-4 text-muted-foreground" />
                <CardTitle className="text-base">Attendance Rules</CardTitle>
              </div>
            </CardHeader>
            <CardContent className="grid gap-4 sm:grid-cols-2">
              <div>
                <Label>Grace Period (minutes)</Label>
                <Input
                  type="number"
                  value={getValue('grace_period_minutes', '15')}
                  onChange={(e) => updateField('grace_period_minutes', parseInt(e.target.value))}
                  className="mt-1"
                />
                <p className="mt-1 text-xs text-muted-foreground">Minutes after shift start before marking late</p>
              </div>
              <div>
                <Label>OT Daily Cap (minutes)</Label>
                <Input
                  type="number"
                  value={getValue('ot_daily_cap_minutes', '120')}
                  onChange={(e) => updateField('ot_daily_cap_minutes', parseInt(e.target.value))}
                  className="mt-1"
                />
              </div>
              <div>
                <Label>Confidence Threshold (%)</Label>
                <Input
                  type="number"
                  min="0"
                  max="100"
                  value={getValue('confidence_threshold', '70')}
                  onChange={(e) => updateField('confidence_threshold', parseInt(e.target.value))}
                  className="mt-1"
                />
                <p className="mt-1 text-xs text-muted-foreground">Minimum confidence score for attendance records</p>
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="leave" className="mt-6">
          <Card>
            <CardHeader>
              <div className="flex items-center gap-2">
                <CalendarDays className="h-4 w-4 text-muted-foreground" />
                <CardTitle className="text-base">Leave Policies</CardTitle>
              </div>
            </CardHeader>
            <CardContent>
              <div>
                <Label>Working Days</Label>
                <p className="mt-1 text-sm text-muted-foreground">
                  Monday through Friday (default). Configure leave types under Leave Types management.
                </p>
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="payroll" className="mt-6">
          <Card>
            <CardHeader>
              <div className="flex items-center gap-2">
                <Wallet className="h-4 w-4 text-muted-foreground" />
                <CardTitle className="text-base">Payroll Configuration</CardTitle>
              </div>
            </CardHeader>
            <CardContent className="grid gap-4 sm:grid-cols-2">
              <div>
                <Label>Pay Period</Label>
                <Input value={data?.payroll?.pay_period ?? 'monthly'} disabled className="mt-1" />
              </div>
              <div>
                <Label>Payroll Run Day</Label>
                <Input
                  type="number"
                  min="1"
                  max="28"
                  value={getValue('run_day', '25')}
                  onChange={(e) => updateField('run_day', parseInt(e.target.value))}
                  className="mt-1"
                />
                <p className="mt-1 text-xs text-muted-foreground">Day of month to run payroll</p>
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="security" className="mt-6">
          <Card>
            <CardHeader>
              <div className="flex items-center gap-2">
                <Shield className="h-4 w-4 text-muted-foreground" />
                <CardTitle className="text-base">Security Settings</CardTitle>
              </div>
            </CardHeader>
            <CardContent className="grid gap-4 sm:grid-cols-2">
              <div>
                <Label>MFA Policy</Label>
                <Select value={getValue('mfa_policy', 'optional')} onValueChange={(v) => updateField('mfa_policy', v)}>
                  <SelectTrigger className="mt-1">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="disabled">Disabled</SelectItem>
                    <SelectItem value="optional">Optional</SelectItem>
                    <SelectItem value="required">Required</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div>
                <Label>Session Timeout (minutes)</Label>
                <Input
                  type="number"
                  value={getValue('session_timeout_minutes', '480')}
                  onChange={(e) => updateField('session_timeout_minutes', parseInt(e.target.value))}
                  className="mt-1"
                />
              </div>
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>
    </div>
  );
}
