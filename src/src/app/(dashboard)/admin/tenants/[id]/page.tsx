'use client';

import { use, useState } from 'react';
import Link from 'next/link';
import {
  ArrowLeft, Users, Calendar, Globe, Building2, Loader2,
  Pause, Play, XCircle, CalendarPlus, KeySquare, AlertTriangle,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter, DialogDescription } from '@/components/ui/dialog';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { RoleGate } from '@/components/shared/role-gate';
import { useAdminTenant, useUpdateTenantStatus, useExtendTrial, useImpersonateTenant } from '@/features/admin/api';
import { toast } from 'sonner';

export default function AdminTenantDetailPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = use(params);
  const { data: tenant, isLoading } = useAdminTenant(id);
  const updateStatus = useUpdateTenantStatus();
  const extendTrial = useExtendTrial();
  const impersonate = useImpersonateTenant();

  const [extendOpen, setExtendOpen] = useState(false);
  const [extendDays, setExtendDays] = useState(30);
  const [impersonateOpen, setImpersonateOpen] = useState(false);
  const [impersonationResult, setImpersonationResult] = useState<{ token: string; tenant: string; expires_at: string } | null>(null);

  function handleStatusChange(newStatus: 'active' | 'suspended' | 'cancelled') {
    if (!tenant) return;
    const action = newStatus === 'active' ? 'Activate' : newStatus === 'suspended' ? 'Suspend' : 'Cancel';
    if (!confirm(`${action} tenant "${tenant.name}"?`)) return;
    updateStatus.mutate(
      { publicId: tenant.public_id, status: newStatus },
      {
        onSuccess: () => toast.success(`Tenant ${newStatus}`),
        onError: () => toast.error('Status update failed'),
      }
    );
  }

  function handleExtendTrial(e: React.FormEvent) {
    e.preventDefault();
    if (!tenant) return;
    extendTrial.mutate(
      { publicId: tenant.public_id, days: extendDays },
      {
        onSuccess: () => {
          toast.success(`Trial extended by ${extendDays} days`);
          setExtendOpen(false);
        },
        onError: () => toast.error('Extend failed'),
      }
    );
  }

  function handleImpersonate() {
    if (!tenant) return;
    impersonate.mutate(tenant.public_id, {
      onSuccess: (data) => {
        setImpersonationResult(data);
        setImpersonateOpen(true);
      },
      onError: (err: unknown) => {
        const axiosErr = err as { response?: { data?: { detail?: string } } };
        toast.error(axiosErr.response?.data?.detail || 'Impersonation failed');
      },
    });
  }

  function copyToken() {
    if (impersonationResult) {
      navigator.clipboard.writeText(impersonationResult.token);
      toast.success('Token copied to clipboard');
    }
  }

  if (isLoading) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-8 w-48" />
        <Skeleton className="h-32 w-full" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  if (!tenant) {
    return (
      <div className="py-16 text-center">
        <p className="text-muted-foreground">Tenant not found</p>
      </div>
    );
  }

  const isActive = tenant.status === 'active';
  const isSuspended = tenant.status === 'suspended';
  const isCancelled = tenant.status === 'cancelled';
  const isTrial = tenant.status === 'trial';

  return (
    <RoleGate allowedRoles={['super_admin']}>
      <div className="space-y-6">
        <div className="flex items-center gap-4">
          <Button variant="ghost" size="sm" asChild>
            <Link href="/admin/tenants">
              <ArrowLeft className="mr-2 h-4 w-4" /> Back to Tenants
            </Link>
          </Button>
        </div>

        <PageHeader
          title={tenant.name}
          description={`${tenant.subdomain}.ethr.et`}
          actions={<StatusBadge status={tenant.status} />}
        />

        {/* Quick stats */}
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <StatCard icon={Users} label="Employees" value={String(tenant.employee_count)} />
          <StatCard icon={Globe} label="Subdomain" value={tenant.subdomain} />
          <StatCard icon={Calendar} label="Trial Ends" value={tenant.trial_ends_at ? new Date(tenant.trial_ends_at).toLocaleDateString() : '—'} />
          <StatCard icon={Building2} label="Type" value={tenant.type ?? 'Unspecified'} />
        </div>

        {/* Actions */}
        <Card>
          <CardHeader><CardTitle className="text-base">Administrative Actions</CardTitle></CardHeader>
          <CardContent>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              {/* Status actions */}
              {!isActive && !isCancelled && (
                <Button variant="outline" onClick={() => handleStatusChange('active')} disabled={updateStatus.isPending}>
                  <Play className="mr-2 h-4 w-4 text-green-600" /> Activate
                </Button>
              )}
              {!isSuspended && !isCancelled && (
                <Button variant="outline" onClick={() => handleStatusChange('suspended')} disabled={updateStatus.isPending}>
                  <Pause className="mr-2 h-4 w-4 text-amber-600" /> Suspend
                </Button>
              )}
              {!isCancelled && (
                <Button variant="outline" onClick={() => handleStatusChange('cancelled')} disabled={updateStatus.isPending}>
                  <XCircle className="mr-2 h-4 w-4 text-red-600" /> Cancel
                </Button>
              )}

              <Button variant="outline" onClick={() => setExtendOpen(true)}>
                <CalendarPlus className="mr-2 h-4 w-4 text-blue-600" /> Extend Trial
              </Button>

              <Button variant="outline" onClick={handleImpersonate} disabled={impersonate.isPending}>
                {impersonate.isPending
                  ? <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  : <KeySquare className="mr-2 h-4 w-4 text-purple-600" />}
                Impersonate Admin
              </Button>
            </div>

            {isCancelled && (
              <div className="mt-4 flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 p-3 dark:border-red-900 dark:bg-red-950/30">
                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-red-600" />
                <p className="text-sm text-foreground">This tenant is <span className="font-semibold">cancelled</span>. Most actions are disabled.</p>
              </div>
            )}
          </CardContent>
        </Card>

        {/* Profile */}
        <Card>
          <CardHeader><CardTitle className="text-base">Tenant Profile</CardTitle></CardHeader>
          <CardContent className="space-y-3">
            <Row label="Public ID" value={<code className="font-mono text-xs">{tenant.public_id}</code>} />
            <Row label="Name" value={tenant.name} />
            <Row label="Subdomain" value={`${tenant.subdomain}.ethr.et`} />
            <Row label="Type" value={tenant.type ?? '—'} />
            <Row label="Status" value={<StatusBadge status={tenant.status} />} />
            <Row label="Employees" value={String(tenant.employee_count)} />
            <Row label="Trial Ends" value={tenant.trial_ends_at ? new Date(tenant.trial_ends_at).toLocaleString() : '—'} />
            <Row label="Created" value={new Date(tenant.created_at).toLocaleString()} />
            <Row label="Updated" value={new Date(tenant.updated_at).toLocaleString()} />
          </CardContent>
        </Card>

        {/* Extend Trial dialog */}
        <Dialog open={extendOpen} onOpenChange={setExtendOpen}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Extend Trial Period</DialogTitle>
              <DialogDescription>
                Current trial ends: <span className="font-medium">{tenant.trial_ends_at ? new Date(tenant.trial_ends_at).toLocaleDateString() : 'no trial set'}</span>
              </DialogDescription>
            </DialogHeader>
            <form onSubmit={handleExtendTrial} className="space-y-4">
              <div>
                <Label>Additional days</Label>
                <Input
                  type="number"
                  min={1}
                  max={180}
                  value={extendDays}
                  onChange={(e) => setExtendDays(parseInt(e.target.value) || 30)}
                  className="mt-1"
                  required
                />
                <p className="mt-1 text-xs text-muted-foreground">Maximum 180 days per extension.</p>
              </div>
              <DialogFooter>
                <Button type="button" variant="outline" onClick={() => setExtendOpen(false)}>Cancel</Button>
                <Button type="submit" disabled={extendTrial.isPending}>
                  {extendTrial.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                  Extend
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>

        {/* Impersonation result dialog */}
        <Dialog open={impersonateOpen} onOpenChange={(open) => { setImpersonateOpen(open); if (!open) setImpersonationResult(null); }}>
          <DialogContent className="max-w-lg">
            <DialogHeader>
              <DialogTitle>Impersonation Token Issued</DialogTitle>
              <DialogDescription>
                You can now act as a Tenant Admin in <span className="font-medium">{impersonationResult?.tenant}</span>.
              </DialogDescription>
            </DialogHeader>
            <div className="space-y-3">
              <div className="rounded-lg border-2 border-amber-300 bg-amber-50 p-3 dark:border-amber-900 dark:bg-amber-950/30">
                <div className="flex items-start gap-2">
                  <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
                  <div>
                    <p className="text-sm font-semibold text-foreground">This action is fully audit-logged.</p>
                    <p className="mt-1 text-xs text-muted-foreground">
                      Token expires: {impersonationResult && new Date(impersonationResult.expires_at).toLocaleString()}
                    </p>
                  </div>
                </div>
              </div>
              <div>
                <Label className="text-xs">Bearer Token</Label>
                <div className="mt-1 flex gap-2">
                  <code className="flex-1 rounded bg-background px-3 py-2 text-xs font-mono break-all border">
                    {impersonationResult?.token}
                  </code>
                </div>
                <Button size="sm" variant="outline" className="mt-2" onClick={copyToken}>
                  Copy Token
                </Button>
              </div>
              <p className="text-xs text-muted-foreground">
                Use this token in an <code>Authorization: Bearer ...</code> header to access the tenant&apos;s data on their behalf. The token grants Tenant Admin privileges and expires in 1 hour.
              </p>
            </div>
            <DialogFooter>
              <Button onClick={() => setImpersonateOpen(false)}>Done</Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </div>
    </RoleGate>
  );
}

function StatCard({ icon: Icon, label, value }: { icon: React.ComponentType<{ className?: string }>; label: string; value: string }) {
  return (
    <Card>
      <CardContent className="flex items-center gap-3 p-4">
        <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10">
          <Icon className="h-5 w-5 text-primary" />
        </div>
        <div className="min-w-0">
          <p className="text-xs text-muted-foreground">{label}</p>
          <p className="font-semibold text-foreground truncate capitalize">{value}</p>
        </div>
      </CardContent>
    </Card>
  );
}

function Row({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between border-b pb-2 last:border-0 last:pb-0">
      <span className="text-sm text-muted-foreground">{label}</span>
      <span className="text-sm font-medium text-foreground">{value}</span>
    </div>
  );
}
