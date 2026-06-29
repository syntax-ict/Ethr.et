'use client';

import { ShieldAlert } from 'lucide-react';
import { Button } from '@/components/ui/button';
import Link from 'next/link';
import { usePermissions } from '@/lib/hooks/usePermissions';

interface RoleGateProps {
  minRole?: string;
  allowedRoles?: string[];
  children: React.ReactNode;
}

export function RoleGate({ minRole, allowedRoles, children }: RoleGateProps) {
  const { isAtLeast, hasRole, role } = usePermissions();

  const allowed = minRole
    ? isAtLeast(minRole)
    : allowedRoles
      ? hasRole(...allowedRoles)
      : true;

  if (!allowed) {
    return (
      <div className="flex flex-col items-center justify-center py-20 text-center">
        <div className="flex h-16 w-16 items-center justify-center rounded-full bg-destructive/10">
          <ShieldAlert className="h-8 w-8 text-destructive" />
        </div>
        <h2 className="mt-4 text-xl font-semibold text-foreground">Access Denied</h2>
        <p className="mt-2 max-w-sm text-sm text-muted-foreground">
          You don&apos;t have permission to access this page. Your role ({role.replace(/_/g, ' ')}) does not have the required access level.
        </p>
        <Button className="mt-6" asChild>
          <Link href="/dashboard">Back to Dashboard</Link>
        </Button>
      </div>
    );
  }

  return <>{children}</>;
}
