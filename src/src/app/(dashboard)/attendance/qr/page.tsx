'use client';

import { useState } from 'react';
import { QRCodeSVG } from 'qrcode.react';
import { QrCode, Printer, RefreshCw, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Input } from '@/components/ui/input';
import { PageHeader } from '@/components/shared/page-header';
import { RoleGate } from '@/components/shared/role-gate';
import { useMutation, useQuery } from '@tanstack/react-query';
import { apiClient } from '@/api/client';
import { toast } from 'sonner';

interface QrResult {
  token: string;
  qr_url?: string;
  expires_at: string;
  branch?: { name: string };
}

export default function QrGeneratorPage() {
  const [branchId, setBranchId] = useState('');
  const [shiftId, setShiftId] = useState('none');
  const [expiry, setExpiry] = useState(30);
  const [result, setResult] = useState<QrResult | null>(null);

  const { data: branches } = useQuery({
    queryKey: ['org', 'branches'],
    queryFn: async () => (await apiClient.get('/organization/branches')).data,
  });

  const { data: shifts } = useQuery({
    queryKey: ['shifts'],
    queryFn: async () => (await apiClient.get('/shifts')).data,
  });

  const generate = useMutation({
    mutationFn: async () => {
      const payload: Record<string, unknown> = { branch_public_id: branchId, expiry_minutes: expiry };
      if (shiftId !== 'none') payload.shift_public_id = shiftId;
      const { data } = await apiClient.get('/attendance/qr/generate', { params: payload });
      return data as QrResult;
    },
    onSuccess: (data) => {
      setResult(data);
      toast.success('QR code generated');
    },
    onError: () => toast.error('Failed to generate QR'),
  });

  return (
    <RoleGate minRole="hr_admin">
      <div className="space-y-6">
        <PageHeader
          title="QR Attendance Code"
          description="Generate a printable QR code for employees to scan from their phones"
        />

        <div className="grid gap-6 lg:grid-cols-[1fr_2fr]">
          <Card>
            <CardHeader><CardTitle className="text-base">Configuration</CardTitle></CardHeader>
            <CardContent className="space-y-4">
              <div>
                <Label>Branch *</Label>
                <Select value={branchId} onValueChange={setBranchId}>
                  <SelectTrigger className="mt-1"><SelectValue placeholder="Select branch" /></SelectTrigger>
                  <SelectContent>
                    {branches?.data?.map((b: { public_id: string; name: string }) => (
                      <SelectItem key={b.public_id} value={b.public_id}>{b.name}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div>
                <Label>Shift (optional)</Label>
                <Select value={shiftId} onValueChange={setShiftId}>
                  <SelectTrigger className="mt-1"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">Any shift</SelectItem>
                    {shifts?.data?.map((s: { public_id: string; name: string }) => (
                      <SelectItem key={s.public_id} value={s.public_id}>{s.name}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div>
                <Label>Expiry (minutes)</Label>
                <Input type="number" value={expiry} onChange={(e) => setExpiry(parseInt(e.target.value) || 30)} min={5} max={480} className="mt-1" />
                <p className="mt-1 text-xs text-muted-foreground">5–480 min. Re-generate when expired.</p>
              </div>
              <Button onClick={() => generate.mutate()} disabled={!branchId || generate.isPending} className="w-full">
                {generate.isPending ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <QrCode className="mr-2 h-4 w-4" />}
                Generate QR Code
              </Button>
            </CardContent>
          </Card>

          <Card className="print:shadow-none print:border-0">
            <CardHeader className="print:hidden">
              <div className="flex items-center justify-between">
                <CardTitle className="text-base">QR Code</CardTitle>
                {result && (
                  <div className="flex gap-2">
                    <Button size="sm" variant="outline" onClick={() => generate.mutate()}>
                      <RefreshCw className="mr-2 h-3 w-3" /> Re-generate
                    </Button>
                    <Button size="sm" onClick={() => window.print()}>
                      <Printer className="mr-2 h-3 w-3" /> Print
                    </Button>
                  </div>
                )}
              </div>
            </CardHeader>
            <CardContent>
              {!result ? (
                <div className="flex flex-col items-center justify-center py-16 text-center text-muted-foreground">
                  <QrCode className="h-12 w-12 opacity-30" />
                  <p className="mt-3 text-sm">Configure on the left and click Generate</p>
                </div>
              ) : (
                <div className="text-center space-y-4 py-6">
                  <div className="inline-block rounded-2xl border-4 border-primary p-6 bg-white">
                    <QRCodeSVG value={result.token} size={280} level="H" />
                  </div>
                  <div>
                    <p className="text-2xl font-bold">{result.branch?.name ?? 'Attendance Check-in'}</p>
                    <p className="mt-2 text-sm text-muted-foreground">
                      Scan this code with the ETHR app to check in.<br />
                      Valid until <span className="font-medium text-foreground">{new Date(result.expires_at).toLocaleString()}</span>
                    </p>
                  </div>
                </div>
              )}
            </CardContent>
          </Card>
        </div>
      </div>
    </RoleGate>
  );
}
