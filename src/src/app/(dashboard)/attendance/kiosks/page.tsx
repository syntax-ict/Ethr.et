"use client";

import { useCallback, useState } from "react";
import {
  Monitor,
  Plus,
  RefreshCw,
  Power,
  PowerOff,
  Trash2,
  Copy,
  Loader2,
  KeyRound,
  MoreVertical,
  CheckCircle2,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { PageHeader } from "@/components/shared/page-header";
import { RoleGate } from "@/components/shared/role-gate";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

interface KioskSession {
  public_id: string;
  name: string;
  branch?: { public_id: string; name: string } | null;
  device_identifier: string | null;
  status: "active" | "inactive";
  token?: string;
  last_activity_at: string | null;
  activated_at: string | null;
  deactivated_at: string | null;
  created_at: string;
}

export default function KioskSessionsPage() {
  const { t } = useT();
  const queryClient = useQueryClient();
  const [showRegister, setShowRegister] = useState(false);
  const [showToken, setShowToken] = useState<string | null>(null);
  const [copiedToken, setCopiedToken] = useState(false);

  // Register form
  const [regName, setRegName] = useState("");
  const [regBranch, setRegBranch] = useState("");
  const [regPin, setRegPin] = useState("");
  const [regDevice, setRegDevice] = useState("");

  const { data: sessions, isLoading } = useQuery({
    queryKey: ["kiosk-sessions"],
    queryFn: async () => (await apiClient.get("/kiosk-sessions")).data,
  });

  const { data: branches } = useQuery({
    queryKey: ["org", "branches"],
    queryFn: async () => (await apiClient.get("/organization/branches")).data,
  });

  const invalidate = useCallback(() => {
    queryClient.invalidateQueries({ queryKey: ["kiosk-sessions"] });
  }, [queryClient]);

  const register = useMutation({
    mutationFn: async () => {
      const { data } = await apiClient.post("/kiosk-sessions", {
        name: regName,
        branch_public_id: regBranch,
        admin_pin: regPin,
        device_identifier: regDevice || undefined,
      });
      return data;
    },
    onSuccess: (data) => {
      invalidate();
      setShowRegister(false);
      setRegName("");
      setRegBranch("");
      setRegPin("");
      setRegDevice("");
      if (data.token) {
        setShowToken(data.token);
      }
      toast.success(t("attendance.kiosks_page.registered"));
    },
    onError: () => toast.error(t("attendance.kiosks_page.register_failed")),
  });

  const deactivate = useMutation({
    mutationFn: async (id: string) =>
      apiClient.post(`/kiosk-sessions/${id}/deactivate`),
    onSuccess: () => {
      invalidate();
      toast.success(t("attendance.kiosks_page.deactivated"));
    },
    onError: () => toast.error(t("attendance.kiosks_page.deactivate_failed")),
  });

  const activate = useMutation({
    mutationFn: async (id: string) =>
      apiClient.post(`/kiosk-sessions/${id}/activate`),
    onSuccess: () => {
      invalidate();
      toast.success(t("attendance.kiosks_page.activated"));
    },
    onError: () => toast.error(t("attendance.kiosks_page.activate_failed")),
  });

  const regenerate = useMutation({
    mutationFn: async (id: string) => {
      const { data } = await apiClient.post(
        `/kiosk-sessions/${id}/regenerate-token`,
      );
      return data;
    },
    onSuccess: (data) => {
      invalidate();
      if (data.token) setShowToken(data.token);
      toast.success(t("attendance.kiosks_page.token_regenerated"));
    },
    onError: () => toast.error(t("attendance.kiosks_page.regenerate_failed")),
  });

  const remove = useMutation({
    mutationFn: async (id: string) => apiClient.delete(`/kiosk-sessions/${id}`),
    onSuccess: () => {
      invalidate();
      toast.success(t("attendance.kiosks_page.deleted"));
    },
    onError: () => toast.error(t("attendance.kiosks_page.delete_failed")),
  });

  function copyToken(token: string) {
    navigator.clipboard.writeText(token);
    setCopiedToken(true);
    setTimeout(() => setCopiedToken(false), 2000);
  }

  const list: KioskSession[] = sessions?.data ?? [];

  return (
    <RoleGate minRole="hr_admin">
      <div className="space-y-6">
        <PageHeader
          title={t("attendance.kiosks_page.title")}
          description={t("attendance.kiosks_page.description")}
          actions={
            <Button onClick={() => setShowRegister(true)}>
              <Plus className="mr-2 h-4 w-4" />{" "}
              {t("attendance.kiosks_page.register_kiosk")}
            </Button>
          }
        />

        {isLoading ? (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {Array.from({ length: 3 }).map((_, i) => (
              <Skeleton key={i} className="h-40" />
            ))}
          </div>
        ) : list.length === 0 ? (
          <Card>
            <CardContent className="flex flex-col items-center justify-center py-16 text-center">
              <Monitor className="h-12 w-12 text-muted-foreground/40" />
              <p className="mt-3 text-sm text-muted-foreground">
                {t("attendance.kiosks_page.empty")}
              </p>
              <Button className="mt-4" onClick={() => setShowRegister(true)}>
                <Plus className="mr-2 h-4 w-4" />{" "}
                {t("attendance.kiosks_page.register_first")}
              </Button>
            </CardContent>
          </Card>
        ) : (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {list.map((k) => (
              <Card key={k.public_id}>
                <CardHeader className="pb-3">
                  <div className="flex items-start justify-between">
                    <div className="flex items-center gap-2">
                      <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-primary/10">
                        <Monitor className="h-4 w-4 text-primary" />
                      </div>
                      <div>
                        <CardTitle className="text-sm">{k.name}</CardTitle>
                        <p className="text-xs text-muted-foreground">
                          {k.branch?.name ??
                            t("attendance.kiosks_page.no_branch")}
                        </p>
                      </div>
                    </div>
                    <div className="flex items-center gap-1.5">
                      <Badge
                        variant={
                          k.status === "active" ? "success" : "secondary"
                        }
                      >
                        {k.status}
                      </Badge>
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button
                            variant="ghost"
                            size="icon"
                            className="h-7 w-7"
                            aria-label={t(
                              "attendance.kiosks_page.row_actions",
                              "Kiosk actions",
                            )}
                          >
                            <MoreVertical className="h-3.5 w-3.5" />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                          {k.status === "active" ? (
                            <DropdownMenuItem
                              onClick={() => deactivate.mutate(k.public_id)}
                            >
                              <PowerOff className="mr-2 h-3.5 w-3.5" />{" "}
                              {t("attendance.kiosks_page.deactivate")}
                            </DropdownMenuItem>
                          ) : (
                            <DropdownMenuItem
                              onClick={() => activate.mutate(k.public_id)}
                            >
                              <Power className="mr-2 h-3.5 w-3.5" />{" "}
                              {t("attendance.kiosks_page.activate")}
                            </DropdownMenuItem>
                          )}
                          <DropdownMenuItem
                            onClick={() => regenerate.mutate(k.public_id)}
                          >
                            <RefreshCw className="mr-2 h-3.5 w-3.5" />{" "}
                            {t("attendance.kiosks_page.regenerate_token")}
                          </DropdownMenuItem>
                          <DropdownMenuSeparator />
                          <DropdownMenuItem
                            className="text-destructive"
                            onClick={() => remove.mutate(k.public_id)}
                          >
                            <Trash2 className="mr-2 h-3.5 w-3.5" />{" "}
                            {t("common.delete")}
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>
                  </div>
                </CardHeader>
                <CardContent className="space-y-2 text-xs text-muted-foreground">
                  {k.device_identifier && (
                    <p>
                      {t("attendance.kiosks_page.device")}:{" "}
                      <span className="font-mono">{k.device_identifier}</span>
                    </p>
                  )}
                  {k.last_activity_at && (
                    <p>
                      {t("attendance.kiosks_page.last_active")}:{" "}
                      {new Date(k.last_activity_at).toLocaleString()}
                    </p>
                  )}
                  <p>
                    {t("attendance.kiosks_page.created")}:{" "}
                    {new Date(k.created_at).toLocaleDateString()}
                  </p>
                </CardContent>
              </Card>
            ))}
          </div>
        )}

        {/* Register Dialog */}
        <Dialog open={showRegister} onOpenChange={setShowRegister}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>
                {t("attendance.kiosks_page.register_title")}
              </DialogTitle>
              <DialogDescription>
                {t("attendance.kiosks_page.register_desc")}
              </DialogDescription>
            </DialogHeader>
            <div className="space-y-4">
              <div>
                <Label htmlFor="kiosk-name">
                  {t("attendance.kiosks_page.kiosk_name")}
                </Label>
                <Input
                  id="kiosk-name"
                  value={regName}
                  onChange={(e) => setRegName(e.target.value)}
                  placeholder={t(
                    "attendance.kiosks_page.kiosk_name_placeholder",
                  )}
                  className="mt-1"
                />
              </div>
              <div>
                <Label htmlFor="branch">
                  {t("attendance.kiosks_page.branch")}
                </Label>
                <Select value={regBranch} onValueChange={setRegBranch}>
                  <SelectTrigger id="branch" className="mt-1">
                    <SelectValue
                      placeholder={t("attendance.kiosks_page.select_branch")}
                    />
                  </SelectTrigger>
                  <SelectContent>
                    {branches?.data?.map(
                      (b: { public_id: string; name: string }) => (
                        <SelectItem key={b.public_id} value={b.public_id}>
                          {b.name}
                        </SelectItem>
                      ),
                    )}
                  </SelectContent>
                </Select>
              </div>
              <div>
                <Label htmlFor="admin-pin">
                  {t("attendance.kiosks_page.admin_pin")}
                </Label>
                <Input
                  id="admin-pin"
                  type="password"
                  value={regPin}
                  onChange={(e) =>
                    setRegPin(e.target.value.replace(/\D/g, "").slice(0, 8))
                  }
                  placeholder={t(
                    "attendance.kiosks_page.admin_pin_placeholder",
                  )}
                  maxLength={8}
                  className="mt-1 font-mono tracking-widest"
                />
              </div>
              <div>
                <Label htmlFor="device-identifier">
                  {t("attendance.kiosks_page.device_identifier")}
                </Label>
                <Input
                  id="device-identifier"
                  value={regDevice}
                  onChange={(e) => setRegDevice(e.target.value)}
                  placeholder={t(
                    "attendance.kiosks_page.device_identifier_placeholder",
                  )}
                  className="mt-1"
                />
              </div>
            </div>
            <DialogFooter>
              <Button variant="outline" onClick={() => setShowRegister(false)}>
                {t("common.cancel")}
              </Button>
              <Button
                onClick={() => register.mutate()}
                disabled={
                  !regName ||
                  !regBranch ||
                  regPin.length < 4 ||
                  register.isPending
                }
              >
                {register.isPending && (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                )}
                {t("attendance.kiosks_page.register")}
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>

        {/* Token Display Dialog */}
        <Dialog
          open={!!showToken}
          onOpenChange={(open) => !open && setShowToken(null)}
        >
          <DialogContent>
            <DialogHeader>
              <DialogTitle className="flex items-center gap-2">
                <KeyRound className="h-5 w-5" />{" "}
                {t("attendance.kiosks_page.kiosk_token")}
              </DialogTitle>
              <DialogDescription>
                {t("attendance.kiosks_page.token_desc")}
              </DialogDescription>
            </DialogHeader>
            <div className="relative">
              <Input
                value={showToken ?? ""}
                readOnly
                className="font-mono text-xs pr-16"
                onClick={(e) => (e.target as HTMLInputElement).select()}
              />
              <Button
                variant="ghost"
                size="sm"
                className="absolute right-1 top-1 h-7"
                onClick={() => showToken && copyToken(showToken)}
              >
                {copiedToken ? (
                  <>
                    <CheckCircle2 className="mr-1 h-3 w-3 text-success" />{" "}
                    {t("attendance.kiosks_page.copied")}
                  </>
                ) : (
                  <>
                    <Copy className="mr-1 h-3 w-3" />{" "}
                    {t("attendance.kiosks_page.copy")}
                  </>
                )}
              </Button>
            </div>
            <DialogFooter>
              <Button onClick={() => setShowToken(null)}>
                {t("attendance.kiosks_page.done")}
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </div>
    </RoleGate>
  );
}
