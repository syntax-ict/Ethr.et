'use client';

import { use, useState } from 'react';
import Link from 'next/link';
import { ArrowLeft, Mail, Phone, Calendar, Building2, Briefcase, Pencil, Save, X, Loader2, Plus, Trash2, FileText, CreditCard, Heart } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { StatusBadge } from '@/components/shared/status-badge';
import { CurrencyDisplay } from '@/components/shared/currency-display';
import { EmptyState } from '@/components/shared/empty-state';
import { useEmployee, useUpdateEmployee } from '@/features/employees/api';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/api/client';
import { toast } from 'sonner';

export default function EmployeeDetailPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = use(params);
  const { data: employee, isLoading } = useEmployee(id);
  const updateEmployee = useUpdateEmployee(id);
  const [editing, setEditing] = useState(false);
  const [editForm, setEditForm] = useState<Record<string, string>>({});

  function startEdit() {
    if (!employee) return;
    setEditForm({
      name: employee.name ?? '',
      email: employee.email ?? '',
      phone: employee.phone ?? '',
    });
    setEditing(true);
  }

  function handleSave() {
    updateEmployee.mutate(editForm, {
      onSuccess: () => {
        toast.success('Employee updated');
        setEditing(false);
      },
      onError: () => toast.error('Failed to update employee'),
    });
  }

  if (isLoading) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-8 w-48" />
        <div className="grid gap-6 md:grid-cols-2">
          <Skeleton className="h-64" />
          <Skeleton className="h-64" />
        </div>
      </div>
    );
  }

  if (!employee) {
    return (
      <div className="flex flex-col items-center justify-center py-20">
        <p className="text-lg font-medium text-foreground">Employee not found</p>
        <Button variant="outline" className="mt-4" asChild>
          <Link href="/employees">Back to list</Link>
        </Button>
      </div>
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
      </div>

      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="flex items-center gap-4">
          <div className="flex h-14 w-14 items-center justify-center rounded-full bg-primary/10">
            <span className="text-lg font-bold text-primary">
              {employee.name.split(' ').map(n => n[0]).join('').slice(0, 2).toUpperCase()}
            </span>
          </div>
          <div>
            <h1 className="text-2xl font-bold text-foreground">{employee.name}</h1>
            <p className="text-sm text-muted-foreground">{employee.employee_code}</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <StatusBadge status={employee.status} />
          {!editing && (
            <Button variant="outline" size="sm" onClick={startEdit}>
              <Pencil className="mr-2 h-3 w-3" />
              Edit
            </Button>
          )}
        </div>
      </div>

      <Tabs defaultValue="info" className="w-full">
        <TabsList>
          <TabsTrigger value="info">Information</TabsTrigger>
          <TabsTrigger value="employment">Employment</TabsTrigger>
          <TabsTrigger value="documents">Documents</TabsTrigger>
          <TabsTrigger value="bank">Bank Details</TabsTrigger>
          <TabsTrigger value="emergency">Emergency Contacts</TabsTrigger>
        </TabsList>

        <TabsContent value="info" className="mt-4">
          <div className="grid gap-6 md:grid-cols-2">
            <Card>
              <CardHeader className="flex flex-row items-center justify-between pb-2">
                <CardTitle className="text-base">Personal Information</CardTitle>
                {editing && (
                  <div className="flex gap-1">
                    <Button size="sm" variant="ghost" onClick={() => setEditing(false)}>
                      <X className="h-3 w-3" />
                    </Button>
                    <Button size="sm" onClick={handleSave} disabled={updateEmployee.isPending}>
                      {updateEmployee.isPending ? <Loader2 className="h-3 w-3 animate-spin" /> : <Save className="mr-1 h-3 w-3" />}
                      Save
                    </Button>
                  </div>
                )}
              </CardHeader>
              <CardContent className="space-y-4">
                {editing ? (
                  <>
                    <div>
                      <Label>Name</Label>
                      <Input value={editForm.name} onChange={(e) => setEditForm(p => ({ ...p, name: e.target.value }))} className="mt-1" />
                    </div>
                    <div>
                      <Label>Email</Label>
                      <Input type="email" value={editForm.email} onChange={(e) => setEditForm(p => ({ ...p, email: e.target.value }))} className="mt-1" />
                    </div>
                    <div>
                      <Label>Phone</Label>
                      <Input value={editForm.phone} onChange={(e) => setEditForm(p => ({ ...p, phone: e.target.value }))} className="mt-1" />
                    </div>
                  </>
                ) : (
                  <>
                    <InfoRow icon={Mail} label="Email" value={employee.email ?? '—'} />
                    <InfoRow icon={Phone} label="Phone" value={employee.phone ?? '—'} />
                    <InfoRow icon={Calendar} label="Hire Date" value={employee.hire_date} />
                    {employee.gender && <InfoRow label="Gender" value={employee.gender} />}
                  </>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="text-base">Organization</CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <InfoRow icon={Building2} label="Department" value={employee.department?.name ?? '—'} />
                <InfoRow icon={Briefcase} label="Position" value={employee.position?.name ?? '—'} />
                <InfoRow label="Branch" value={employee.branch?.name ?? '—'} />
                {employee.salary_cents !== undefined && (
                  <div className="flex items-center justify-between">
                    <span className="text-sm text-muted-foreground">Salary</span>
                    <CurrencyDisplay cents={employee.salary_cents} className="text-sm font-medium" />
                  </div>
                )}
              </CardContent>
            </Card>
          </div>
        </TabsContent>

        <TabsContent value="employment" className="mt-4">
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Employment Timeline</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-4">
                <div className="flex items-center gap-4">
                  <div className="h-3 w-3 rounded-full bg-green-500" />
                  <div>
                    <p className="text-sm font-medium">Hired</p>
                    <p className="text-xs text-muted-foreground">{employee.hire_date}</p>
                  </div>
                </div>
                <div className="flex items-center gap-4">
                  <div className="h-3 w-3 rounded-full bg-primary" />
                  <div>
                    <p className="text-sm font-medium">Current Status</p>
                    <StatusBadge status={employee.status} />
                  </div>
                </div>
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="documents" className="mt-4">
          <DocumentsTab employeeId={id} />
        </TabsContent>

        <TabsContent value="bank" className="mt-4">
          <BankDetailsTab employeeId={id} />
        </TabsContent>

        <TabsContent value="emergency" className="mt-4">
          <EmergencyContactsTab employeeId={id} />
        </TabsContent>
      </Tabs>
    </div>
  );
}

interface Doc { public_id: string; filename: string; mime_type?: string; document_type?: string; uploaded_at?: string; }
interface BankDetail { public_id: string; bank_name: string; branch_name?: string; account_number: string; account_holder_name?: string; is_primary?: boolean; }
interface EmergencyContact { public_id: string; name: string; relationship: string; phone: string; }

function DocumentsTab({ employeeId }: { employeeId: string }) {
  const queryClient = useQueryClient();
  const [uploadOpen, setUploadOpen] = useState(false);
  const [docType, setDocType] = useState('contract');
  const [file, setFile] = useState<File | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['employee', employeeId, 'documents'],
    queryFn: async () => {
      const { data } = await apiClient.get(`/employees/${employeeId}/documents`);
      return data;
    },
  });

  const uploadDoc = useMutation({
    mutationFn: async () => {
      if (!file) throw new Error('No file');
      const fd = new FormData();
      fd.append('file', file);
      fd.append('document_type', docType);
      const { data } = await apiClient.post(`/employees/${employeeId}/documents`, fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['employee', employeeId, 'documents'] });
      toast.success('Document uploaded');
      setUploadOpen(false);
      setFile(null);
    },
    onError: () => toast.error('Upload failed'),
  });

  const deleteDoc = useMutation({
    mutationFn: async (docId: string) => { await apiClient.delete(`/employees/${employeeId}/documents/${docId}`); },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['employee', employeeId, 'documents'] });
      toast.success('Document deleted');
    },
  });

  const docs: Doc[] = data?.data ?? [];

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between">
        <CardTitle className="text-base">Documents</CardTitle>
        <Button size="sm" onClick={() => setUploadOpen(true)}>
          <Plus className="mr-2 h-3 w-3" /> Upload
        </Button>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <Skeleton className="h-20 w-full" />
        ) : docs.length === 0 ? (
          <EmptyState icon={FileText} title="No documents" description="Upload contracts, IDs, and other documents" />
        ) : (
          <div className="space-y-2">
            {docs.map((d) => (
              <div key={d.public_id} className="flex items-center justify-between rounded-lg border p-3">
                <div className="flex items-center gap-3">
                  <FileText className="h-4 w-4 text-muted-foreground" />
                  <div>
                    <p className="text-sm font-medium">{d.filename}</p>
                    <p className="text-xs text-muted-foreground capitalize">{d.document_type ?? 'document'}</p>
                  </div>
                </div>
                <Button variant="ghost" size="sm" onClick={() => deleteDoc.mutate(d.public_id)}>
                  <Trash2 className="h-4 w-4 text-destructive" />
                </Button>
              </div>
            ))}
          </div>
        )}
      </CardContent>

      <Dialog open={uploadOpen} onOpenChange={setUploadOpen}>
        <DialogContent>
          <DialogHeader><DialogTitle>Upload Document</DialogTitle></DialogHeader>
          <form onSubmit={(e) => { e.preventDefault(); uploadDoc.mutate(); }} className="space-y-4">
            <div>
              <Label>Document Type</Label>
              <Input value={docType} onChange={(e) => setDocType(e.target.value)} placeholder="contract, id, certificate..." className="mt-1" />
            </div>
            <div>
              <Label>File</Label>
              <Input type="file" onChange={(e) => setFile(e.target.files?.[0] ?? null)} required className="mt-1" />
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setUploadOpen(false)}>Cancel</Button>
              <Button type="submit" disabled={uploadDoc.isPending || !file}>
                {uploadDoc.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                Upload
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </Card>
  );
}

function BankDetailsTab({ employeeId }: { employeeId: string }) {
  const queryClient = useQueryClient();
  const [addOpen, setAddOpen] = useState(false);
  const [form, setForm] = useState({ bank_name: '', branch_name: '', account_number: '', account_holder_name: '', is_primary: true });

  const { data, isLoading } = useQuery({
    queryKey: ['employee', employeeId, 'bank-details'],
    queryFn: async () => {
      const { data } = await apiClient.get(`/employees/${employeeId}/bank-details`);
      return data;
    },
  });

  const addBank = useMutation({
    mutationFn: async () => {
      const { data } = await apiClient.post(`/employees/${employeeId}/bank-details`, form);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['employee', employeeId, 'bank-details'] });
      toast.success('Bank details added');
      setAddOpen(false);
      setForm({ bank_name: '', branch_name: '', account_number: '', account_holder_name: '', is_primary: true });
    },
    onError: () => toast.error('Failed to add bank'),
  });

  const deleteBank = useMutation({
    mutationFn: async (id: string) => { await apiClient.delete(`/employees/${employeeId}/bank-details/${id}`); },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['employee', employeeId, 'bank-details'] });
      toast.success('Bank deleted');
    },
  });

  const banks: BankDetail[] = data?.data ?? [];

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between">
        <CardTitle className="text-base">Bank Details</CardTitle>
        <Button size="sm" onClick={() => setAddOpen(true)}><Plus className="mr-2 h-3 w-3" /> Add</Button>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <Skeleton className="h-20 w-full" />
        ) : banks.length === 0 ? (
          <EmptyState icon={CreditCard} title="No bank accounts" description="Add bank details for salary deposits" />
        ) : (
          <div className="space-y-2">
            {banks.map((b) => (
              <div key={b.public_id} className="flex items-center justify-between rounded-lg border p-3">
                <div className="flex items-center gap-3">
                  <CreditCard className="h-4 w-4 text-muted-foreground" />
                  <div>
                    <p className="text-sm font-medium">{b.bank_name} {b.is_primary && <span className="ml-1 text-[10px] text-primary">PRIMARY</span>}</p>
                    <p className="text-xs text-muted-foreground font-mono">{b.account_number}</p>
                    {b.branch_name && <p className="text-xs text-muted-foreground">{b.branch_name}</p>}
                  </div>
                </div>
                <Button variant="ghost" size="sm" onClick={() => deleteBank.mutate(b.public_id)}>
                  <Trash2 className="h-4 w-4 text-destructive" />
                </Button>
              </div>
            ))}
          </div>
        )}
      </CardContent>

      <Dialog open={addOpen} onOpenChange={setAddOpen}>
        <DialogContent>
          <DialogHeader><DialogTitle>Add Bank Account</DialogTitle></DialogHeader>
          <form onSubmit={(e) => { e.preventDefault(); addBank.mutate(); }} className="space-y-4">
            <div>
              <Label>Bank Name</Label>
              <Input value={form.bank_name} onChange={(e) => setForm(p => ({ ...p, bank_name: e.target.value }))} required className="mt-1" />
            </div>
            <div>
              <Label>Branch Name</Label>
              <Input value={form.branch_name} onChange={(e) => setForm(p => ({ ...p, branch_name: e.target.value }))} className="mt-1" />
            </div>
            <div>
              <Label>Account Number</Label>
              <Input value={form.account_number} onChange={(e) => setForm(p => ({ ...p, account_number: e.target.value }))} required className="mt-1" />
            </div>
            <div>
              <Label>Account Holder Name</Label>
              <Input value={form.account_holder_name} onChange={(e) => setForm(p => ({ ...p, account_holder_name: e.target.value }))} className="mt-1" />
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setAddOpen(false)}>Cancel</Button>
              <Button type="submit" disabled={addBank.isPending}>
                {addBank.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                Add
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </Card>
  );
}

function EmergencyContactsTab({ employeeId }: { employeeId: string }) {
  const queryClient = useQueryClient();
  const [addOpen, setAddOpen] = useState(false);
  const [form, setForm] = useState({ name: '', relationship: '', phone: '' });

  const { data, isLoading } = useQuery({
    queryKey: ['employee', employeeId, 'emergency-contacts'],
    queryFn: async () => {
      const { data } = await apiClient.get(`/employees/${employeeId}/emergency-contacts`);
      return data;
    },
  });

  const addContact = useMutation({
    mutationFn: async () => {
      const { data } = await apiClient.post(`/employees/${employeeId}/emergency-contacts`, form);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['employee', employeeId, 'emergency-contacts'] });
      toast.success('Contact added');
      setAddOpen(false);
      setForm({ name: '', relationship: '', phone: '' });
    },
    onError: () => toast.error('Failed to add contact'),
  });

  const deleteContact = useMutation({
    mutationFn: async (id: string) => { await apiClient.delete(`/employees/${employeeId}/emergency-contacts/${id}`); },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['employee', employeeId, 'emergency-contacts'] });
      toast.success('Contact deleted');
    },
  });

  const contacts: EmergencyContact[] = data?.data ?? [];

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between">
        <CardTitle className="text-base">Emergency Contacts</CardTitle>
        <Button size="sm" onClick={() => setAddOpen(true)}><Plus className="mr-2 h-3 w-3" /> Add</Button>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <Skeleton className="h-20 w-full" />
        ) : contacts.length === 0 ? (
          <EmptyState icon={Heart} title="No emergency contacts" description="Add people to contact in case of emergency" />
        ) : (
          <div className="space-y-2">
            {contacts.map((c) => (
              <div key={c.public_id} className="flex items-center justify-between rounded-lg border p-3">
                <div className="flex items-center gap-3">
                  <Heart className="h-4 w-4 text-muted-foreground" />
                  <div>
                    <p className="text-sm font-medium">{c.name}</p>
                    <p className="text-xs text-muted-foreground">{c.relationship} · {c.phone}</p>
                  </div>
                </div>
                <Button variant="ghost" size="sm" onClick={() => deleteContact.mutate(c.public_id)}>
                  <Trash2 className="h-4 w-4 text-destructive" />
                </Button>
              </div>
            ))}
          </div>
        )}
      </CardContent>

      <Dialog open={addOpen} onOpenChange={setAddOpen}>
        <DialogContent>
          <DialogHeader><DialogTitle>Add Emergency Contact</DialogTitle></DialogHeader>
          <form onSubmit={(e) => { e.preventDefault(); addContact.mutate(); }} className="space-y-4">
            <div>
              <Label>Name</Label>
              <Input value={form.name} onChange={(e) => setForm(p => ({ ...p, name: e.target.value }))} required className="mt-1" />
            </div>
            <div>
              <Label>Relationship</Label>
              <Input value={form.relationship} onChange={(e) => setForm(p => ({ ...p, relationship: e.target.value }))} placeholder="Spouse, Parent..." required className="mt-1" />
            </div>
            <div>
              <Label>Phone</Label>
              <Input value={form.phone} onChange={(e) => setForm(p => ({ ...p, phone: e.target.value }))} required className="mt-1" />
            </div>
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => setAddOpen(false)}>Cancel</Button>
              <Button type="submit" disabled={addContact.isPending}>
                {addContact.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                Add
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </Card>
  );
}

function InfoRow({
  icon: Icon,
  label,
  value,
}: {
  icon?: React.ComponentType<{ className?: string }>;
  label: string;
  value: string;
}) {
  return (
    <div className="flex items-center justify-between">
      <div className="flex items-center gap-2">
        {Icon && <Icon className="h-4 w-4 text-muted-foreground" />}
        <span className="text-sm text-muted-foreground">{label}</span>
      </div>
      <span className="text-sm font-medium text-foreground">{value}</span>
    </div>
  );
}
