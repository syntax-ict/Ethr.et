"use client";

import { useState } from "react";
import { FileText, Plus, Trash2, Loader2, Pencil } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Badge } from "@/components/ui/badge";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { RoleGate } from "@/components/shared/role-gate";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

interface LeaveType {
  public_id: string;
  name: string;
  code: string;
  default_days: number;
  accrual_type: string;
  is_active: boolean;
}

export default function LeaveTypesPage() {
  const { t } = useT();
  const queryClient = useQueryClient();
  const [dialogOpen, setDialogOpen] = useState(false);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [form, setForm] = useState({
    name: "",
    code: "",
    default_days: "",
    accrual_type: "monthly",
  });

  function openNew() {
    setEditingId(null);
    setForm({ name: "", code: "", default_days: "", accrual_type: "monthly" });
    setDialogOpen(true);
  }

  function openEdit(lt: LeaveType) {
    setEditingId(lt.public_id);
    setForm({
      name: lt.name,
      code: lt.code,
      default_days: String(lt.default_days),
      accrual_type: "monthly",
    });
    setDialogOpen(true);
  }

  const { data, isLoading } = useQuery<{ data: LeaveType[] }>({
    queryKey: ["leave-types"],
    queryFn: async () => {
      const { data } = await apiClient.get("/leave-types");
      return data;
    },
  });

  const createLeaveType = useMutation({
    mutationFn: async (payload: {
      name: string;
      code: string;
      default_days: number;
      accrual_type: string;
    }) => {
      const { data } = await apiClient.post("/leave-types", payload);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["leave-types"] });
      toast.success(t("leave_types_page.created"));
      setDialogOpen(false);
      setForm({
        name: "",
        code: "",
        default_days: "",
        accrual_type: "monthly",
      });
    },
    onError: (err: unknown) => {
      const axiosError = err as { response?: { data?: { detail?: string } } };
      toast.error(
        axiosError.response?.data?.detail ||
          t("leave_types_page.create_failed"),
      );
    },
  });

  const updateLeaveType = useMutation({
    mutationFn: async (payload: {
      name: string;
      code: string;
      default_days: number;
      accrual_type: string;
    }) => {
      const { data } = await apiClient.put(
        `/leave-types/${editingId}`,
        payload,
      );
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["leave-types"] });
      toast.success(t("leave_types_page.updated"));
      setDialogOpen(false);
      setEditingId(null);
    },
    onError: () => toast.error(t("leave_types_page.update_failed")),
  });

  const deleteLeaveType = useMutation({
    mutationFn: async (publicId: string) => {
      await apiClient.delete(`/leave-types/${publicId}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["leave-types"] });
      toast.success(t("leave_types_page.deleted"));
    },
    onError: () => toast.error(t("leave_types_page.delete_failed")),
  });

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    const payload = {
      name: form.name,
      code: form.code,
      default_days: parseInt(form.default_days, 10),
      accrual_type: form.accrual_type,
    };
    if (editingId) {
      updateLeaveType.mutate(payload);
    } else {
      createLeaveType.mutate(payload);
    }
  }

  const leaveTypes = data?.data ?? [];

  return (
    <RoleGate minRole="hr_admin">
      <div className="space-y-6">
        <PageHeader
          title={t("leave_types_page.title")}
          description={t("leave_types_page.description")}
          actions={
            <Button onClick={openNew}>
              <Plus className="mr-2 h-4 w-4" />
              {t("leave_types_page.add")}
            </Button>
          }
        />

        {isLoading ? (
          <div className="space-y-3">
            {Array.from({ length: 4 }).map((_, i) => (
              <Skeleton key={i} className="h-12 w-full" />
            ))}
          </div>
        ) : leaveTypes.length === 0 ? (
          <EmptyState
            icon={FileText}
            title={t("leave_types_page.no_leave_types")}
            description={t("leave_types_page.no_leave_types_desc")}
          />
        ) : (
          <Card>
            <CardHeader>
              <CardTitle className="text-base">
                {t("leave_types_page.title")}
              </CardTitle>
            </CardHeader>
            <CardContent className="p-0">
              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead>
                    <tr className="border-b bg-muted/50">
                      <th className="px-4 py-3 text-left text-sm font-medium text-muted-foreground">
                        {t("common.name")}
                      </th>
                      <th className="px-4 py-3 text-left text-sm font-medium text-muted-foreground">
                        {t("leave_types_page.code")}
                      </th>
                      <th className="px-4 py-3 text-right text-sm font-medium text-muted-foreground">
                        {t("leave_types_page.default_days")}
                      </th>
                      <th className="px-4 py-3 text-left text-sm font-medium text-muted-foreground">
                        {t("leave_types_page.accrual_type")}
                      </th>
                      <th className="px-4 py-3 text-left text-sm font-medium text-muted-foreground">
                        {t("common.status")}
                      </th>
                      <th className="px-4 py-3 text-right text-sm font-medium text-muted-foreground">
                        {t("common.actions")}
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {leaveTypes.map((lt) => (
                      <tr
                        key={lt.public_id}
                        className="border-b last:border-0 hover:bg-muted/30"
                      >
                        <td className="px-4 py-3 text-sm font-medium text-foreground">
                          {lt.name}
                        </td>
                        <td className="px-4 py-3 text-sm font-mono text-muted-foreground">
                          {lt.code}
                        </td>
                        <td className="px-4 py-3 text-right text-sm text-muted-foreground">
                          {lt.default_days}
                        </td>
                        <td className="px-4 py-3 text-sm capitalize text-muted-foreground">
                          {lt.accrual_type.replace(/_/g, " ")}
                        </td>
                        <td className="px-4 py-3">
                          {lt.is_active ? (
                            <Badge
                              variant="outline"
                              className="border-0 bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300"
                            >
                              {t("webhooks_page.active")}
                            </Badge>
                          ) : (
                            <Badge
                              variant="outline"
                              className="border-0 bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400"
                            >
                              {t("roles_page.inactive")}
                            </Badge>
                          )}
                        </td>
                        <td className="px-4 py-3 text-right">
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => openEdit(lt)}
                          >
                            <Pencil className="h-4 w-4" />
                          </Button>
                          <Button
                            variant="ghost"
                            size="sm"
                            className="text-red-600 hover:bg-red-50 hover:text-red-700 dark:hover:bg-red-950"
                            onClick={() => deleteLeaveType.mutate(lt.public_id)}
                            disabled={deleteLeaveType.isPending}
                          >
                            <Trash2 className="h-4 w-4" />
                          </Button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </CardContent>
          </Card>
        )}

        <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>
                {editingId
                  ? t("leave_types_page.edit")
                  : t("leave_types_page.add")}
              </DialogTitle>
            </DialogHeader>
            <form onSubmit={handleSubmit} className="space-y-4">
              <div>
                <Label htmlFor="lt_name">{t("common.name")}</Label>
                <Input
                  id="lt_name"
                  value={form.name}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, name: e.target.value }))
                  }
                  placeholder={t("leave_types_page.name_placeholder")}
                  required
                  className="mt-1"
                />
              </div>
              <div>
                <Label htmlFor="lt_code">{t("leave_types_page.code")}</Label>
                <Input
                  id="lt_code"
                  value={form.code}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, code: e.target.value }))
                  }
                  placeholder={t("leave_types_page.code_placeholder")}
                  required
                  className="mt-1"
                />
              </div>
              <div>
                <Label htmlFor="lt_days">
                  {t("leave_types_page.default_days")}
                </Label>
                <Input
                  id="lt_days"
                  type="number"
                  min="0"
                  value={form.default_days}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, default_days: e.target.value }))
                  }
                  placeholder={t("leave_types_page.days_placeholder")}
                  required
                  className="mt-1"
                />
              </div>
              <div>
                <Label>{t("leave_types_page.accrual_type")}</Label>
                <Select
                  value={form.accrual_type}
                  onValueChange={(v) =>
                    setForm((p) => ({ ...p, accrual_type: v }))
                  }
                >
                  <SelectTrigger className="mt-1">
                    <SelectValue
                      placeholder={t("leave_types_page.select_accrual_type")}
                    />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="monthly">
                      {t("leave_types_page.monthly")}
                    </SelectItem>
                    <SelectItem value="annual">
                      {t("leave_types_page.annual")}
                    </SelectItem>
                    <SelectItem value="immediate">
                      {t("leave_types_page.immediate")}
                    </SelectItem>
                    <SelectItem value="one_time">
                      {t("leave_types_page.one_time")}
                    </SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <DialogFooter>
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => setDialogOpen(false)}
                >
                  {t("common.cancel")}
                </Button>
                <Button
                  type="submit"
                  disabled={
                    createLeaveType.isPending || updateLeaveType.isPending
                  }
                >
                  {(createLeaveType.isPending || updateLeaveType.isPending) && (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  )}
                  {editingId
                    ? t("leave_types_page.save_changes")
                    : t("leave_types_page.add")}
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>
      </div>
    </RoleGate>
  );
}
