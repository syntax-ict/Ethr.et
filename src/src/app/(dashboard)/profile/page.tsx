'use client';

import { User, Mail, Phone, Shield, Briefcase, Building2, Calendar } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { PageHeader } from '@/components/shared/page-header';
import { useCurrentUser, useCurrentTenant } from '@/features/auth/api';
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/api/client';
import { CurrencyDisplay } from '@/components/shared/currency-display';

export default function ProfilePage() {
  const { data: user, isLoading: userLoading } = useCurrentUser();
  const { data: tenant } = useCurrentTenant();

  const employeeId = (user as { employee_id?: number } | undefined)?.employee_id;
  const { data: employee } = useQuery({
    queryKey: ['profile', 'employee', user?.public_id],
    queryFn: async () => {
      if (!employeeId) return null;
      const { data } = await apiClient.get('/employees/' + employeeId);
      return data;
    },
    enabled: !!employeeId,
  });

  if (userLoading || !user) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-8 w-48" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  const initials = user.email?.slice(0, 2).toUpperCase() ?? 'U';

  return (
    <div className="space-y-6">
      <PageHeader title="My Profile" description="View your account information" />

      <Card>
        <CardContent className="p-6">
          <div className="flex flex-col items-center gap-4 sm:flex-row sm:items-start">
            <Avatar className="h-20 w-20">
              <AvatarFallback className="text-xl font-semibold">{initials}</AvatarFallback>
            </Avatar>
            <div className="flex-1 text-center sm:text-left">
              <h2 className="text-xl font-bold text-foreground">
                {employee?.name ?? user.email.split('@')[0]}
              </h2>
              <p className="text-sm text-muted-foreground">{user.email}</p>
              <div className="mt-2 flex flex-wrap items-center justify-center gap-2 sm:justify-start">
                <Badge variant="outline" className="capitalize">{user.role?.replace(/_/g, ' ')}</Badge>
                <Badge variant="outline" className="capitalize">{user.status}</Badge>
                {user.mfa_enabled && <Badge variant="outline" className="bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300 border-0">MFA Enabled</Badge>}
              </div>
            </div>
          </div>
        </CardContent>
      </Card>

      <div className="grid gap-6 md:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Account Information</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <InfoRow icon={Mail} label="Email" value={user.email} />
            {user.phone && <InfoRow icon={Phone} label="Phone" value={user.phone} />}
            <InfoRow icon={Shield} label="Role" value={user.role?.replace(/_/g, ' ') ?? ''} className="capitalize" />
            <InfoRow icon={Calendar} label="Locale" value={user.locale ?? 'en'} />
            {user.last_login_at && (
              <InfoRow icon={Calendar} label="Last Login" value={new Date(user.last_login_at).toLocaleString()} />
            )}
          </CardContent>
        </Card>

        {tenant && (
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Organization</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <InfoRow icon={Building2} label="Organization" value={tenant.name} />
              <InfoRow icon={Building2} label="Subdomain" value={tenant.subdomain} />
              <InfoRow icon={Shield} label="Status" value={tenant.status} className="capitalize" />
            </CardContent>
          </Card>
        )}

        {employee && (
          <Card className="md:col-span-2">
            <CardHeader>
              <CardTitle className="text-base">Employment Details</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-4 sm:grid-cols-2">
              <InfoRow icon={User} label="Employee Code" value={employee.employee_code ?? '—'} />
              <InfoRow icon={Calendar} label="Hire Date" value={employee.hire_date ?? '—'} />
              <InfoRow icon={Building2} label="Department" value={employee.department?.name ?? '—'} />
              <InfoRow icon={Briefcase} label="Position" value={employee.position?.title ?? employee.position?.name ?? '—'} />
              <InfoRow icon={Building2} label="Branch" value={employee.branch?.name ?? '—'} />
              {employee.salary_cents && (
                <div className="flex items-center justify-between rounded-lg border p-3">
                  <div className="flex items-center gap-2">
                    <Briefcase className="h-4 w-4 text-muted-foreground" />
                    <span className="text-sm text-muted-foreground">Salary</span>
                  </div>
                  <CurrencyDisplay cents={employee.salary_cents} className="text-sm font-semibold text-foreground" />
                </div>
              )}
            </CardContent>
          </Card>
        )}
      </div>
    </div>
  );
}

function InfoRow({ icon: Icon, label, value, className }: {
  icon: React.ComponentType<{ className?: string }>;
  label: string;
  value: string;
  className?: string;
}) {
  return (
    <div className="flex items-center justify-between rounded-lg border p-3">
      <div className="flex items-center gap-2">
        <Icon className="h-4 w-4 text-muted-foreground" />
        <span className="text-sm text-muted-foreground">{label}</span>
      </div>
      <span className={`text-sm font-medium text-foreground ${className ?? ''}`}>{value}</span>
    </div>
  );
}
