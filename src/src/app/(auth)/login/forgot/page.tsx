'use client';

import { useState } from 'react';
import Link from 'next/link';
import { ArrowLeft, MailCheck, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import axios from 'axios';

function detectTenantFromHost(): string | null {
  if (typeof window === 'undefined') return null;
  const parts = window.location.host.split('.');
  if (parts.length >= 3) return parts[0];
  if (parts.length === 2 && !['localhost', 'test'].includes(parts[1].split(':')[0])) return parts[0];
  return null;
}

export default function ForgotPasswordPage() {
  const subdomainFromHost = typeof window !== 'undefined' ? detectTenantFromHost() : null;
  const [tenant, setTenant] = useState(() =>
    typeof window !== 'undefined' ? (localStorage.getItem('tenant') ?? '') : ''
  );
  const [email, setEmail] = useState('');
  const [loading, setLoading] = useState(false);
  const [sent, setSent] = useState(false);
  const [error, setError] = useState('');

  const effectiveTenant = subdomainFromHost ?? tenant.trim().toLowerCase();
  const tenantFieldVisible = !subdomainFromHost;

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!effectiveTenant) {
      setError('Please enter your organization subdomain.');
      return;
    }
    setLoading(true);
    setError('');

    try {
      const headers: Record<string, string> = { 'Content-Type': 'application/json', Accept: 'application/json' };
      if (!subdomainFromHost) headers['X-Tenant'] = effectiveTenant;

      await axios.post('/api/v1/auth/password/forgot', { email, tenant: effectiveTenant }, { headers });
      setSent(true);
    } catch (err: unknown) {
      const axiosError = err as { response?: { data?: { detail?: string; message?: string } } };
      setError(axiosError.response?.data?.detail || axiosError.response?.data?.message || 'Something went wrong. Try again.');
    } finally {
      setLoading(false);
    }
  }

  if (sent) {
    return (
      <div className="w-full max-w-sm text-center">
        <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-green-100 dark:bg-green-950">
          <MailCheck className="h-7 w-7 text-green-600 dark:text-green-400" />
        </div>
        <h1 className="mt-4 text-2xl font-bold text-foreground">Check your email</h1>
        <p className="mt-2 text-sm text-muted-foreground">
          If an account with <span className="font-medium">{email}</span> exists in <span className="font-medium">{effectiveTenant}</span>, we&apos;ve sent a password reset link.
        </p>
        <p className="mt-1 text-xs text-muted-foreground">The link expires in 60 minutes.</p>
        <div className="mt-6 space-y-2">
          <Button asChild variant="outline" className="w-full">
            <Link href="/login"><ArrowLeft className="mr-2 h-4 w-4" /> Back to sign in</Link>
          </Button>
          <Button variant="ghost" className="w-full text-xs text-muted-foreground" onClick={() => { setSent(false); setError(''); }}>
            Didn&apos;t receive an email? Try again
          </Button>
        </div>
      </div>
    );
  }

  return (
    <div className="w-full max-w-sm">
      <div className="mb-8 text-center">
        <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-primary">
          <span className="text-lg font-bold text-primary-foreground">E</span>
        </div>
        <h1 className="mt-4 text-2xl font-bold text-foreground">Forgot your password?</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Enter your email and we&apos;ll send you a reset link.
        </p>
      </div>

      <form onSubmit={handleSubmit} className="space-y-4">
        {tenantFieldVisible && (
          <div className="space-y-2">
            <Label htmlFor="tenant">Organization subdomain</Label>
            <div className="flex items-center rounded-md border border-input focus-within:ring-2 focus-within:ring-ring">
              <Input
                id="tenant"
                value={tenant}
                onChange={(e) => setTenant(e.target.value)}
                placeholder="acme"
                required
                className="border-0 focus-visible:ring-0"
              />
              <span className="px-3 text-sm text-muted-foreground border-l">.ethr.et</span>
            </div>
          </div>
        )}
        <div className="space-y-2">
          <Label htmlFor="email">Email</Label>
          <Input
            id="email"
            type="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
            autoComplete="email"
            autoFocus
          />
        </div>

        {error && (
          <div className="rounded-lg bg-destructive/10 p-3">
            <p className="text-sm text-destructive">{error}</p>
          </div>
        )}

        <Button type="submit" className="w-full" disabled={loading}>
          {loading ? <><Loader2 className="mr-2 h-4 w-4 animate-spin" /> Sending…</> : 'Send reset link'}
        </Button>

        <Link href="/login" className="block text-center text-sm text-muted-foreground hover:text-foreground">
          <ArrowLeft className="inline h-3 w-3 mr-1" /> Back to sign in
        </Link>
      </form>
    </div>
  );
}
