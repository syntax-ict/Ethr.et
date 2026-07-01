"use client";

import { use, useState } from "react";
import Link from "next/link";
import {
  ArrowLeft,
  Mail,
  Phone,
  Calendar,
  Building2,
  Briefcase,
  Pencil,
  Save,
  X,
  Loader2,
  Plus,
  Trash2,
  FileText,
  CreditCard,
  Heart,
  GraduationCap,
  GitCommit,
  ArrowRight,
  AlertCircle,
  CalendarRange,
  Clock as ClockIcon,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Textarea } from "@/components/ui/textarea";
import { Badge } from "@/components/ui/badge";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { cn } from "@/lib/utils";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog";
import { StatusBadge } from "@/components/shared/status-badge";
import { CurrencyDisplay } from "@/components/shared/currency-display";
import { EmptyState } from "@/components/shared/empty-state";
import { useEmployee, useUpdateEmployee } from "@/features/employees/api";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { toast } from "sonner";

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
      name: employee.name ?? "",
      email: employee.email ?? "",
      phone: employee.phone ?? "",
    });
    setEditing(true);
  }

  function handleSave() {
    updateEmployee.mutate(editForm, {
      onSuccess: () => {
        toast.success("Employee updated");
        setEditing(false);
      },
      onError: () => toast.error("Failed to update employee"),
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
        <p className="text-lg font-medium text-foreground">
          Employee not found
        </p>
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
              {employee.name
                .split(" ")
                .map((n) => n[0])
                .join("")
                .slice(0, 2)
                .toUpperCase()}
            </span>
          </div>
          <div>
            <h1 className="text-2xl font-bold text-foreground">
              {employee.name}
            </h1>
            <p className="text-sm text-muted-foreground">
              {employee.employee_code}
            </p>
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
          <TabsTrigger value="education">Education</TabsTrigger>
          <TabsTrigger value="lifecycle">Lifecycle</TabsTrigger>
          <TabsTrigger value="attendance">Attendance</TabsTrigger>
        </TabsList>

        <TabsContent value="info" className="mt-4">
          <div className="grid gap-6 md:grid-cols-2">
            <Card>
              <CardHeader className="flex flex-row items-center justify-between pb-2">
                <CardTitle className="text-base">
                  Personal Information
                </CardTitle>
                {editing && (
                  <div className="flex gap-1">
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() => setEditing(false)}
                    >
                      <X className="h-3 w-3" />
                    </Button>
                    <Button
                      size="sm"
                      onClick={handleSave}
                      disabled={updateEmployee.isPending}
                    >
                      {updateEmployee.isPending ? (
                        <Loader2 className="h-3 w-3 animate-spin" />
                      ) : (
                        <Save className="mr-1 h-3 w-3" />
                      )}
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
                      <Input
                        value={editForm.name}
                        onChange={(e) =>
                          setEditForm((p) => ({ ...p, name: e.target.value }))
                        }
                        className="mt-1"
                      />
                    </div>
                    <div>
                      <Label>Email</Label>
                      <Input
                        type="email"
                        value={editForm.email}
                        onChange={(e) =>
                          setEditForm((p) => ({ ...p, email: e.target.value }))
                        }
                        className="mt-1"
                      />
                    </div>
                    <div>
                      <Label>Phone</Label>
                      <Input
                        value={editForm.phone}
                        onChange={(e) =>
                          setEditForm((p) => ({ ...p, phone: e.target.value }))
                        }
                        className="mt-1"
                      />
                    </div>
                  </>
                ) : (
                  <>
                    <InfoRow
                      icon={Mail}
                      label="Email"
                      value={employee.email ?? "—"}
                    />
                    <InfoRow
                      icon={Phone}
                      label="Phone"
                      value={employee.phone ?? "—"}
                    />
                    <InfoRow
                      icon={Calendar}
                      label="Hire Date"
                      value={employee.hire_date}
                    />
                    {employee.gender && (
                      <InfoRow label="Gender" value={employee.gender} />
                    )}
                  </>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="text-base">Organization</CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <InfoRow
                  icon={Building2}
                  label="Department"
                  value={employee.department?.name ?? "—"}
                />
                <InfoRow
                  icon={Briefcase}
                  label="Position"
                  value={employee.position?.name ?? "—"}
                />
                <InfoRow label="Branch" value={employee.branch?.name ?? "—"} />
                {employee.salary_cents !== undefined && (
                  <div className="flex items-center justify-between">
                    <span className="text-sm text-muted-foreground">
                      Salary
                    </span>
                    <CurrencyDisplay
                      cents={employee.salary_cents}
                      className="text-sm font-medium"
                    />
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
                    <p className="text-xs text-muted-foreground">
                      {employee.hire_date}
                    </p>
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

        <TabsContent value="education" className="mt-4">
          <EducationTab employeeId={id} />
        </TabsContent>

        <TabsContent value="lifecycle" className="mt-4">
          <LifecycleTab employeeId={id} currentStatus={employee.status} />
        </TabsContent>

        <TabsContent value="attendance" className="mt-4">
          <AttendanceTimelineTab employeeId={id} />
        </TabsContent>
      </Tabs>
    </div>
  );
}

interface Doc {
  public_id: string;
  filename: string;
  mime_type?: string;
  document_type?: string;
  uploaded_at?: string;
}
interface BankDetail {
  public_id: string;
  bank_name: string;
  branch_name?: string;
  account_number: string;
  account_holder_name?: string;
  is_primary?: boolean;
}
interface EmergencyContact {
  public_id: string;
  name: string;
  relationship: string;
  phone: string;
}
interface Education {
  public_id: string;
  institution: string;
  degree: string;
  field_of_study?: string;
  start_year?: number;
  end_year?: number;
  gpa?: string;
}

function EducationTab({ employeeId }: { employeeId: string }) {
  const queryClient = useQueryClient();
  const [addOpen, setAddOpen] = useState(false);
  const [form, setForm] = useState({
    institution: "",
    degree: "",
    field_of_study: "",
    start_year: "",
    end_year: "",
    gpa: "",
  });

  const { data, isLoading } = useQuery({
    queryKey: ["employee", employeeId, "education"],
    queryFn: async () => {
      const { data } = await apiClient.get(
        `/employees/${employeeId}/education`,
      );
      return data;
    },
  });

  const addEducation = useMutation({
    mutationFn: async () => {
      const payload = {
        ...form,
        start_year: form.start_year ? parseInt(form.start_year) : undefined,
        end_year: form.end_year ? parseInt(form.end_year) : undefined,
      };
      const { data } = await apiClient.post(
        `/employees/${employeeId}/education`,
        payload,
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ["employee", employeeId, "education"],
      });
      toast.success("Education added");
      setAddOpen(false);
      setForm({
        institution: "",
        degree: "",
        field_of_study: "",
        start_year: "",
        end_year: "",
        gpa: "",
      });
    },
    onError: () => toast.error("Failed to add education"),
  });

  const deleteEducation = useMutation({
    mutationFn: async (id: string) => {
      await apiClient.delete(`/employees/${employeeId}/education/${id}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ["employee", employeeId, "education"],
      });
      toast.success("Education deleted");
    },
  });

  const records: Education[] = data?.data ?? [];

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between">
        <CardTitle className="text-base">Education History</CardTitle>
        <Button size="sm" onClick={() => setAddOpen(true)}>
          <Plus className="mr-2 h-3 w-3" /> Add
        </Button>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <Skeleton className="h-20 w-full" />
        ) : records.length === 0 ? (
          <EmptyState
            icon={GraduationCap}
            title="No education records"
            description="Add educational qualifications"
          />
        ) : (
          <div className="space-y-2">
            {records.map((e) => (
              <div
                key={e.public_id}
                className="flex items-start justify-between rounded-lg border p-3"
              >
                <div className="flex items-start gap-3">
                  <GraduationCap className="mt-0.5 h-4 w-4 text-muted-foreground" />
                  <div>
                    <p className="text-sm font-medium">
                      {e.degree}
                      {e.field_of_study && ` — ${e.field_of_study}`}
                    </p>
                    <p className="text-xs text-muted-foreground">
                      {e.institution}
                    </p>
                    {(e.start_year || e.end_year) && (
                      <p className="text-xs text-muted-foreground">
                        {e.start_year ?? ""} – {e.end_year ?? "Present"}
                        {e.gpa && ` · GPA: ${e.gpa}`}
                      </p>
                    )}
                  </div>
                </div>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => deleteEducation.mutate(e.public_id)}
                >
                  <Trash2 className="h-4 w-4 text-destructive" />
                </Button>
              </div>
            ))}
          </div>
        )}
      </CardContent>

      <Dialog open={addOpen} onOpenChange={setAddOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Add Education</DialogTitle>
          </DialogHeader>
          <form
            onSubmit={(e) => {
              e.preventDefault();
              addEducation.mutate();
            }}
            className="space-y-4"
          >
            <div>
              <Label>Institution</Label>
              <Input
                value={form.institution}
                onChange={(e) =>
                  setForm((p) => ({ ...p, institution: e.target.value }))
                }
                required
                className="mt-1"
              />
            </div>
            <div>
              <Label>Degree</Label>
              <Input
                value={form.degree}
                onChange={(e) =>
                  setForm((p) => ({ ...p, degree: e.target.value }))
                }
                required
                placeholder="BSc, MSc, MBA..."
                className="mt-1"
              />
            </div>
            <div>
              <Label>Field of Study</Label>
              <Input
                value={form.field_of_study}
                onChange={(e) =>
                  setForm((p) => ({ ...p, field_of_study: e.target.value }))
                }
                placeholder="Computer Science..."
                className="mt-1"
              />
            </div>
            <div className="grid grid-cols-3 gap-3">
              <div>
                <Label>Start Year</Label>
                <Input
                  type="number"
                  value={form.start_year}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, start_year: e.target.value }))
                  }
                  placeholder="2015"
                  className="mt-1"
                />
              </div>
              <div>
                <Label>End Year</Label>
                <Input
                  type="number"
                  value={form.end_year}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, end_year: e.target.value }))
                  }
                  placeholder="2019"
                  className="mt-1"
                />
              </div>
              <div>
                <Label>GPA</Label>
                <Input
                  value={form.gpa}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, gpa: e.target.value }))
                  }
                  placeholder="3.8"
                  className="mt-1"
                />
              </div>
            </div>
            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setAddOpen(false)}
              >
                Cancel
              </Button>
              <Button type="submit" disabled={addEducation.isPending}>
                {addEducation.isPending && (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                )}
                Add
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </Card>
  );
}

function DocumentsTab({ employeeId }: { employeeId: string }) {
  const queryClient = useQueryClient();
  const [uploadOpen, setUploadOpen] = useState(false);
  const [docType, setDocType] = useState("contract");
  const [file, setFile] = useState<File | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ["employee", employeeId, "documents"],
    queryFn: async () => {
      const { data } = await apiClient.get(
        `/employees/${employeeId}/documents`,
      );
      return data;
    },
  });

  const uploadDoc = useMutation({
    mutationFn: async () => {
      if (!file) throw new Error("No file");
      const fd = new FormData();
      fd.append("file", file);
      fd.append("document_type", docType);
      const { data } = await apiClient.post(
        `/employees/${employeeId}/documents`,
        fd,
        {
          headers: { "Content-Type": "multipart/form-data" },
        },
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ["employee", employeeId, "documents"],
      });
      toast.success("Document uploaded");
      setUploadOpen(false);
      setFile(null);
    },
    onError: () => toast.error("Upload failed"),
  });

  const deleteDoc = useMutation({
    mutationFn: async (docId: string) => {
      await apiClient.delete(`/employees/${employeeId}/documents/${docId}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ["employee", employeeId, "documents"],
      });
      toast.success("Document deleted");
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
          <EmptyState
            icon={FileText}
            title="No documents"
            description="Upload contracts, IDs, and other documents"
          />
        ) : (
          <div className="space-y-2">
            {docs.map((d) => (
              <div
                key={d.public_id}
                className="flex items-center justify-between rounded-lg border p-3"
              >
                <div className="flex items-center gap-3">
                  <FileText className="h-4 w-4 text-muted-foreground" />
                  <div>
                    <p className="text-sm font-medium">{d.filename}</p>
                    <p className="text-xs text-muted-foreground capitalize">
                      {d.document_type ?? "document"}
                    </p>
                  </div>
                </div>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => deleteDoc.mutate(d.public_id)}
                >
                  <Trash2 className="h-4 w-4 text-destructive" />
                </Button>
              </div>
            ))}
          </div>
        )}
      </CardContent>

      <Dialog open={uploadOpen} onOpenChange={setUploadOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Upload Document</DialogTitle>
          </DialogHeader>
          <form
            onSubmit={(e) => {
              e.preventDefault();
              uploadDoc.mutate();
            }}
            className="space-y-4"
          >
            <div>
              <Label>Document Type</Label>
              <Input
                value={docType}
                onChange={(e) => setDocType(e.target.value)}
                placeholder="contract, id, certificate..."
                className="mt-1"
              />
            </div>
            <div>
              <Label>File</Label>
              <Input
                type="file"
                onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                required
                className="mt-1"
              />
            </div>
            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setUploadOpen(false)}
              >
                Cancel
              </Button>
              <Button type="submit" disabled={uploadDoc.isPending || !file}>
                {uploadDoc.isPending && (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                )}
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
  const [form, setForm] = useState({
    bank_name: "",
    branch_name: "",
    account_number: "",
    account_holder_name: "",
    is_primary: true,
  });

  const { data, isLoading } = useQuery({
    queryKey: ["employee", employeeId, "bank-details"],
    queryFn: async () => {
      const { data } = await apiClient.get(
        `/employees/${employeeId}/bank-details`,
      );
      return data;
    },
  });

  const addBank = useMutation({
    mutationFn: async () => {
      const { data } = await apiClient.post(
        `/employees/${employeeId}/bank-details`,
        form,
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ["employee", employeeId, "bank-details"],
      });
      toast.success("Bank details added");
      setAddOpen(false);
      setForm({
        bank_name: "",
        branch_name: "",
        account_number: "",
        account_holder_name: "",
        is_primary: true,
      });
    },
    onError: () => toast.error("Failed to add bank"),
  });

  const deleteBank = useMutation({
    mutationFn: async (id: string) => {
      await apiClient.delete(`/employees/${employeeId}/bank-details/${id}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ["employee", employeeId, "bank-details"],
      });
      toast.success("Bank deleted");
    },
  });

  const banks: BankDetail[] = data?.data ?? [];

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between">
        <CardTitle className="text-base">Bank Details</CardTitle>
        <Button size="sm" onClick={() => setAddOpen(true)}>
          <Plus className="mr-2 h-3 w-3" /> Add
        </Button>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <Skeleton className="h-20 w-full" />
        ) : banks.length === 0 ? (
          <EmptyState
            icon={CreditCard}
            title="No bank accounts"
            description="Add bank details for salary deposits"
          />
        ) : (
          <div className="space-y-2">
            {banks.map((b) => (
              <div
                key={b.public_id}
                className="flex items-center justify-between rounded-lg border p-3"
              >
                <div className="flex items-center gap-3">
                  <CreditCard className="h-4 w-4 text-muted-foreground" />
                  <div>
                    <p className="text-sm font-medium">
                      {b.bank_name}{" "}
                      {b.is_primary && (
                        <span className="ml-1 text-[10px] text-primary">
                          PRIMARY
                        </span>
                      )}
                    </p>
                    <p className="text-xs text-muted-foreground font-mono">
                      {b.account_number}
                    </p>
                    {b.branch_name && (
                      <p className="text-xs text-muted-foreground">
                        {b.branch_name}
                      </p>
                    )}
                  </div>
                </div>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => deleteBank.mutate(b.public_id)}
                >
                  <Trash2 className="h-4 w-4 text-destructive" />
                </Button>
              </div>
            ))}
          </div>
        )}
      </CardContent>

      <Dialog open={addOpen} onOpenChange={setAddOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Add Bank Account</DialogTitle>
          </DialogHeader>
          <form
            onSubmit={(e) => {
              e.preventDefault();
              addBank.mutate();
            }}
            className="space-y-4"
          >
            <div>
              <Label>Bank Name</Label>
              <Input
                value={form.bank_name}
                onChange={(e) =>
                  setForm((p) => ({ ...p, bank_name: e.target.value }))
                }
                required
                className="mt-1"
              />
            </div>
            <div>
              <Label>Branch Name</Label>
              <Input
                value={form.branch_name}
                onChange={(e) =>
                  setForm((p) => ({ ...p, branch_name: e.target.value }))
                }
                className="mt-1"
              />
            </div>
            <div>
              <Label>Account Number</Label>
              <Input
                value={form.account_number}
                onChange={(e) =>
                  setForm((p) => ({ ...p, account_number: e.target.value }))
                }
                required
                className="mt-1"
              />
            </div>
            <div>
              <Label>Account Holder Name</Label>
              <Input
                value={form.account_holder_name}
                onChange={(e) =>
                  setForm((p) => ({
                    ...p,
                    account_holder_name: e.target.value,
                  }))
                }
                className="mt-1"
              />
            </div>
            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setAddOpen(false)}
              >
                Cancel
              </Button>
              <Button type="submit" disabled={addBank.isPending}>
                {addBank.isPending && (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                )}
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
  const [form, setForm] = useState({ name: "", relationship: "", phone: "" });

  const { data, isLoading } = useQuery({
    queryKey: ["employee", employeeId, "emergency-contacts"],
    queryFn: async () => {
      const { data } = await apiClient.get(
        `/employees/${employeeId}/emergency-contacts`,
      );
      return data;
    },
  });

  const addContact = useMutation({
    mutationFn: async () => {
      const { data } = await apiClient.post(
        `/employees/${employeeId}/emergency-contacts`,
        form,
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ["employee", employeeId, "emergency-contacts"],
      });
      toast.success("Contact added");
      setAddOpen(false);
      setForm({ name: "", relationship: "", phone: "" });
    },
    onError: () => toast.error("Failed to add contact"),
  });

  const deleteContact = useMutation({
    mutationFn: async (id: string) => {
      await apiClient.delete(
        `/employees/${employeeId}/emergency-contacts/${id}`,
      );
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ["employee", employeeId, "emergency-contacts"],
      });
      toast.success("Contact deleted");
    },
  });

  const contacts: EmergencyContact[] = data?.data ?? [];

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between">
        <CardTitle className="text-base">Emergency Contacts</CardTitle>
        <Button size="sm" onClick={() => setAddOpen(true)}>
          <Plus className="mr-2 h-3 w-3" /> Add
        </Button>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <Skeleton className="h-20 w-full" />
        ) : contacts.length === 0 ? (
          <EmptyState
            icon={Heart}
            title="No emergency contacts"
            description="Add people to contact in case of emergency"
          />
        ) : (
          <div className="space-y-2">
            {contacts.map((c) => (
              <div
                key={c.public_id}
                className="flex items-center justify-between rounded-lg border p-3"
              >
                <div className="flex items-center gap-3">
                  <Heart className="h-4 w-4 text-muted-foreground" />
                  <div>
                    <p className="text-sm font-medium">{c.name}</p>
                    <p className="text-xs text-muted-foreground">
                      {c.relationship} · {c.phone}
                    </p>
                  </div>
                </div>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => deleteContact.mutate(c.public_id)}
                >
                  <Trash2 className="h-4 w-4 text-destructive" />
                </Button>
              </div>
            ))}
          </div>
        )}
      </CardContent>

      <Dialog open={addOpen} onOpenChange={setAddOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Add Emergency Contact</DialogTitle>
          </DialogHeader>
          <form
            onSubmit={(e) => {
              e.preventDefault();
              addContact.mutate();
            }}
            className="space-y-4"
          >
            <div>
              <Label>Name</Label>
              <Input
                value={form.name}
                onChange={(e) =>
                  setForm((p) => ({ ...p, name: e.target.value }))
                }
                required
                className="mt-1"
              />
            </div>
            <div>
              <Label>Relationship</Label>
              <Input
                value={form.relationship}
                onChange={(e) =>
                  setForm((p) => ({ ...p, relationship: e.target.value }))
                }
                placeholder="Spouse, Parent..."
                required
                className="mt-1"
              />
            </div>
            <div>
              <Label>Phone</Label>
              <Input
                value={form.phone}
                onChange={(e) =>
                  setForm((p) => ({ ...p, phone: e.target.value }))
                }
                required
                className="mt-1"
              />
            </div>
            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setAddOpen(false)}
              >
                Cancel
              </Button>
              <Button type="submit" disabled={addContact.isPending}>
                {addContact.isPending && (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                )}
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

// ── LIFECYCLE TAB ──────────────────────────────────────────────

interface Transition {
  public_id: string;
  from_status: string;
  to_status: string;
  reason: string | null;
  effective_date: string;
  approved_by?: { name?: string; email?: string };
  created_at: string;
}

const ALLOWED_TRANSITIONS: Record<string, string[]> = {
  hired: ["probation", "confirmed"],
  probation: ["confirmed", "terminated"],
  confirmed: ["suspended", "resigned", "terminated", "retired"],
  suspended: ["confirmed", "terminated"],
  resigned: [],
  terminated: [],
  retired: [],
};

const STATUS_DOT_COLOR: Record<string, string> = {
  hired: "bg-blue-500",
  probation: "bg-amber-500",
  confirmed: "bg-green-500",
  suspended: "bg-orange-500",
  resigned: "bg-gray-500",
  terminated: "bg-red-500",
  retired: "bg-purple-500",
};

const STATUS_LABEL: Record<string, string> = {
  hired: "Hired",
  probation: "Probation",
  confirmed: "Confirmed",
  suspended: "Suspended",
  resigned: "Resigned",
  terminated: "Terminated",
  retired: "Retired",
};

interface TimelineDay {
  date: string;
  status: "present" | "late" | "absent" | "weekend" | string;
  check_in: string | null;
  check_out: string | null;
  worked_minutes: number | null;
  source: string | null;
  is_weekend: boolean;
}

interface TimelineResponse {
  employee: { public_id: string; name: string };
  range: { from: string; to: string };
  totals: {
    present: number;
    late: number;
    absent: number;
    total_minutes_worked: number;
  };
  days: TimelineDay[];
}

const STATUS_BG: Record<string, string> = {
  present: "bg-green-500 hover:bg-green-600",
  late: "bg-amber-500 hover:bg-amber-600",
  absent: "bg-red-400 hover:bg-red-500",
  weekend: "bg-muted hover:bg-muted-foreground/20",
};

function AttendanceTimelineTab({ employeeId }: { employeeId: string }) {
  const [range, setRange] = useState<{ from: string; to: string }>(() => {
    const to = new Date().toISOString().split("T")[0];
    const from = new Date(Date.now() - 90 * 86400000)
      .toISOString()
      .split("T")[0];
    return { from, to };
  });
  const [selectedDay, setSelectedDay] = useState<TimelineDay | null>(null);

  const { data, isLoading } = useQuery<TimelineResponse>({
    queryKey: ["employee", employeeId, "timeline", range],
    queryFn: async () => {
      const { data } = await apiClient.get(
        `/employees/${employeeId}/attendance/timeline`,
        {
          params: { from: range.from, to: range.to },
        },
      );
      return data;
    },
  });

  if (isLoading || !data) {
    return (
      <Card>
        <CardContent className="p-6">
          <Skeleton className="h-8 w-48 mb-4" />
          <Skeleton className="h-40 w-full" />
        </CardContent>
      </Card>
    );
  }

  const weeks: TimelineDay[][] = [];
  let currentWeek: TimelineDay[] = [];
  const firstDate = new Date(data.days[0]?.date ?? range.from);
  const firstDow = (firstDate.getDay() + 6) % 7;
  for (let i = 0; i < firstDow; i++) {
    currentWeek.push({
      date: "",
      status: "weekend",
      check_in: null,
      check_out: null,
      worked_minutes: null,
      source: null,
      is_weekend: true,
    });
  }
  for (const day of data.days) {
    currentWeek.push(day);
    if (currentWeek.length === 7) {
      weeks.push(currentWeek);
      currentWeek = [];
    }
  }
  if (currentWeek.length > 0) {
    while (currentWeek.length < 7) {
      currentWeek.push({
        date: "",
        status: "weekend",
        check_in: null,
        check_out: null,
        worked_minutes: null,
        source: null,
        is_weekend: true,
      });
    }
    weeks.push(currentWeek);
  }

  const totalHoursWorked = (data.totals.total_minutes_worked / 60).toFixed(1);

  return (
    <Card>
      <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <CardTitle className="text-base flex items-center gap-2">
            <CalendarRange className="h-4 w-4" /> Attendance Timeline
          </CardTitle>
          <p className="mt-1 text-xs text-muted-foreground">
            {data.range.from} to {data.range.to}
          </p>
        </div>
        <div className="flex gap-2">
          <Input
            type="date"
            value={range.from}
            onChange={(e) => setRange((r) => ({ ...r, from: e.target.value }))}
            className="w-36"
          />
          <Input
            type="date"
            value={range.to}
            onChange={(e) => setRange((r) => ({ ...r, to: e.target.value }))}
            className="w-36"
          />
        </div>
      </CardHeader>
      <CardContent>
        <div className="mb-6 grid gap-3 sm:grid-cols-4">
          <TotalChip
            color="green"
            label="Present"
            value={data.totals.present}
          />
          <TotalChip color="amber" label="Late" value={data.totals.late} />
          <TotalChip color="red" label="Absent" value={data.totals.absent} />
          <TotalChip
            color="blue"
            label="Hours Worked"
            value={totalHoursWorked}
            suffix="h"
          />
        </div>

        <div className="overflow-x-auto pb-2">
          <div className="flex gap-2">
            <div className="flex flex-col gap-1 pt-0">
              {["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"].map((d) => (
                <div
                  key={d}
                  className="h-4 text-[10px] font-medium text-muted-foreground leading-none flex items-center w-6"
                >
                  {d}
                </div>
              ))}
            </div>
            <div className="flex gap-1">
              {weeks.map((week, wi) => (
                <div key={wi} className="flex flex-col gap-1">
                  {week.map((day, di) => {
                    const isEmpty = day.date === "";
                    const bg = isEmpty
                      ? "bg-transparent"
                      : (STATUS_BG[day.status] ?? "bg-muted");
                    return (
                      <button
                        key={di}
                        onClick={() => !isEmpty && setSelectedDay(day)}
                        disabled={isEmpty}
                        title={isEmpty ? "" : `${day.date} — ${day.status}`}
                        className={cn(
                          "h-4 w-4 rounded-sm transition-colors",
                          bg,
                          !isEmpty &&
                            "cursor-pointer ring-offset-1 hover:ring-2 hover:ring-primary",
                        )}
                      />
                    );
                  })}
                </div>
              ))}
            </div>
          </div>
        </div>

        <div className="mt-6 flex flex-wrap items-center gap-4 text-xs">
          <span className="text-muted-foreground">Legend:</span>
          <LegendDot color="green" label="Present" />
          <LegendDot color="amber" label="Late" />
          <LegendDot color="red" label="Absent" />
          <LegendDot color="gray" label="Weekend / no data" />
        </div>

        {selectedDay && (
          <div className="mt-6 rounded-lg border p-4">
            <div className="flex items-start justify-between gap-2">
              <div>
                <p className="font-semibold text-foreground">
                  {selectedDay.date}
                </p>
                <p className="text-xs text-muted-foreground capitalize">
                  {selectedDay.status}
                  {selectedDay.source && ` · ${selectedDay.source}`}
                </p>
              </div>
              <Button
                size="sm"
                variant="ghost"
                onClick={() => setSelectedDay(null)}
              >
                <X className="h-3 w-3" />
              </Button>
            </div>
            {selectedDay.check_in && (
              <div className="mt-3 grid gap-2 sm:grid-cols-3 text-sm">
                <div className="flex items-center gap-2">
                  <ClockIcon className="h-3 w-3 text-green-600" />
                  <span className="text-muted-foreground">In:</span>
                  <span className="font-mono">{selectedDay.check_in}</span>
                </div>
                <div className="flex items-center gap-2">
                  <ClockIcon className="h-3 w-3 text-orange-600" />
                  <span className="text-muted-foreground">Out:</span>
                  <span className="font-mono">
                    {selectedDay.check_out ?? "—"}
                  </span>
                </div>
                {selectedDay.worked_minutes != null && (
                  <div className="flex items-center gap-2">
                    <CalendarRange className="h-3 w-3 text-blue-600" />
                    <span className="text-muted-foreground">Worked:</span>
                    <span className="font-mono">
                      {(selectedDay.worked_minutes / 60).toFixed(1)}h
                    </span>
                  </div>
                )}
              </div>
            )}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

const TOTAL_COLORS: Record<string, string> = {
  green: "bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300",
  amber: "bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300",
  red: "bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300",
  blue: "bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300",
};

function TotalChip({
  color,
  label,
  value,
  suffix,
}: {
  color: string;
  label: string;
  value: string | number;
  suffix?: string;
}) {
  return (
    <div className={cn("rounded-lg p-3", TOTAL_COLORS[color])}>
      <p className="text-xs uppercase tracking-wider opacity-70">{label}</p>
      <p className="mt-1 text-2xl font-bold">
        {value}
        {suffix && <span className="text-sm ml-1">{suffix}</span>}
      </p>
    </div>
  );
}

const LEGEND_DOT_COLORS: Record<string, string> = {
  green: "bg-green-500",
  amber: "bg-amber-500",
  red: "bg-red-400",
  gray: "bg-muted",
};

function LegendDot({ color, label }: { color: string; label: string }) {
  return (
    <span className="flex items-center gap-1.5">
      <span className={cn("h-3 w-3 rounded-sm", LEGEND_DOT_COLORS[color])} />
      <span className="text-muted-foreground">{label}</span>
    </span>
  );
}

function LifecycleTab({
  employeeId,
  currentStatus,
}: {
  employeeId: string;
  currentStatus: string;
}) {
  const queryClient = useQueryClient();
  const { can } = usePermissions();
  const canTransition = can.manageEmployees;
  const [dialogOpen, setDialogOpen] = useState(false);
  const [form, setForm] = useState({
    to_status: "",
    reason: "",
    effective_date: new Date().toISOString().split("T")[0],
  });

  const { data, isLoading } = useQuery({
    queryKey: ["employee", employeeId, "transitions"],
    queryFn: async () => {
      const { data } = await apiClient.get(
        `/employees/${employeeId}/transitions`,
      );
      return data;
    },
  });

  const transitionMut = useMutation({
    mutationFn: async () => {
      const { data } = await apiClient.post(
        `/employees/${employeeId}/transition`,
        form,
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["employee", employeeId] });
      queryClient.invalidateQueries({ queryKey: ["employees"] });
      toast.success("Status transitioned");
      setDialogOpen(false);
      setForm({
        to_status: "",
        reason: "",
        effective_date: new Date().toISOString().split("T")[0],
      });
    },
    onError: (err: unknown) => {
      const axiosErr = err as { response?: { data?: { detail?: string } } };
      toast.error(axiosErr.response?.data?.detail || "Transition failed");
    },
  });

  const allowed = ALLOWED_TRANSITIONS[currentStatus] ?? [];
  const isTerminal = allowed.length === 0;
  const transitions: Transition[] = Array.isArray(data)
    ? data
    : (data?.data ?? []);

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between">
        <CardTitle className="text-base">Employment Lifecycle</CardTitle>
        {!isTerminal && canTransition && (
          <Button size="sm" onClick={() => setDialogOpen(true)}>
            <GitCommit className="mr-2 h-3 w-3" /> Transition Status
          </Button>
        )}
      </CardHeader>
      <CardContent>
        {isTerminal && (
          <div className="mb-4 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-900 dark:bg-amber-950/30">
            <AlertCircle className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
            <div>
              <p className="text-sm font-medium">
                Employee is in a terminal status
              </p>
              <p className="text-xs text-muted-foreground">
                Status &ldquo;{STATUS_LABEL[currentStatus]}&rdquo; cannot be
                transitioned further. Re-hire would require a new employee
                record.
              </p>
            </div>
          </div>
        )}

        <div className="space-y-4">
          {/* Current status as the top of the timeline */}
          <div className="flex items-center gap-3 rounded-lg border-2 border-primary bg-primary/5 p-3">
            <div
              className={cn(
                "h-3 w-3 rounded-full",
                STATUS_DOT_COLOR[currentStatus],
              )}
            />
            <div className="flex-1">
              <p className="text-sm font-semibold">
                Currently: {STATUS_LABEL[currentStatus] ?? currentStatus}
              </p>
              <p className="text-xs text-muted-foreground">Active status</p>
            </div>
          </div>

          {isLoading ? (
            <div className="space-y-2">
              {Array.from({ length: 2 }).map((_, i) => (
                <Skeleton key={i} className="h-16 w-full" />
              ))}
            </div>
          ) : transitions.length === 0 ? (
            <p className="text-center text-sm text-muted-foreground py-4">
              No transitions yet. Employee is in initial state.
            </p>
          ) : (
            <div className="space-y-3">
              <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                History
              </p>
              <div className="relative space-y-3">
                {/* Vertical line through the timeline */}
                <div className="absolute left-[7px] top-3 bottom-3 w-px bg-border" />
                {transitions
                  .slice()
                  .sort(
                    (a, b) =>
                      new Date(b.effective_date).getTime() -
                      new Date(a.effective_date).getTime(),
                  )
                  .map((t) => (
                    <div key={t.public_id} className="relative flex gap-3 pl-0">
                      <div
                        className={cn(
                          "z-10 mt-1 h-3.5 w-3.5 shrink-0 rounded-full ring-2 ring-background",
                          STATUS_DOT_COLOR[t.to_status],
                        )}
                      />
                      <div className="flex-1 min-w-0 rounded-lg border p-3">
                        <div className="flex flex-wrap items-center gap-2">
                          <Badge
                            variant="outline"
                            className="text-[10px] font-mono"
                          >
                            {STATUS_LABEL[t.from_status] ?? t.from_status}
                          </Badge>
                          <ArrowRight className="h-3 w-3 text-muted-foreground" />
                          <Badge
                            variant="outline"
                            className={cn(
                              "text-[10px] font-mono border-0",
                              STATUS_DOT_COLOR[t.to_status],
                              "text-white",
                            )}
                          >
                            {STATUS_LABEL[t.to_status] ?? t.to_status}
                          </Badge>
                          <span className="ml-auto text-xs text-muted-foreground">
                            {t.effective_date}
                          </span>
                        </div>
                        {t.reason && (
                          <p className="mt-2 text-sm text-foreground">
                            {t.reason}
                          </p>
                        )}
                        {t.approved_by && (
                          <p className="mt-1 text-xs text-muted-foreground">
                            by{" "}
                            {t.approved_by.name ??
                              t.approved_by.email ??
                              "system"}
                          </p>
                        )}
                      </div>
                    </div>
                  ))}
              </div>
            </div>
          )}
        </div>
      </CardContent>

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Transition Employee Status</DialogTitle>
          </DialogHeader>
          <form
            onSubmit={(e) => {
              e.preventDefault();
              transitionMut.mutate();
            }}
            className="space-y-4"
          >
            <div className="rounded-lg border bg-muted/30 p-3 text-sm">
              <p className="text-xs text-muted-foreground">Current status</p>
              <p className="mt-0.5 font-medium capitalize">
                {STATUS_LABEL[currentStatus] ?? currentStatus}
              </p>
            </div>
            <div>
              <Label>New Status *</Label>
              <div className="mt-2 grid gap-2 sm:grid-cols-2">
                {allowed.map((status) => (
                  <button
                    key={status}
                    type="button"
                    onClick={() =>
                      setForm((p) => ({ ...p, to_status: status }))
                    }
                    className={cn(
                      "flex items-center gap-2 rounded-lg border-2 p-3 text-left transition-colors",
                      form.to_status === status
                        ? "border-primary bg-primary/5"
                        : "border-border hover:border-primary/50",
                    )}
                  >
                    <div
                      className={cn(
                        "h-2.5 w-2.5 rounded-full",
                        STATUS_DOT_COLOR[status],
                      )}
                    />
                    <span className="text-sm font-medium">
                      {STATUS_LABEL[status]}
                    </span>
                  </button>
                ))}
              </div>
            </div>
            <div>
              <Label>Effective Date *</Label>
              <Input
                type="date"
                value={form.effective_date}
                onChange={(e) =>
                  setForm((p) => ({ ...p, effective_date: e.target.value }))
                }
                required
                className="mt-1"
              />
            </div>
            <div>
              <Label>Reason</Label>
              <Textarea
                value={form.reason}
                onChange={(e) =>
                  setForm((p) => ({ ...p, reason: e.target.value }))
                }
                placeholder="Optional — context for the transition"
                rows={3}
                className="mt-1"
              />
            </div>
            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setDialogOpen(false)}
              >
                Cancel
              </Button>
              <Button
                type="submit"
                disabled={transitionMut.isPending || !form.to_status}
              >
                {transitionMut.isPending && (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                )}
                Apply Transition
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </Card>
  );
}
