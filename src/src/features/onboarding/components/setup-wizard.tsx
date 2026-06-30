'use client';

import { useEffect, useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import {
  Rocket, Palette, Building2, Layers, MapPin, UserPlus, Sparkles,
  ChevronLeft, ChevronRight, Loader2, Check, SkipForward, Copy,
  Globe, Mail, Image as ImageIcon, ArrowRight,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Progress } from '@/components/ui/progress';
import { apiClient } from '@/api/client';
import { useQuery } from '@tanstack/react-query';
import { fetchTemplates, applyTemplate, completeOnboarding } from '../api';
import type { OrganizationTemplate } from '../types';
import { toast } from 'sonner';
import { cn } from '@/lib/utils';

interface MeResponse {
  user: { email: string; role: string };
  tenant: { name: string; subdomain: string; type: string | null } | null;
}

const STEPS = [
  { num: 1, title: 'Welcome', icon: Rocket },
  { num: 2, title: 'Organization', icon: Building2 },
  { num: 3, title: 'Branding', icon: Palette },
  { num: 4, title: 'Template', icon: Layers },
  { num: 5, title: 'First Branch', icon: MapPin },
  { num: 6, title: 'Invite Team', icon: UserPlus },
] as const;

const TOTAL_STEPS = STEPS.length;

export function SetupWizard() {
  const router = useRouter();
  const [currentStep, setCurrentStep] = useState(1);

  const { data: me, isLoading } = useQuery<MeResponse>({
    queryKey: ['auth', 'me'],
    queryFn: async () => (await apiClient.get('/auth/me')).data,
  });

  function next() { setCurrentStep((p) => Math.min(p + 1, TOTAL_STEPS + 1)); }
  function back() { setCurrentStep((p) => Math.max(p - 1, 1)); }
  function skip() { next(); }

  async function finish() {
    try {
      await completeOnboarding();
      toast.success('You\'re all set! Welcome to ETHR.');
    } catch {
      toast.success('Setup complete!');
    }
    router.push('/dashboard');
  }

  useEffect(() => {
    if (currentStep > TOTAL_STEPS) finish();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [currentStep]);

  if (isLoading) {
    return (
      <div className="mx-auto max-w-3xl space-y-6 py-8">
        <Skeleton className="h-2 w-full" />
        <Skeleton className="h-96 w-full" />
      </div>
    );
  }

  const progress = ((currentStep - 1) / TOTAL_STEPS) * 100;

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <div className="space-y-3">
        <div className="flex items-center justify-between text-xs text-muted-foreground">
          <span>Step {Math.min(currentStep, TOTAL_STEPS)} of {TOTAL_STEPS}</span>
          <Button variant="ghost" size="sm" className="h-7 text-xs text-muted-foreground" onClick={finish}>
            <SkipForward className="mr-1 h-3 w-3" /> Skip setup, go to dashboard
          </Button>
        </div>
        <Progress value={progress} className="h-1.5" />
        <div className="flex justify-between">
          {STEPS.map((s) => {
            const Icon = s.icon;
            const isDone = s.num < currentStep;
            const isActive = s.num === currentStep;
            return (
              <div key={s.num} className="flex flex-col items-center gap-1">
                <div className={cn(
                  'flex h-7 w-7 items-center justify-center rounded-full border-2 transition-colors',
                  isDone ? 'border-primary bg-primary text-primary-foreground'
                    : isActive ? 'border-primary text-primary'
                    : 'border-muted text-muted-foreground'
                )}>
                  {isDone ? <Check className="h-3 w-3" /> : <Icon className="h-3 w-3" />}
                </div>
                <span className={cn('hidden sm:block text-[10px]', isActive ? 'font-medium text-foreground' : 'text-muted-foreground')}>
                  {s.title}
                </span>
              </div>
            );
          })}
        </div>
      </div>

      <Card>
        <CardContent className="p-6 sm:p-8">
          {currentStep === 1 && <WelcomeStep me={me} onNext={next} />}
          {currentStep === 2 && <OrganizationStep me={me} onNext={next} onBack={back} />}
          {currentStep === 3 && <BrandingStep onNext={next} onBack={back} onSkip={skip} />}
          {currentStep === 4 && <TemplateStep onNext={next} onBack={back} onSkip={skip} />}
          {currentStep === 5 && <BranchStep onNext={next} onBack={back} onSkip={skip} />}
          {currentStep === 6 && <InviteStep me={me} onNext={next} onBack={back} onSkip={skip} />}
          {currentStep > TOTAL_STEPS && (
            <div className="flex flex-col items-center gap-3 py-10">
              <Loader2 className="h-8 w-8 animate-spin text-primary" />
              <p className="text-sm text-muted-foreground">Finalizing your workspace…</p>
            </div>
          )}
        </CardContent>
      </Card>

      <p className="text-center text-xs text-muted-foreground">
        Every step is optional. You can always change these later in Settings.
      </p>
    </div>
  );
}

// ── STEP 1 — Welcome ───────────────────────────────────────────

function WelcomeStep({ me, onNext }: { me?: MeResponse; onNext: () => void }) {
  const tenant = me?.tenant;
  const subdomain = tenant?.subdomain ?? 'your-org';
  const fullUrl = `${subdomain}.ethr.et`;
  const adminEmail = me?.user?.email ?? '';

  function copyUrl() {
    navigator.clipboard.writeText(`https://${fullUrl}`);
    toast.success('URL copied');
  }

  return (
    <div className="space-y-6 text-center">
      <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-primary/10">
        <Sparkles className="h-8 w-8 text-primary" />
      </div>
      <div>
        <h2 className="text-2xl font-bold text-foreground">Welcome to ETHR! 🎉</h2>
        <p className="mt-2 text-sm text-muted-foreground">
          Your workspace <span className="font-semibold text-foreground">{tenant?.name ?? 'Your Organization'}</span> is ready.
        </p>
      </div>

      <div className="mx-auto max-w-md space-y-3 rounded-xl border bg-muted/30 p-5 text-left">
        <div>
          <div className="flex items-center gap-2 text-xs text-muted-foreground">
            <Globe className="h-3 w-3" /> Your workspace URL
          </div>
          <div className="mt-1 flex items-center gap-2">
            <code className="flex-1 rounded bg-background px-3 py-2 text-sm font-mono">{fullUrl}</code>
            <Button size="sm" variant="outline" onClick={copyUrl}><Copy className="h-3 w-3" /></Button>
          </div>
        </div>
        {adminEmail && (
          <div>
            <div className="flex items-center gap-2 text-xs text-muted-foreground">
              <Mail className="h-3 w-3" /> Admin email
            </div>
            <code className="mt-1 block rounded bg-background px-3 py-2 text-sm font-mono">{adminEmail}</code>
          </div>
        )}
      </div>

      <p className="text-sm text-muted-foreground">
        Let&apos;s set up your organization in <span className="font-medium text-foreground">5 quick steps</span>.<br />
        Each one takes less than a minute, and they&apos;re all optional.
      </p>

      <Button size="lg" onClick={onNext} className="min-w-44">
        Let&apos;s go <ArrowRight className="ml-2 h-4 w-4" />
      </Button>
    </div>
  );
}

// ── STEP 2 — Organization ──────────────────────────────────────

function OrganizationStep({ me, onNext, onBack }: { me?: MeResponse; onNext: () => void; onBack: () => void }) {
  const [name, setName] = useState(me?.tenant?.name ?? '');
  const [type, setType] = useState(me?.tenant?.type ?? 'private');
  const [timezone, setTimezone] = useState('Africa/Addis_Ababa');
  const [locale, setLocale] = useState('en');
  const [saving, setSaving] = useState(false);

  async function save() {
    setSaving(true);
    try {
      await apiClient.put('/settings/organization', { name, type, timezone, locale });
      toast.success('Organization details saved');
      onNext();
    } catch {
      toast.error('Save failed');
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="space-y-6">
      <StepHeader
        icon={Building2}
        title="Confirm your organization"
        description="Tell us a bit more about your organization. You can change these anytime."
      />

      <div className="grid gap-4 sm:grid-cols-2">
        <div>
          <Label>Organization Name</Label>
          <Input value={name} onChange={(e) => setName(e.target.value)} className="mt-1" />
        </div>
        <div>
          <Label>Type</Label>
          <Select value={type ?? 'private'} onValueChange={setType}>
            <SelectTrigger className="mt-1"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="private">Private Company</SelectItem>
              <SelectItem value="government">Government</SelectItem>
              <SelectItem value="ngo">NGO</SelectItem>
              <SelectItem value="bank">Bank / Financial</SelectItem>
              <SelectItem value="hospital">Hospital / Healthcare</SelectItem>
              <SelectItem value="manufacturing">Manufacturing</SelectItem>
              <SelectItem value="hotel">Hotel / Hospitality</SelectItem>
              <SelectItem value="education">Education</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div>
          <Label>Timezone</Label>
          <Select value={timezone} onValueChange={setTimezone}>
            <SelectTrigger className="mt-1"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="Africa/Addis_Ababa">Africa/Addis_Ababa (EAT, UTC+3)</SelectItem>
              <SelectItem value="UTC">UTC</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div>
          <Label>Default Language</Label>
          <Select value={locale} onValueChange={setLocale}>
            <SelectTrigger className="mt-1"><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="en">🇬🇧 English</SelectItem>
              <SelectItem value="am">🇪🇹 አማርኛ (Amharic)</SelectItem>
              <SelectItem value="om">🇪🇹 Afaan Oromoo</SelectItem>
              <SelectItem value="ti">🇪🇹 ትግርኛ</SelectItem>
            </SelectContent>
          </Select>
        </div>
      </div>

      <StepFooter onBack={onBack} onPrimary={save} primaryLabel="Save & Continue" isPending={saving} />
    </div>
  );
}

// ── STEP 3 — Branding ──────────────────────────────────────────

const COLOR_PRESETS = [
  { name: 'Ethio Green', primary: '#0d9488', secondary: '#fbbf24', accent: '#dc2626' },
  { name: 'Royal Blue', primary: '#2563eb', secondary: '#f59e0b', accent: '#ec4899' },
  { name: 'Slate Pro', primary: '#475569', secondary: '#0ea5e9', accent: '#10b981' },
  { name: 'Sunset', primary: '#ea580c', secondary: '#9333ea', accent: '#06b6d4' },
  { name: 'Forest', primary: '#16a34a', secondary: '#854d0e', accent: '#dc2626' },
];

function BrandingStep({ onNext, onBack, onSkip }: { onNext: () => void; onBack: () => void; onSkip: () => void }) {
  const [logoUrl, setLogoUrl] = useState('');
  const [preset, setPreset] = useState(0);
  const [saving, setSaving] = useState(false);

  const colors = COLOR_PRESETS[preset];

  async function save() {
    setSaving(true);
    try {
      await apiClient.put('/settings/branding', {
        logo_url: logoUrl || null,
        primary_color: colors.primary,
        secondary_color: colors.secondary,
        accent_color: colors.accent,
      });
      toast.success('Branding saved');
      onNext();
    } catch {
      toast.error('Save failed');
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="space-y-6">
      <StepHeader
        icon={Palette}
        title="Make it yours"
        description="Customize your workspace branding. Optional, but a nice touch."
        optional
      />

      <div className="space-y-4">
        <div>
          <Label>Logo URL (paste an image link)</Label>
          <div className="mt-1 flex gap-2">
            <Input
              value={logoUrl}
              onChange={(e) => setLogoUrl(e.target.value)}
              placeholder="https://yourcompany.com/logo.png"
            />
            {logoUrl && (
              <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border bg-muted/30">
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img src={logoUrl} alt="Logo preview" className="max-h-8 max-w-8 object-contain"
                  onError={(e) => { (e.target as HTMLImageElement).style.display = 'none'; }} />
              </div>
            )}
          </div>
          <p className="mt-1 text-xs text-muted-foreground">
            <ImageIcon className="inline h-3 w-3 mr-1" />
            File upload coming soon — for now paste a public URL.
          </p>
        </div>

        <div>
          <Label>Color Scheme</Label>
          <div className="mt-2 grid gap-2 sm:grid-cols-5">
            {COLOR_PRESETS.map((c, i) => (
              <button
                key={c.name}
                type="button"
                onClick={() => setPreset(i)}
                className={cn(
                  'rounded-lg border-2 p-2 transition-all',
                  preset === i ? 'border-primary shadow-md' : 'border-border hover:border-primary/50'
                )}
              >
                <div className="flex gap-1">
                  <div className="h-6 flex-1 rounded" style={{ background: c.primary }} />
                  <div className="h-6 flex-1 rounded" style={{ background: c.secondary }} />
                  <div className="h-6 flex-1 rounded" style={{ background: c.accent }} />
                </div>
                <p className="mt-2 text-xs font-medium">{c.name}</p>
              </button>
            ))}
          </div>
        </div>

        <div className="rounded-xl border-2 border-dashed p-4">
          <p className="mb-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Preview</p>
          <div className="flex items-center gap-3 rounded-lg p-3" style={{ background: colors.primary }}>
            <div className="flex h-8 w-8 items-center justify-center rounded bg-white/20">
              {logoUrl ? (
                /* eslint-disable-next-line @next/next/no-img-element */
                <img src={logoUrl} alt="" className="max-h-6 max-w-6 object-contain"
                  onError={(e) => { (e.target as HTMLImageElement).style.display = 'none'; }} />
              ) : (
                <span className="text-xs font-bold text-white">E</span>
              )}
            </div>
            <span className="font-semibold text-white">Your Organization</span>
            <div className="ml-auto flex gap-1">
              <span className="h-2 w-2 rounded-full" style={{ background: colors.secondary }} />
              <span className="h-2 w-2 rounded-full" style={{ background: colors.accent }} />
            </div>
          </div>
        </div>
      </div>

      <StepFooter onBack={onBack} onSkip={onSkip} onPrimary={save} primaryLabel="Save & Continue" isPending={saving} />
    </div>
  );
}

// ── STEP 4 — Template ──────────────────────────────────────────

function TemplateStep({ onNext, onBack, onSkip }: { onNext: () => void; onBack: () => void; onSkip: () => void }) {
  const [templates, setTemplates] = useState<OrganizationTemplate[]>([]);
  const [selected, setSelected] = useState<string | null>(null);
  const [applying, setApplying] = useState(false);

  useEffect(() => {
    fetchTemplates().then(setTemplates).catch(() => {});
  }, []);

  async function apply() {
    if (!selected) { onSkip(); return; }
    setApplying(true);
    try {
      await applyTemplate(selected);
      toast.success('Template applied — departments, positions, shifts, and leave types created');
      onNext();
    } catch {
      toast.error('Template apply failed (you can still continue)');
      onNext();
    } finally {
      setApplying(false);
    }
  }

  return (
    <div className="space-y-6">
      <StepHeader
        icon={Layers}
        title="Start from a template?"
        description="Pre-configured departments, positions, shifts, and leave types for your industry. One click sets them all up."
        optional
      />

      {templates.length === 0 ? (
        <p className="py-6 text-center text-sm text-muted-foreground">No templates available — you can skip and add things manually later.</p>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {templates.map((t) => {
            const isSelected = selected === t.slug;
            return (
              <button
                key={t.public_id}
                type="button"
                onClick={() => setSelected(isSelected ? null : t.slug)}
                className={cn(
                  'rounded-xl border-2 p-4 text-left transition-all',
                  isSelected ? 'border-primary bg-primary/5 shadow-md' : 'border-border hover:border-primary/50'
                )}
              >
                <div className="flex items-start justify-between">
                  <span className="text-2xl">{t.icon ?? '🏢'}</span>
                  {isSelected && (
                    <div className="flex h-5 w-5 items-center justify-center rounded-full bg-primary text-primary-foreground">
                      <Check className="h-3 w-3" />
                    </div>
                  )}
                </div>
                <p className="mt-2 font-semibold">{t.name}</p>
                {t.description && <p className="mt-1 text-xs text-muted-foreground line-clamp-2">{t.description}</p>}
                <div className="mt-3 flex flex-wrap gap-1">
                  {t.template_data?.departments && (
                    <Badge variant="outline" className="text-[10px]">{t.template_data.departments.length} depts</Badge>
                  )}
                  {t.template_data?.positions && (
                    <Badge variant="outline" className="text-[10px]">{t.template_data.positions.length} positions</Badge>
                  )}
                  {t.template_data?.leave_types && (
                    <Badge variant="outline" className="text-[10px]">{t.template_data.leave_types.length} leave types</Badge>
                  )}
                </div>
              </button>
            );
          })}
        </div>
      )}

      <StepFooter
        onBack={onBack}
        onSkip={onSkip}
        onPrimary={apply}
        primaryLabel={selected ? 'Apply Template' : 'Continue without template'}
        isPending={applying}
      />
    </div>
  );
}

// ── STEP 5 — First branch ──────────────────────────────────────

function BranchStep({ onNext, onBack, onSkip }: { onNext: () => void; onBack: () => void; onSkip: () => void }) {
  const [name, setName] = useState('Headquarters');
  const [city, setCity] = useState('Addis Ababa');
  const [code, setCode] = useState('HQ');
  const [saving, setSaving] = useState(false);

  async function save() {
    if (!name.trim()) { onSkip(); return; }
    setSaving(true);
    try {
      await apiClient.post('/organization/branches', { name, city, code, is_active: true });
      toast.success(`Branch "${name}" created`);
      onNext();
    } catch {
      toast.error('Could not create branch — continuing anyway');
      onNext();
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="space-y-6">
      <StepHeader
        icon={MapPin}
        title="Add your first branch"
        description="Most organizations have at least one branch (headquarters). You can add more later."
        optional
      />

      <div className="grid gap-4 sm:grid-cols-3">
        <div className="sm:col-span-2">
          <Label>Branch Name</Label>
          <Input value={name} onChange={(e) => setName(e.target.value)} className="mt-1" />
        </div>
        <div>
          <Label>Code</Label>
          <Input value={code} onChange={(e) => setCode(e.target.value)} className="mt-1" />
        </div>
        <div className="sm:col-span-3">
          <Label>City</Label>
          <Input value={city} onChange={(e) => setCity(e.target.value)} className="mt-1" />
        </div>
      </div>

      <StepFooter onBack={onBack} onSkip={onSkip} onPrimary={save} primaryLabel="Create Branch" isPending={saving} />
    </div>
  );
}

// ── STEP 6 — Invite team ───────────────────────────────────────

function InviteStep({ me, onNext, onBack, onSkip }: { me?: MeResponse; onNext: () => void; onBack: () => void; onSkip: () => void }) {
  const [emails, setEmails] = useState('');

  const parsed = useMemo(() =>
    emails.split(/[\s,;\n]+/).map((e) => e.trim()).filter((e) => e.includes('@') && e !== me?.user?.email),
    [emails, me?.user?.email]
  );

  function done() {
    if (parsed.length > 0) {
      toast.success(`${parsed.length} invitation${parsed.length > 1 ? 's' : ''} noted (add them properly from Employees later)`);
    }
    onNext();
  }

  return (
    <div className="space-y-6">
      <StepHeader
        icon={UserPlus}
        title="Invite your team"
        description="Add HR admins and managers who'll help run your workspace. You can invite more from the Employees page anytime."
        optional
      />

      <div>
        <Label>Email addresses</Label>
        <textarea
          value={emails}
          onChange={(e) => setEmails(e.target.value)}
          placeholder="hr@yourcompany.com&#10;manager@yourcompany.com&#10;finance@yourcompany.com"
          rows={5}
          className="mt-1 flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        />
        <p className="mt-1 text-xs text-muted-foreground">
          One email per line, or comma-separated.{' '}
          {parsed.length > 0 && <span className="font-medium text-foreground">{parsed.length} valid email{parsed.length > 1 ? 's' : ''} detected.</span>}
        </p>
      </div>

      <div className="rounded-lg border bg-muted/30 p-3 text-xs text-muted-foreground">
        💡 <span className="font-medium text-foreground">Tip:</span> Want to bulk-add dozens of employees? Skip this and use <span className="font-mono">Employees → Import CSV</span> from the dashboard.
      </div>

      <StepFooter onBack={onBack} onSkip={onSkip} onPrimary={done} primaryLabel="Finish Setup" />
    </div>
  );
}

// ── Shared bits ────────────────────────────────────────────────

function StepHeader({ icon: Icon, title, description, optional }: {
  icon: React.ComponentType<{ className?: string }>;
  title: string; description: string; optional?: boolean;
}) {
  return (
    <div className="space-y-2">
      <div className="flex items-center gap-3">
        <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10">
          <Icon className="h-5 w-5 text-primary" />
        </div>
        <h2 className="text-xl font-bold text-foreground">{title}</h2>
        {optional && <Badge variant="outline" className="text-[10px]">Optional</Badge>}
      </div>
      <p className="text-sm text-muted-foreground">{description}</p>
    </div>
  );
}

function StepFooter({ onBack, onSkip, onPrimary, primaryLabel, isPending }: {
  onBack: () => void;
  onSkip?: () => void;
  onPrimary: () => void;
  primaryLabel: string;
  isPending?: boolean;
}) {
  return (
    <div className="flex items-center justify-between gap-2 border-t pt-4">
      <Button variant="ghost" size="sm" onClick={onBack}>
        <ChevronLeft className="mr-1 h-4 w-4" /> Back
      </Button>
      <div className="flex gap-2">
        {onSkip && (
          <Button variant="ghost" size="sm" onClick={onSkip}>
            Skip this step
          </Button>
        )}
        <Button onClick={onPrimary} disabled={isPending}>
          {isPending ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : null}
          {primaryLabel}
          {!isPending && <ChevronRight className="ml-1 h-4 w-4" />}
        </Button>
      </div>
    </div>
  );
}
