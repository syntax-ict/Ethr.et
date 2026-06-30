'use client';

import { useEffect, useState } from 'react';
import { LogIn, LogOut, CheckCircle2, XCircle, Clock, Building2, KeyRound } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { apiClient } from '@/api/client';
import { cn } from '@/lib/utils';

type Mode = 'idle' | 'checking' | 'success' | 'error';

export default function KioskPage() {
  const [tenant, setTenant] = useState('');
  const [tenantLocked, setTenantLocked] = useState(false);
  const [code, setCode] = useState('');
  const [pin, setPin] = useState<string[]>([]);
  const [type, setType] = useState<'check_in' | 'check_out'>('check_in');
  const [mode, setMode] = useState<Mode>('idle');
  const [message, setMessage] = useState('');
  const [now, setNow] = useState(new Date());

  // Pre-fill tenant from URL or localStorage
  useEffect(() => {
    if (typeof window === 'undefined') return;
    const parts = window.location.host.split('.');
    const fromUrl = parts.length >= 3 ? parts[0] : null;
    const fromStorage = localStorage.getItem('kiosk_tenant') ?? localStorage.getItem('tenant');
    const t = fromUrl ?? fromStorage;
    if (t) {
      setTenant(t);
      setTenantLocked(true);
    }
  }, []);

  // Live clock
  useEffect(() => {
    const id = setInterval(() => setNow(new Date()), 1000);
    return () => clearInterval(id);
  }, []);

  // Auto-reset after success/error
  useEffect(() => {
    if (mode === 'success' || mode === 'error') {
      const id = setTimeout(() => {
        setMode('idle');
        setCode('');
        setPin([]);
        setMessage('');
      }, 4000);
      return () => clearTimeout(id);
    }
  }, [mode]);

  function pressDigit(d: string) {
    if (pin.length >= 8) return;
    setPin((p) => [...p, d]);
    setCode((p) => p + d);
  }

  function backspace() {
    setPin((p) => p.slice(0, -1));
    setCode((p) => p.slice(0, -1));
  }

  function clear() {
    setPin([]);
    setCode('');
  }

  async function submit() {
    if (!code || !tenant) return;
    setMode('checking');
    try {
      const { data } = await apiClient.post('/attendance/kiosk', {
        employee_code: code,
        type,
        idempotency_key: `kiosk-${Date.now()}-${Math.random()}`,
      }, {
        headers: { 'X-Tenant': tenant },
      });
      const empName = data.employee?.name ?? 'Employee';
      setMode('success');
      setMessage(`${type === 'check_in' ? 'Welcome' : 'Goodbye'}, ${empName}!`);
    } catch (err: unknown) {
      const axiosErr = err as { response?: { data?: { detail?: string } } };
      setMessage(axiosErr.response?.data?.detail ?? 'Employee not found or check-in failed.');
      setMode('error');
    }
  }

  function lockTenant() {
    if (!tenant.trim()) return;
    localStorage.setItem('kiosk_tenant', tenant.trim().toLowerCase());
    setTenantLocked(true);
  }

  if (!tenantLocked) {
    return (
      <div className="min-h-screen flex items-center justify-center p-6">
        <Card className="max-w-md w-full">
          <CardContent className="p-8 space-y-4">
            <div className="text-center">
              <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-primary/10">
                <Building2 className="h-7 w-7 text-primary" />
              </div>
              <h1 className="mt-4 text-2xl font-bold">Kiosk Setup</h1>
              <p className="text-sm text-muted-foreground mt-1">Which organization is this kiosk for?</p>
            </div>
            <div>
              <Label>Organization subdomain</Label>
              <Input
                value={tenant}
                onChange={(e) => setTenant(e.target.value)}
                placeholder="acme"
                className="mt-1"
                autoFocus
              />
            </div>
            <Button onClick={lockTenant} className="w-full" disabled={!tenant.trim()}>
              Start Kiosk
            </Button>
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <div className="min-h-screen flex flex-col">
      {/* Header bar */}
      <header className="border-b bg-card px-6 py-4 flex items-center justify-between">
        <div className="flex items-center gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary text-primary-foreground font-bold">E</div>
          <div>
            <p className="text-lg font-bold">ETHR Kiosk</p>
            <p className="text-xs text-muted-foreground font-mono">{tenant}.ethr.et</p>
          </div>
        </div>
        <div className="text-right">
          <p className="text-3xl font-bold font-mono">{now.toLocaleTimeString('en-ET', { hour: '2-digit', minute: '2-digit' })}</p>
          <p className="text-xs text-muted-foreground">{now.toLocaleDateString('en-ET', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}</p>
        </div>
      </header>

      {/* Main */}
      <main className="flex-1 flex items-center justify-center p-6">
        {mode === 'success' ? (
          <div className="text-center space-y-6 animate-in zoom-in duration-300">
            <div className="mx-auto flex h-32 w-32 items-center justify-center rounded-full bg-green-100 dark:bg-green-950">
              <CheckCircle2 className="h-20 w-20 text-green-600 dark:text-green-400" />
            </div>
            <p className="text-4xl font-bold text-foreground">{message}</p>
            <p className="text-sm text-muted-foreground">Recorded at {now.toLocaleTimeString()}</p>
          </div>
        ) : mode === 'error' ? (
          <div className="text-center space-y-6 animate-in zoom-in duration-300">
            <div className="mx-auto flex h-32 w-32 items-center justify-center rounded-full bg-red-100 dark:bg-red-950">
              <XCircle className="h-20 w-20 text-red-600 dark:text-red-400" />
            </div>
            <p className="text-3xl font-bold text-foreground">{message}</p>
            <p className="text-sm text-muted-foreground">Please try again</p>
          </div>
        ) : (
          <div className="w-full max-w-md space-y-6">
            {/* Mode switcher */}
            <div className="flex rounded-xl border-2 p-1">
              <button
                onClick={() => setType('check_in')}
                className={cn('flex-1 rounded-lg py-3 font-semibold transition-all flex items-center justify-center gap-2',
                  type === 'check_in' ? 'bg-green-600 text-white shadow-md' : 'text-muted-foreground')}
              >
                <LogIn className="h-4 w-4" /> Check In
              </button>
              <button
                onClick={() => setType('check_out')}
                className={cn('flex-1 rounded-lg py-3 font-semibold transition-all flex items-center justify-center gap-2',
                  type === 'check_out' ? 'bg-orange-600 text-white shadow-md' : 'text-muted-foreground')}
              >
                <LogOut className="h-4 w-4" /> Check Out
              </button>
            </div>

            {/* Display */}
            <Card>
              <CardContent className="p-6 text-center">
                <Label className="text-xs uppercase tracking-wider text-muted-foreground flex items-center justify-center gap-1">
                  <KeyRound className="h-3 w-3" /> Employee Code
                </Label>
                <div className="mt-3 flex justify-center gap-2">
                  {Array.from({ length: 8 }).map((_, i) => (
                    <div key={i} className={cn(
                      'h-12 w-8 rounded border-2 flex items-center justify-center text-2xl font-mono font-bold',
                      pin[i] ? 'border-primary bg-primary/5' : 'border-muted'
                    )}>
                      {pin[i] ?? ''}
                    </div>
                  ))}
                </div>
              </CardContent>
            </Card>

            {/* Number pad */}
            <div className="grid grid-cols-3 gap-3">
              {['1', '2', '3', '4', '5', '6', '7', '8', '9'].map((d) => (
                <Button
                  key={d}
                  variant="outline"
                  className="h-16 text-2xl font-bold"
                  onClick={() => pressDigit(d)}
                  disabled={mode === 'checking'}
                >
                  {d}
                </Button>
              ))}
              <Button variant="outline" className="h-16" onClick={clear} disabled={mode === 'checking'}>Clear</Button>
              <Button variant="outline" className="h-16 text-2xl font-bold" onClick={() => pressDigit('0')} disabled={mode === 'checking'}>0</Button>
              <Button variant="outline" className="h-16" onClick={backspace} disabled={mode === 'checking'}>⌫</Button>
            </div>

            <Button
              className="w-full h-14 text-lg"
              onClick={submit}
              disabled={mode === 'checking' || code.length === 0}
            >
              {mode === 'checking' ? (
                <span className="flex items-center gap-2">
                  <Clock className="h-5 w-5 animate-spin" /> Processing…
                </span>
              ) : type === 'check_in' ? 'Check In' : 'Check Out'}
            </Button>
          </div>
        )}
      </main>

      <footer className="border-t bg-muted/30 px-6 py-2 text-center text-xs text-muted-foreground">
        Type your employee code and tap Check In/Out · Kiosk mode
      </footer>
    </div>
  );
}
