import type { Metadata } from 'next';
import { SetupWizard } from '@/features/onboarding/components/setup-wizard';

export const metadata: Metadata = {
  title: 'Setup',
};

export default function SetupPage() {
  return (
    <div className="py-6">
      <div className="mb-8 text-center">
        <h1 className="text-2xl font-bold tracking-tight text-foreground">
          Welcome to ETHR
        </h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Let&apos;s set up your organization in a few steps
        </p>
      </div>
      <SetupWizard />
    </div>
  );
}
