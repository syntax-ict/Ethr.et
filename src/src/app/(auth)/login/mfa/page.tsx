'use client';

import { useEffect, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import { ArrowLeft, ShieldCheck, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import axios from 'axios';
import { toast } from 'sonner';

const CODE_LENGTH = 6;

export default function MfaChallengePage() {
  const router = useRouter();
  const [digits, setDigits] = useState<string[]>(Array(CODE_LENGTH).fill(''));
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const inputsRef = useRef<Array<HTMLInputElement | null>>([]);

  // Verify we have a temp MFA token; otherwise bounce back to login.
  useEffect(() => {
    if (typeof window === 'undefined') return;
    const tempToken = localStorage.getItem('mfa_token');
    if (!tempToken) {
      router.replace('/login');
      return;
    }
    inputsRef.current[0]?.focus();
  }, [router]);

  function setDigit(i: number, value: string) {
    const clean = value.replace(/\D/g, '').slice(0, 1);
    setDigits((prev) => {
      const next = [...prev];
      next[i] = clean;
      return next;
    });
    if (clean && i < CODE_LENGTH - 1) inputsRef.current[i + 1]?.focus();
  }

  function handleKeyDown(i: number, e: React.KeyboardEvent<HTMLInputElement>) {
    if (e.key === 'Backspace' && !digits[i] && i > 0) {
      inputsRef.current[i - 1]?.focus();
    } else if (e.key === 'ArrowLeft' && i > 0) {
      inputsRef.current[i - 1]?.focus();
    } else if (e.key === 'ArrowRight' && i < CODE_LENGTH - 1) {
      inputsRef.current[i + 1]?.focus();
    }
  }

  function handlePaste(e: React.ClipboardEvent<HTMLInputElement>) {
    e.preventDefault();
    const pasted = e.clipboardData.getData('text').replace(/\D/g, '').slice(0, CODE_LENGTH);
    if (pasted.length === 0) return;
    const next = Array(CODE_LENGTH).fill('');
    for (let i = 0; i < pasted.length; i++) next[i] = pasted[i];
    setDigits(next);
    const focusIdx = Math.min(pasted.length, CODE_LENGTH - 1);
    inputsRef.current[focusIdx]?.focus();
    if (pasted.length === CODE_LENGTH) {
      // Auto-submit on full paste
      setTimeout(() => verifyCode(pasted), 100);
    }
  }

  async function verifyCode(code: string) {
    setLoading(true);
    setError('');

    const tempToken = localStorage.getItem('mfa_token');
    const tenant = localStorage.getItem('tenant');

    if (!tempToken) {
      router.replace('/login');
      return;
    }

    try {
      // Use a bare axios call so we can pass the temp token directly without
      // colliding with the apiClient interceptor's stored access_token.
      const headers: Record<string, string> = {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        Authorization: `Bearer ${tempToken}`,
      };
      if (tenant) headers['X-Tenant'] = tenant;

      const { data } = await axios.post('/api/v1/auth/mfa/verify', { code }, { headers });

      if (data.access_token) {
        localStorage.setItem('access_token', data.access_token);
        localStorage.removeItem('mfa_token');
        toast.success('Authenticated');
        router.push('/dashboard');
      } else {
        throw new Error('No token returned');
      }
    } catch (err: unknown) {
      const axiosError = err as { response?: { data?: { detail?: string } } };
      setError(axiosError.response?.data?.detail || 'Invalid code. Try again.');
      setDigits(Array(CODE_LENGTH).fill(''));
      inputsRef.current[0]?.focus();
    } finally {
      setLoading(false);
    }
  }

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    const code = digits.join('');
    if (code.length !== CODE_LENGTH) return;
    verifyCode(code);
  }

  function cancel() {
    localStorage.removeItem('mfa_token');
    router.replace('/login');
  }

  return (
    <div className="w-full max-w-sm">
      <div className="mb-8 text-center">
        <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-primary/10">
          <ShieldCheck className="h-6 w-6 text-primary" />
        </div>
        <h1 className="mt-4 text-2xl font-bold text-foreground">Two-factor authentication</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Enter the 6-digit code from your authenticator app
        </p>
      </div>

      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="space-y-2">
          <Label className="sr-only">Verification code</Label>
          <div className="flex justify-center gap-2">
            {digits.map((digit, i) => (
              <input
                key={i}
                ref={(el) => { inputsRef.current[i] = el; }}
                type="text"
                inputMode="numeric"
                pattern="\d*"
                maxLength={1}
                value={digit}
                onChange={(e) => setDigit(i, e.target.value)}
                onKeyDown={(e) => handleKeyDown(i, e)}
                onPaste={i === 0 ? handlePaste : undefined}
                disabled={loading}
                className="h-12 w-10 rounded-md border border-input bg-background text-center text-xl font-mono ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50"
                autoComplete={i === 0 ? 'one-time-code' : 'off'}
              />
            ))}
          </div>
          <p className="text-center text-xs text-muted-foreground">
            Paste a code or type each digit
          </p>
        </div>

        {error && (
          <div className="rounded-lg bg-destructive/10 p-3">
            <p className="text-sm text-destructive">{error}</p>
          </div>
        )}

        <Button type="submit" className="w-full" disabled={loading || digits.join('').length !== CODE_LENGTH}>
          {loading ? (
            <>
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              Verifying…
            </>
          ) : (
            'Verify'
          )}
        </Button>

        <div className="flex items-center justify-between text-xs">
          <button type="button" onClick={cancel} className="flex items-center gap-1 text-muted-foreground hover:text-foreground">
            <ArrowLeft className="h-3 w-3" /> Back to sign in
          </button>
          <Link href="/login/recovery" className="text-primary hover:underline">
            Use a recovery code
          </Link>
        </div>
      </form>

      <div className="mt-8 rounded-lg border bg-muted/30 p-3 text-xs text-muted-foreground">
        💡 <span className="font-medium text-foreground">Lost your device?</span> Use one of the recovery codes you saved when you enabled MFA, or contact your tenant admin.
      </div>
    </div>
  );
}
