'use client';

import { useState } from 'react';
import { ShieldCheck, ShieldOff, Loader2, Copy, AlertCircle, Eye, EyeOff, KeyRound } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { PageHeader } from '@/components/shared/page-header';
import { useCurrentUser } from '@/features/auth/api';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/api/client';
import { toast } from 'sonner';

export default function SecurityPage() {
  const { data: user } = useCurrentUser();
  const queryClient = useQueryClient();
  const [setupOpen, setSetupOpen] = useState(false);
  const [setupData, setSetupData] = useState<{ secret: string; qr_code_url: string; recovery_codes: string[] } | null>(null);
  const [code, setCode] = useState('');
  const [disableOpen, setDisableOpen] = useState(false);
  const [disableCode, setDisableCode] = useState('');
  const [changeOpen, setChangeOpen] = useState(false);
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [showNew, setShowNew] = useState(false);
  const [changeError, setChangeError] = useState('');

  const startSetup = useMutation({
    mutationFn: async () => {
      const { data } = await apiClient.post('/auth/mfa/setup');
      return data;
    },
    onSuccess: (data) => {
      setSetupData(data);
      setSetupOpen(true);
    },
    onError: () => toast.error('Failed to initiate MFA setup'),
  });

  const enableMfa = useMutation({
    mutationFn: async () => {
      const { data } = await apiClient.post('/auth/mfa/enable', { code });
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['auth', 'me'] });
      toast.success('Two-factor authentication enabled');
      setSetupOpen(false);
      setSetupData(null);
      setCode('');
    },
    onError: () => toast.error('Invalid verification code'),
  });

  const disableMfa = useMutation({
    mutationFn: async () => {
      const { data } = await apiClient.post('/auth/mfa/disable', { code: disableCode });
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['auth', 'me'] });
      toast.success('Two-factor authentication disabled');
      setDisableOpen(false);
      setDisableCode('');
    },
    onError: () => toast.error('Invalid verification code'),
  });

  const changePassword = useMutation({
    mutationFn: async () => {
      const { data } = await apiClient.post('/auth/password/change', {
        current_password: currentPassword,
        password: newPassword,
        password_confirmation: confirmPassword,
      });
      return data;
    },
    onSuccess: (data: { message: string }) => {
      toast.success(data.message ?? 'Password changed');
      setChangeOpen(false);
      setCurrentPassword('');
      setNewPassword('');
      setConfirmPassword('');
      setChangeError('');
    },
    onError: (err: unknown) => {
      const e = err as { response?: { data?: { detail?: string; message?: string; errors?: Record<string, string[]> } } };
      const errors = e.response?.data?.errors;
      const first = errors ? Object.values(errors)[0]?.[0] : undefined;
      setChangeError(first || e.response?.data?.detail || e.response?.data?.message || 'Failed to change password');
    },
  });

  function submitChangePassword(e: React.FormEvent) {
    e.preventDefault();
    setChangeError('');
    if (newPassword !== confirmPassword) {
      setChangeError('New passwords do not match.');
      return;
    }
    if (newPassword.length < 8) {
      setChangeError('New password must be at least 8 characters.');
      return;
    }
    if (newPassword === currentPassword) {
      setChangeError('New password must be different from your current password.');
      return;
    }
    changePassword.mutate();
  }

  function copyCodes() {
    if (setupData?.recovery_codes) {
      navigator.clipboard.writeText(setupData.recovery_codes.join('\n'));
      toast.success('Recovery codes copied');
    }
  }

  return (
    <div className="space-y-6">
      <PageHeader title="Security" description="Manage your account security and two-factor authentication" />

      <Card>
        <CardHeader>
          <CardTitle className="text-base">Two-Factor Authentication</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="flex items-start justify-between gap-4">
            <div className="flex items-start gap-3">
              {user?.mfa_enabled ? (
                <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-green-100 dark:bg-green-950">
                  <ShieldCheck className="h-5 w-5 text-green-600 dark:text-green-400" />
                </div>
              ) : (
                <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-100 dark:bg-amber-950">
                  <ShieldOff className="h-5 w-5 text-amber-600 dark:text-amber-400" />
                </div>
              )}
              <div>
                <div className="flex items-center gap-2">
                  <p className="font-semibold text-foreground">Authenticator App</p>
                  {user?.mfa_enabled && (
                    <Badge variant="outline" className="bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300 border-0">Enabled</Badge>
                  )}
                </div>
                <p className="mt-1 text-sm text-muted-foreground">
                  {user?.mfa_enabled
                    ? 'Your account is protected with two-factor authentication'
                    : 'Add an extra layer of security to your account'}
                </p>
              </div>
            </div>
            {user?.mfa_enabled ? (
              <Button variant="outline" onClick={() => setDisableOpen(true)}>Disable</Button>
            ) : (
              <Button onClick={() => startSetup.mutate()} disabled={startSetup.isPending}>
                {startSetup.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                Enable MFA
              </Button>
            )}
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">Password</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="flex items-center justify-between">
            <div>
              <p className="font-semibold text-foreground">Account Password</p>
              <p className="mt-1 text-sm text-muted-foreground">Use a strong password unique to ETHR</p>
            </div>
            <Button variant="outline" onClick={() => setChangeOpen(true)}>
              <KeyRound className="mr-2 h-4 w-4" /> Change Password
            </Button>
          </div>
        </CardContent>
      </Card>

      <Dialog open={setupOpen} onOpenChange={setSetupOpen}>
        <DialogContent>
          <DialogHeader><DialogTitle>Set up Two-Factor Authentication</DialogTitle></DialogHeader>

          {setupData && (
            <div className="space-y-4">
              <div>
                <p className="text-sm text-muted-foreground">
                  1. Scan this QR code with Google Authenticator, Authy, or another TOTP app:
                </p>
                {setupData.qr_code_url && (
                  <div className="mt-3 flex justify-center rounded-lg border bg-white p-4">
                    {/* eslint-disable-next-line @next/next/no-img-element */}
                    <img src={setupData.qr_code_url} alt="MFA QR Code" className="h-48 w-48" />
                  </div>
                )}
                <p className="mt-3 text-xs text-muted-foreground">Or enter this secret manually:</p>
                <code className="mt-1 block rounded bg-muted px-3 py-2 text-xs font-mono break-all">{setupData.secret}</code>
              </div>

              {setupData.recovery_codes && setupData.recovery_codes.length > 0 && (
                <div className="rounded-lg border-2 border-amber-300 bg-amber-50 p-3 dark:bg-amber-950/30">
                  <div className="flex items-start gap-2">
                    <AlertCircle className="mt-0.5 h-4 w-4 text-amber-600" />
                    <div className="flex-1">
                      <p className="text-sm font-semibold text-amber-900 dark:text-amber-300">Recovery Codes</p>
                      <p className="mt-1 text-xs text-amber-900 dark:text-amber-300/80">Save these in a secure place. You can use them to access your account if you lose your device.</p>
                      <div className="mt-2 grid grid-cols-2 gap-1 font-mono text-xs">
                        {setupData.recovery_codes.map((c) => <code key={c} className="rounded bg-background px-2 py-1">{c}</code>)}
                      </div>
                      <Button size="sm" variant="outline" className="mt-2" onClick={copyCodes}>
                        <Copy className="mr-2 h-3 w-3" /> Copy all
                      </Button>
                    </div>
                  </div>
                </div>
              )}

              <div>
                <Label>2. Enter the 6-digit code from your app:</Label>
                <Input
                  value={code}
                  onChange={(e) => setCode(e.target.value)}
                  placeholder="000000"
                  maxLength={6}
                  className="mt-1 text-center text-2xl font-mono tracking-widest"
                />
              </div>

              <DialogFooter>
                <Button type="button" variant="outline" onClick={() => setSetupOpen(false)}>Cancel</Button>
                <Button onClick={() => enableMfa.mutate()} disabled={enableMfa.isPending || code.length !== 6}>
                  {enableMfa.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                  Verify & Enable
                </Button>
              </DialogFooter>
            </div>
          )}
        </DialogContent>
      </Dialog>

      <Dialog open={changeOpen} onOpenChange={(o) => { setChangeOpen(o); if (!o) setChangeError(''); }}>
        <DialogContent>
          <DialogHeader><DialogTitle>Change Password</DialogTitle></DialogHeader>
          <form onSubmit={submitChangePassword} className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="current_password">Current password</Label>
              <Input
                id="current_password"
                type="password"
                value={currentPassword}
                onChange={(e) => setCurrentPassword(e.target.value)}
                autoComplete="current-password"
                required
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="new_password">New password</Label>
              <div className="relative">
                <Input
                  id="new_password"
                  type={showNew ? 'text' : 'password'}
                  value={newPassword}
                  onChange={(e) => setNewPassword(e.target.value)}
                  autoComplete="new-password"
                  required
                  minLength={8}
                  className="pr-10"
                />
                <button
                  type="button"
                  onClick={() => setShowNew(!showNew)}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                  tabIndex={-1}
                >
                  {showNew ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                </button>
              </div>
              <p className="text-xs text-muted-foreground">At least 8 characters, different from your current password.</p>
            </div>
            <div className="space-y-2">
              <Label htmlFor="confirm_password">Confirm new password</Label>
              <Input
                id="confirm_password"
                type={showNew ? 'text' : 'password'}
                value={confirmPassword}
                onChange={(e) => setConfirmPassword(e.target.value)}
                autoComplete="new-password"
                required
              />
            </div>
            {changeError && (
              <div className="rounded-lg bg-destructive/10 p-3">
                <p className="text-sm text-destructive">{changeError}</p>
              </div>
            )}
            <p className="text-xs text-muted-foreground">
              Note: Changing your password will sign out all your other active sessions.
            </p>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setChangeOpen(false)}>Cancel</Button>
              <Button type="submit" disabled={changePassword.isPending}>
                {changePassword.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                Change Password
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      <Dialog open={disableOpen} onOpenChange={setDisableOpen}>
        <DialogContent>
          <DialogHeader><DialogTitle>Disable Two-Factor Authentication</DialogTitle></DialogHeader>
          <p className="text-sm text-muted-foreground">Enter your current authenticator code to confirm:</p>
          <Input
            value={disableCode}
            onChange={(e) => setDisableCode(e.target.value)}
            placeholder="000000"
            maxLength={6}
            className="text-center text-2xl font-mono tracking-widest"
          />
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setDisableOpen(false)}>Cancel</Button>
            <Button variant="destructive" onClick={() => disableMfa.mutate()} disabled={disableMfa.isPending || disableCode.length !== 6}>
              {disableMfa.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              Disable MFA
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
