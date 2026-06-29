'use client';

import { useState } from 'react';
import { Building2, GitBranch, Users2, Briefcase } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { PageHeader } from '@/components/shared/page-header';
import { RoleGate } from '@/components/shared/role-gate';
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/api/client';

export default function OrganizationPage() {
  const { data: branches, isLoading: branchLoading } = useQuery({
    queryKey: ['organization', 'branches'],
    queryFn: async () => {
      const { data } = await apiClient.get('/organization/branches');
      return data;
    },
  });

  const { data: departments, isLoading: deptLoading } = useQuery({
    queryKey: ['organization', 'departments'],
    queryFn: async () => {
      const { data } = await apiClient.get('/organization/departments');
      return data;
    },
  });

  const { data: positions, isLoading: posLoading } = useQuery({
    queryKey: ['organization', 'positions'],
    queryFn: async () => {
      const { data } = await apiClient.get('/organization/positions');
      return data;
    },
  });

  const isLoading = branchLoading || deptLoading || posLoading;

  return (
    <RoleGate minRole="hr_admin">
    <div className="space-y-6">
      <PageHeader
        title="Organization"
        description="Manage your organizational structure"
      />

      <div className="grid gap-4 sm:grid-cols-3">
        <StatSummaryCard
          icon={Building2}
          title="Branches"
          count={branches?.data?.length ?? 0}
          loading={branchLoading}
        />
        <StatSummaryCard
          icon={GitBranch}
          title="Departments"
          count={departments?.data?.length ?? 0}
          loading={deptLoading}
        />
        <StatSummaryCard
          icon={Briefcase}
          title="Positions"
          count={positions?.data?.length ?? 0}
          loading={posLoading}
        />
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Branches</CardTitle>
          </CardHeader>
          <CardContent>
            {branchLoading ? (
              <Skeleton className="h-20 w-full" />
            ) : !branches?.data?.length ? (
              <p className="text-sm text-muted-foreground">No branches configured</p>
            ) : (
              <div className="space-y-2">
                {branches.data.map((b: { public_id: string; name: string; code: string }) => (
                  <div key={b.public_id} className="flex items-center justify-between rounded-lg border p-3">
                    <span className="text-sm font-medium">{b.name}</span>
                    <span className="text-xs text-muted-foreground">{b.code}</span>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="text-base">Departments</CardTitle>
          </CardHeader>
          <CardContent>
            {deptLoading ? (
              <Skeleton className="h-20 w-full" />
            ) : !departments?.data?.length ? (
              <p className="text-sm text-muted-foreground">No departments configured</p>
            ) : (
              <div className="space-y-2">
                {departments.data.map((d: { public_id: string; name: string; code: string }) => (
                  <div key={d.public_id} className="flex items-center justify-between rounded-lg border p-3">
                    <span className="text-sm font-medium">{d.name}</span>
                    <span className="text-xs text-muted-foreground">{d.code}</span>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
    </RoleGate>
  );
}

function StatSummaryCard({
  icon: Icon,
  title,
  count,
  loading,
}: {
  icon: React.ComponentType<{ className?: string }>;
  title: string;
  count: number;
  loading: boolean;
}) {
  return (
    <Card>
      <CardContent className="flex items-center gap-4 p-4">
        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
          <Icon className="h-5 w-5 text-primary" />
        </div>
        <div>
          <p className="text-sm text-muted-foreground">{title}</p>
          {loading ? (
            <Skeleton className="mt-1 h-6 w-8" />
          ) : (
            <p className="text-2xl font-bold text-foreground">{count}</p>
          )}
        </div>
      </CardContent>
    </Card>
  );
}
