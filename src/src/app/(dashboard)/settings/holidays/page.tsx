"use client";

import { useState } from "react";
import { CalendarDays, Plus, Trash2, Wand2, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Badge } from "@/components/ui/badge";
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
import { toast } from "sonner";

interface Holiday {
  public_id: string;
  name: string;
  date: string;
  recurring: boolean;
}

export default function HolidaysPage() {
  const queryClient = useQueryClient();
  const [dialogOpen, setDialogOpen] = useState(false);
  const [form, setForm] = useState({ name: "", date: "", recurring: false });

  const { data, isLoading } = useQuery<{ data: Holiday[] }>({
    queryKey: ["holidays"],
    queryFn: async () => {
      const { data } = await apiClient.get("/holidays");
      return data;
    },
  });

  const createHoliday = useMutation({
    mutationFn: async (payload: {
      name: string;
      date: string;
      recurring: boolean;
    }) => {
      const { data } = await apiClient.post("/holidays", payload);
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["holidays"] });
      toast.success("Holiday added");
      setDialogOpen(false);
      setForm({ name: "", date: "", recurring: false });
    },
    onError: (err: unknown) => {
      const axiosError = err as { response?: { data?: { detail?: string } } };
      toast.error(axiosError.response?.data?.detail || "Failed to add holiday");
    },
  });

  const deleteHoliday = useMutation({
    mutationFn: async (publicId: string) => {
      await apiClient.delete(`/holidays/${publicId}`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["holidays"] });
      toast.success("Holiday deleted");
    },
    onError: () => toast.error("Failed to delete holiday"),
  });

  const autoDetect = useMutation({
    mutationFn: async () => {
      const { data } = await apiClient.post("/holidays/auto-detect");
      return data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["holidays"] });
      toast.success("Ethiopian holidays detected and added");
    },
    onError: () => toast.error("Failed to auto-detect holidays"),
  });

  function handleCreate(e: React.FormEvent) {
    e.preventDefault();
    createHoliday.mutate(form);
  }

  const holidays = data?.data ?? [];

  return (
    <RoleGate minRole="hr_admin">
      <div className="space-y-6">
        <PageHeader
          title="Holidays"
          description="Manage public holidays and non-working days"
          actions={
            <div className="flex items-center gap-2">
              <Button
                variant="outline"
                onClick={() => autoDetect.mutate()}
                disabled={autoDetect.isPending}
              >
                {autoDetect.isPending ? (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                ) : (
                  <Wand2 className="mr-2 h-4 w-4" />
                )}
                Auto-Detect Ethiopian Holidays
              </Button>
              <Button onClick={() => setDialogOpen(true)}>
                <Plus className="mr-2 h-4 w-4" />
                Add Holiday
              </Button>
            </div>
          }
        />

        {isLoading ? (
          <div className="space-y-3">
            {Array.from({ length: 4 }).map((_, i) => (
              <Skeleton key={i} className="h-12 w-full" />
            ))}
          </div>
        ) : holidays.length === 0 ? (
          <EmptyState
            icon={CalendarDays}
            title="No holidays configured"
            description="Add holidays manually or auto-detect Ethiopian holidays"
          />
        ) : (
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Holidays</CardTitle>
            </CardHeader>
            <CardContent className="p-0">
              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead>
                    <tr className="border-b bg-muted/50">
                      <th className="px-4 py-3 text-left text-sm font-medium text-muted-foreground">
                        Name
                      </th>
                      <th className="px-4 py-3 text-left text-sm font-medium text-muted-foreground">
                        Date
                      </th>
                      <th className="px-4 py-3 text-left text-sm font-medium text-muted-foreground">
                        Recurring
                      </th>
                      <th className="px-4 py-3 text-right text-sm font-medium text-muted-foreground">
                        Actions
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {holidays.map((holiday) => (
                      <tr
                        key={holiday.public_id}
                        className="border-b last:border-0 hover:bg-muted/30"
                      >
                        <td className="px-4 py-3 text-sm font-medium text-foreground">
                          {holiday.name}
                        </td>
                        <td className="px-4 py-3 text-sm text-muted-foreground">
                          {holiday.date}
                        </td>
                        <td className="px-4 py-3">
                          {holiday.recurring ? (
                            <Badge
                              variant="outline"
                              className="border-0 bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300"
                            >
                              Recurring
                            </Badge>
                          ) : (
                            <Badge
                              variant="outline"
                              className="border-0 bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400"
                            >
                              One-time
                            </Badge>
                          )}
                        </td>
                        <td className="px-4 py-3 text-right">
                          <Button
                            variant="ghost"
                            size="sm"
                            className="text-red-600 hover:bg-red-50 hover:text-red-700 dark:hover:bg-red-950"
                            onClick={() =>
                              deleteHoliday.mutate(holiday.public_id)
                            }
                            disabled={deleteHoliday.isPending}
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
              <DialogTitle>Add Holiday</DialogTitle>
            </DialogHeader>
            <form onSubmit={handleCreate} className="space-y-4">
              <div>
                <Label htmlFor="holiday_name">Name</Label>
                <Input
                  id="holiday_name"
                  value={form.name}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, name: e.target.value }))
                  }
                  placeholder="e.g. Ethiopian New Year"
                  required
                  className="mt-1"
                />
              </div>
              <div>
                <Label htmlFor="holiday_date">Date</Label>
                <Input
                  id="holiday_date"
                  type="date"
                  value={form.date}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, date: e.target.value }))
                  }
                  required
                  className="mt-1"
                />
              </div>
              <div className="flex items-center gap-2">
                <input
                  id="holiday_recurring"
                  type="checkbox"
                  checked={form.recurring}
                  onChange={(e) =>
                    setForm((p) => ({ ...p, recurring: e.target.checked }))
                  }
                  className="h-4 w-4 rounded border-gray-300"
                />
                <Label htmlFor="holiday_recurring" className="cursor-pointer">
                  Recurring every year
                </Label>
              </div>
              <DialogFooter>
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => setDialogOpen(false)}
                >
                  Cancel
                </Button>
                <Button type="submit" disabled={createHoliday.isPending}>
                  {createHoliday.isPending && (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  )}
                  Add Holiday
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>
      </div>
    </RoleGate>
  );
}
