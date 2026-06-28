'use client';

import { useState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { WizardProgress } from './wizard-progress';
import { StepOrgProfile } from './step-org-profile';
import { StepPlaceholder } from './step-placeholder';
import { fetchTemplates, fetchProgress, updateStep, applyTemplate, completeOnboarding } from '../api';
import type { OnboardingProgress, OrganizationTemplate } from '../types';

export function SetupWizard() {
  const router = useRouter();
  const [loading, setLoading] = useState(true);
  const [currentStep, setCurrentStep] = useState(1);
  const [completedSteps, setCompletedSteps] = useState<number[]>([]);
  const [stepData, setStepData] = useState<Record<string, unknown>>({});
  const [templates, setTemplates] = useState<OrganizationTemplate[]>([]);

  useEffect(() => {
    async function load() {
      try {
        const [progress, templateList] = await Promise.all([
          fetchProgress(),
          fetchTemplates(),
        ]);
        setCurrentStep(progress.current_step);
        setCompletedSteps(progress.completed_steps || []);
        setStepData(progress.step_data || {});
        setTemplates(templateList);
      } catch {
        // Will use defaults
      } finally {
        setLoading(false);
      }
    }
    load();
  }, []);

  async function handleNext(step: number, data: Record<string, unknown>) {
    try {
      const progress = await updateStep(step, data);

      if (step === 1 && data.template_slug) {
        await applyTemplate(data.template_slug as string);
      }

      setCompletedSteps(progress.completed_steps || []);
      setStepData(progress.step_data || {});
      setCurrentStep(progress.current_step);
    } catch {
      setCurrentStep(Math.min(step + 1, 7));
      setCompletedSteps((prev) => [...new Set([...prev, step])]);
    }
  }

  async function handleComplete() {
    try {
      await handleNext(7, {});
      await completeOnboarding();
    } catch {
      // Continue anyway
    }
    router.push('/dashboard');
  }

  function handleBack() {
    setCurrentStep(Math.max(currentStep - 1, 1));
  }

  if (loading) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-12 w-full" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-3xl">
      <WizardProgress currentStep={currentStep} completedSteps={completedSteps} />

      <Card>
        <CardContent className="p-6">
          {currentStep === 1 && (
            <StepOrgProfile
              templates={templates}
              data={(stepData.step_1 as Record<string, unknown>) || {}}
              onNext={(data) => handleNext(1, data)}
            />
          )}
          {currentStep === 2 && (
            <StepPlaceholder
              title="Organization Structure"
              description="Configure departments and branches"
              items={['Add or edit departments', 'Create branch locations', 'Set up cost centers']}
              onNext={(data) => handleNext(2, data)}
              onBack={handleBack}
            />
          )}
          {currentStep === 3 && (
            <StepPlaceholder
              title="Work Schedule"
              description="Define shifts and working days"
              items={['Morning shift (8:00 - 17:00)', 'Working days: Mon - Fri', 'Grace period: 15 min', 'Overtime policy: 1.5x after 8 hours']}
              onNext={(data) => handleNext(3, data)}
              onBack={handleBack}
            />
          )}
          {currentStep === 4 && (
            <StepPlaceholder
              title="Leave Policies"
              description="Configure leave types and accrual rules"
              items={['Annual leave: 16 days/year', 'Sick leave: 6 days/year', 'Maternity leave: 120 days', 'Paternity leave: 5 days', 'Bereavement leave: 3 days']}
              onNext={(data) => handleNext(4, data)}
              onBack={handleBack}
            />
          )}
          {currentStep === 5 && (
            <StepPlaceholder
              title="Payroll Configuration"
              description="Set up salary structure and tax rules"
              items={['Ethiopian tax brackets (Proclamation 979/2016)', 'Employee pension: 7%', 'Employer pension: 11%', 'Transport allowance', 'Housing allowance']}
              onNext={(data) => handleNext(5, data)}
              onBack={handleBack}
            />
          )}
          {currentStep === 6 && (
            <StepPlaceholder
              title="Employee Import"
              description="Import existing employees or skip for now"
              items={['Download CSV template', 'Upload employee data', 'Map fields to columns', 'Or add employees later from the dashboard']}
              onNext={(data) => handleNext(6, data)}
              onBack={handleBack}
            />
          )}
          {currentStep === 7 && (
            <StepPlaceholder
              title="Review & Launch"
              description="Review your settings and launch your dashboard"
              items={['Organization profile configured', 'Departments and branches set up', 'Work schedule defined', 'Leave policies configured', 'Payroll rules set', 'Ready to launch!']}
              onNext={handleComplete}
              onBack={handleBack}
              isLast
            />
          )}
        </CardContent>
      </Card>
    </div>
  );
}
