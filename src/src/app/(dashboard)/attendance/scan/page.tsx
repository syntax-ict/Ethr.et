'use client';

import { useEffect, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import { Camera, CheckCircle2, XCircle, ArrowLeft, LogIn, LogOut } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { PageHeader } from '@/components/shared/page-header';
import { apiClient } from '@/api/client';
import { cn } from '@/lib/utils';
import { toast } from 'sonner';

type Status = 'idle' | 'scanning' | 'verifying' | 'success' | 'error';

export default function QrScanPage() {
  const router = useRouter();
  const [type, setType] = useState<'check_in' | 'check_out'>('check_in');
  const [status, setStatus] = useState<Status>('idle');
  const [message, setMessage] = useState('');
  const readerRef = useRef<HTMLDivElement | null>(null);
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const scannerRef = useRef<any>(null);

  useEffect(() => {
    return () => { stopScanner(); };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function startScanner() {
    setStatus('scanning');
    try {
      const { Html5Qrcode } = await import('html5-qrcode');
      if (!readerRef.current) return;
      const scanner = new Html5Qrcode('qr-reader');
      scannerRef.current = scanner;
      await scanner.start(
        { facingMode: 'environment' },
        { fps: 10, qrbox: { width: 250, height: 250 } },
        async (decodedText: string) => {
          await stopScanner();
          await verifyToken(decodedText);
        },
        () => { /* ignore per-frame failures */ }
      );
    } catch (err) {
      console.error(err);
      toast.error('Camera access denied or unavailable');
      setStatus('idle');
    }
  }

  async function stopScanner() {
    if (scannerRef.current) {
      try {
        await scannerRef.current.stop();
        scannerRef.current.clear();
      } catch { /* ignore */ }
      scannerRef.current = null;
    }
  }

  async function verifyToken(qrToken: string) {
    setStatus('verifying');
    try {
      const { data } = await apiClient.post('/attendance/qr', {
        qr_token: qrToken,
        type,
        idempotency_key: `qr-${Date.now()}-${Math.random()}`,
      });
      const empName = data.employee?.name ?? 'Employee';
      setStatus('success');
      setMessage(`${type === 'check_in' ? 'Welcome' : 'Goodbye'}, ${empName}!`);
      setTimeout(() => router.push('/attendance'), 3000);
    } catch (err: unknown) {
      const axiosErr = err as { response?: { data?: { detail?: string } } };
      setMessage(axiosErr.response?.data?.detail ?? 'QR code invalid or expired');
      setStatus('error');
    }
  }

  return (
    <div className="space-y-6">
      <PageHeader title="Scan QR to Check In/Out" description="Point your camera at the QR code at your workplace entrance" />

      <div className="mx-auto max-w-md space-y-4">
        {/* Type selector */}
        <div className="flex rounded-xl border-2 p-1">
          <button
            onClick={() => setType('check_in')}
            disabled={status !== 'idle' && status !== 'scanning'}
            className={cn('flex-1 rounded-lg py-2 font-semibold transition-all flex items-center justify-center gap-2',
              type === 'check_in' ? 'bg-green-600 text-white' : 'text-muted-foreground')}
          >
            <LogIn className="h-4 w-4" /> Check In
          </button>
          <button
            onClick={() => setType('check_out')}
            disabled={status !== 'idle' && status !== 'scanning'}
            className={cn('flex-1 rounded-lg py-2 font-semibold transition-all flex items-center justify-center gap-2',
              type === 'check_out' ? 'bg-orange-600 text-white' : 'text-muted-foreground')}
          >
            <LogOut className="h-4 w-4" /> Check Out
          </button>
        </div>

        {/* Result states */}
        {status === 'success' && (
          <Card className="border-green-300">
            <CardContent className="p-8 text-center space-y-3">
              <CheckCircle2 className="mx-auto h-16 w-16 text-green-600 animate-in zoom-in" />
              <p className="text-xl font-bold">{message}</p>
              <p className="text-xs text-muted-foreground">Redirecting…</p>
            </CardContent>
          </Card>
        )}

        {status === 'error' && (
          <Card className="border-red-300">
            <CardContent className="p-8 text-center space-y-3">
              <XCircle className="mx-auto h-16 w-16 text-red-600 animate-in zoom-in" />
              <p className="font-semibold">{message}</p>
              <Button variant="outline" size="sm" onClick={() => { setStatus('idle'); setMessage(''); }}>
                Try again
              </Button>
            </CardContent>
          </Card>
        )}

        {(status === 'idle' || status === 'scanning' || status === 'verifying') && (
          <Card>
            <CardContent className="p-4">
              <div id="qr-reader" ref={readerRef} className={cn('w-full overflow-hidden rounded-lg', status !== 'scanning' && 'hidden')} />
              {status === 'idle' && (
                <div className="flex flex-col items-center justify-center py-12 text-center space-y-4">
                  <div className="flex h-20 w-20 items-center justify-center rounded-2xl bg-primary/10">
                    <Camera className="h-10 w-10 text-primary" />
                  </div>
                  <p className="text-sm text-muted-foreground max-w-xs">
                    Tap below to start your camera. Allow camera access when prompted.
                  </p>
                  <Button onClick={startScanner}>
                    <Camera className="mr-2 h-4 w-4" /> Start Scanner
                  </Button>
                </div>
              )}
              {status === 'verifying' && (
                <p className="text-center text-sm text-muted-foreground py-4">Verifying QR code…</p>
              )}
            </CardContent>
          </Card>
        )}

        <Button variant="ghost" size="sm" onClick={() => router.back()} className="w-full">
          <ArrowLeft className="mr-2 h-4 w-4" /> Back
        </Button>
      </div>
    </div>
  );
}
