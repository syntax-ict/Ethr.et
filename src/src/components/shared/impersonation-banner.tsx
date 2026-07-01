'use client';

import { useEffect, useState } from 'react';
import { AlertTriangle, X } from 'lucide-react';
import { Button } from '@/components/ui/button';

export function ImpersonationBanner() {
  const [isImpersonating, setIsImpersonating] = useState(false);

  useEffect(() => {
    const flag = localStorage.getItem('impersonating');
    setIsImpersonating(flag === 'true');
  }, []);

  function exitImpersonation() {
    const originalToken = localStorage.getItem('original_access_token');
    const originalTenant = localStorage.getItem('original_tenant');

    if (originalToken) {
      localStorage.setItem('access_token', originalToken);
      localStorage.removeItem('original_access_token');
    }
    if (originalTenant) {
      localStorage.setItem('tenant', originalTenant);
      localStorage.removeItem('original_tenant');
    }
    localStorage.removeItem('impersonating');

    // Redirect back to admin console
    window.location.href = '/admin';
  }

  if (!isImpersonating) return null;

  return (
    <div className="fixed top-0 left-0 right-0 z-50 flex items-center justify-between bg-amber-500 px-4 py-2 text-amber-950">
      <div className="flex items-center gap-2">
        <AlertTriangle className="h-4 w-4 shrink-0" />
        <span className="text-sm font-medium">
          You are impersonating a tenant admin. Actions taken are logged.
        </span>
      </div>
      <Button
        size="sm"
        variant="outline"
        className="h-7 border-amber-700 bg-amber-400 text-amber-950 hover:bg-amber-300 text-xs"
        onClick={exitImpersonation}
      >
        <X className="mr-1 h-3 w-3" />
        Exit Impersonation
      </Button>
    </div>
  );
}
