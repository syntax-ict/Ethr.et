'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import { ArrowLeft, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useCreateEmployee } from '@/features/employees/api';
import { toast } from 'sonner';

export default function NewEmployeePage() {
  const router = useRouter();
  const createEmployee = useCreateEmployee();

  const [form, setForm] = useState({
    name: '',
    email: '',
    phone: '',
    employee_code: '',
    gender: '',
    date_of_birth: '',
    nationality: 'Ethiopian',
    marital_status: 'single',
    hire_date: '',
    salary_cents: '',
  });

  function updateField(field: string, value: string) {
    setForm((prev) => ({ ...prev, [field]: value }));
  }

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();

    createEmployee.mutate(
      {
        ...form,
        salary_cents: parseInt(form.salary_cents) * 100 || 0,
      },
      {
        onSuccess: () => {
          toast.success('Employee created successfully');
          router.push('/employees');
        },
        onError: (err: unknown) => {
          const axiosError = err as { response?: { data?: { detail?: string } } };
          toast.error(axiosError.response?.data?.detail || 'Failed to create employee');
        },
      }
    );
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-4">
        <Button variant="ghost" size="sm" asChild>
          <Link href="/employees">
            <ArrowLeft className="mr-2 h-4 w-4" />
            Back
          </Link>
        </Button>
        <h1 className="text-2xl font-bold text-foreground">Add Employee</h1>
      </div>

      <form onSubmit={handleSubmit}>
        <div className="grid gap-6 lg:grid-cols-2">
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Personal Information</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div>
                <Label htmlFor="name">Full Name *</Label>
                <Input id="name" value={form.name} onChange={(e) => updateField('name', e.target.value)} required className="mt-1" />
              </div>
              <div>
                <Label htmlFor="email">Email *</Label>
                <Input id="email" type="email" value={form.email} onChange={(e) => updateField('email', e.target.value)} required className="mt-1" />
              </div>
              <div>
                <Label htmlFor="phone">Phone</Label>
                <Input id="phone" value={form.phone} onChange={(e) => updateField('phone', e.target.value)} placeholder="+251..." className="mt-1" />
              </div>
              <div>
                <Label htmlFor="gender">Gender</Label>
                <Select value={form.gender} onValueChange={(v) => updateField('gender', v)}>
                  <SelectTrigger className="mt-1">
                    <SelectValue placeholder="Select gender" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="male">Male</SelectItem>
                    <SelectItem value="female">Female</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <div>
                <Label htmlFor="dob">Date of Birth</Label>
                <Input id="dob" type="date" value={form.date_of_birth} onChange={(e) => updateField('date_of_birth', e.target.value)} className="mt-1" />
              </div>
              <div>
                <Label htmlFor="marital">Marital Status</Label>
                <Select value={form.marital_status} onValueChange={(v) => updateField('marital_status', v)}>
                  <SelectTrigger className="mt-1">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="single">Single</SelectItem>
                    <SelectItem value="married">Married</SelectItem>
                    <SelectItem value="divorced">Divorced</SelectItem>
                    <SelectItem value="widowed">Widowed</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="text-base">Employment Details</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div>
                <Label htmlFor="code">Employee Code *</Label>
                <Input id="code" value={form.employee_code} onChange={(e) => updateField('employee_code', e.target.value)} required placeholder="EMP-0001" className="mt-1" />
              </div>
              <div>
                <Label htmlFor="hire">Hire Date *</Label>
                <Input id="hire" type="date" value={form.hire_date} onChange={(e) => updateField('hire_date', e.target.value)} required className="mt-1" />
              </div>
              <div>
                <Label htmlFor="salary">Monthly Salary (ETB) *</Label>
                <Input id="salary" type="number" value={form.salary_cents} onChange={(e) => updateField('salary_cents', e.target.value)} required placeholder="5000" className="mt-1" />
              </div>
              <div>
                <Label htmlFor="nationality">Nationality</Label>
                <Input id="nationality" value={form.nationality} onChange={(e) => updateField('nationality', e.target.value)} className="mt-1" />
              </div>
            </CardContent>
          </Card>
        </div>

        <div className="mt-6 flex justify-end gap-3">
          <Button variant="outline" type="button" asChild>
            <Link href="/employees">Cancel</Link>
          </Button>
          <Button type="submit" disabled={createEmployee.isPending}>
            {createEmployee.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
            Create Employee
          </Button>
        </div>
      </form>
    </div>
  );
}
