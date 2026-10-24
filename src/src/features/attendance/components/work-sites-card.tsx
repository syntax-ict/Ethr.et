"use client";

import { useState } from "react";
import { Loader2, MapPinned, Plus, Trash2, X } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
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
import { ErrorState } from "@/components/shared/error-state";
import { EmployeeSelect } from "@/components/shared/employee-select";
import { MapPicker } from "@/components/shared/map-picker/map-picker";
import {
  useAttendanceSites,
  useDeleteAttendanceSite,
  useEmployeeOptions,
  useMapConfig,
  useSaveAttendanceSite,
  type AttendanceSite,
} from "@/features/attendance/api";
import { branchesApi, departmentsApi } from "@/features/organization/api";
import { apiErrorMessage } from "@/lib/api/error-message";
import { useT } from "@/lib/i18n/useT";

interface Draft {
  name: string;
  branch: string;
  latitude: number | null;
  longitude: number | null;
  radius: number;
  is_active: boolean;
  employees: string[];
  departments: string[];
}

const EMPTY: Draft = {
  name: "",
  branch: "none",
  latitude: null,
  longitude: null,
  radius: 150,
  is_active: true,
  employees: [],
  departments: [],
};

function toDraft(site: AttendanceSite): Draft {
  return {
    name: site.name,
    branch: site.branch_public_id ?? "none",
    latitude: site.latitude,
    longitude: site.longitude,
    radius: site.radius_meters,
    is_active: site.is_active,
    employees: site.employee_public_ids as string[],
    departments: site.department_public_ids as string[],
  };
}

/**
 * Places besides the branch where people may punch: a project, a client's
 * office, a farm, a branch's second building. Everyone at the site's branch
 * may use it; further people and whole departments by choice here.
 */
export function WorkSitesCard() {
  const { t } = useT();
  const sites = useAttendanceSites();
  const remove = useDeleteAttendanceSite();
  const [editing, setEditing] = useState<AttendanceSite | "new" | null>(null);

  return (
    <Card className="lg:col-span-2">
      <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3 space-y-0">
        <div>
          <CardTitle className="flex items-center gap-2 text-base">
            <MapPinned className="h-4 w-4" />
            {t("work_sites.title", "Work sites")}
          </CardTitle>
          <p className="mt-1 text-sm text-muted-foreground">
            {t(
              "work_sites.description",
              "Places besides the branch where people may punch. A punch inside a branch or any of its sites counts as on site.",
            )}
          </p>
        </div>
        <Button size="sm" onClick={() => setEditing("new")}>
          <Plus className="mr-1 h-4 w-4" />
          {t("work_sites.add", "Add a site")}
        </Button>
      </CardHeader>
      <CardContent>
        {sites.isError ? (
          <ErrorState onRetry={() => sites.refetch()} />
        ) : sites.isLoading ? (
          <Skeleton className="h-20" />
        ) : (sites.data ?? []).length === 0 ? (
          <p className="text-sm text-muted-foreground">
            {t(
              "work_sites.none",
              "No sites yet. Field teams can only be checked against their branch until you add one.",
            )}
          </p>
        ) : (
          <ul className="divide-y divide-border">
            {(sites.data ?? []).map((site) => (
              <li
                key={site.public_id}
                className="flex flex-wrap items-center justify-between gap-3 py-3"
              >
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium">{site.name}</p>
                  <div className="mt-1 flex flex-wrap gap-1.5 text-xs text-muted-foreground">
                    <span>
                      {t("work_sites.radius_short", ":m m", {
                        m: site.radius_meters,
                      })}
                    </span>
                    {site.branch_name && <span>· {site.branch_name}</span>}
                    {!site.is_active && (
                      <Badge variant="outline">
                        {t("work_sites.inactive", "Off")}
                      </Badge>
                    )}
                  </div>
                </div>
                <div className="flex gap-2">
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setEditing(site)}
                  >
                    {t("work_sites.edit", "Edit")}
                  </Button>
                  <Button
                    size="icon"
                    variant="ghost"
                    aria-label={t("work_sites.delete", "Remove site")}
                    disabled={remove.isPending}
                    onClick={() =>
                      remove.mutate(site.public_id, {
                        onSuccess: () =>
                          toast.success(
                            t("work_sites.deleted", "Site removed"),
                          ),
                        onError: (err) =>
                          toast.error(
                            apiErrorMessage(
                              err,
                              t(
                                "work_sites.delete_failed",
                                "Couldn't remove the site",
                              ),
                            ),
                          ),
                      })
                    }
                  >
                    <Trash2 className="h-4 w-4" />
                  </Button>
                </div>
              </li>
            ))}
          </ul>
        )}
      </CardContent>

      {editing !== null && (
        <SiteDialog
          site={editing === "new" ? null : editing}
          onClose={() => setEditing(null)}
        />
      )}
    </Card>
  );
}

function SiteDialog({
  site,
  onClose,
}: {
  site: AttendanceSite | null;
  onClose: () => void;
}) {
  const { t } = useT();
  const save = useSaveAttendanceSite();
  const mapConfig = useMapConfig();
  const branches = branchesApi.useList();
  const departments = departmentsApi.useList();
  const employees = useEmployeeOptions({ enabled: true });
  const [draft, setDraft] = useState<Draft>(site ? toDraft(site) : EMPTY);
  const [adding, setAdding] = useState("");

  const nameOf = (publicId: string) =>
    employees.data?.find((e) => e.public_id === publicId)?.name ?? publicId;

  function submit() {
    if (
      !draft.name.trim() ||
      draft.latitude === null ||
      draft.longitude === null
    ) {
      toast.error(
        t(
          "work_sites.incomplete",
          "Give the site a name and put it on the map.",
        ),
      );
      return;
    }
    save.mutate(
      {
        publicId: site?.public_id,
        payload: {
          name: draft.name.trim(),
          branch_public_id: draft.branch === "none" ? null : draft.branch,
          latitude: draft.latitude,
          longitude: draft.longitude,
          radius_meters: draft.radius,
          is_active: draft.is_active,
          employee_public_ids: draft.employees,
          department_public_ids: draft.departments,
        },
      },
      {
        onSuccess: () => {
          toast.success(t("work_sites.saved", "Site saved"));
          onClose();
        },
        onError: (err) =>
          toast.error(
            apiErrorMessage(
              err,
              t("work_sites.save_failed", "Couldn't save the site"),
            ),
          ),
      },
    );
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>
            {site
              ? t("work_sites.edit_title", "Edit site")
              : t("work_sites.add_title", "Add a site")}
          </DialogTitle>
          <DialogDescription>
            {t(
              "work_sites.dialog_description",
              "Everyone at the chosen branch may punch here. Add people or departments who work here from elsewhere.",
            )}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div className="grid gap-3 sm:grid-cols-2">
            <div>
              <Label htmlFor="site-name">{t("work_sites.name", "Name")}</Label>
              <Input
                id="site-name"
                className="mt-1"
                value={draft.name}
                onChange={(e) => setDraft({ ...draft, name: e.target.value })}
              />
            </div>
            <div>
              <Label>{t("work_sites.branch", "Branch")}</Label>
              <Select
                value={draft.branch}
                onValueChange={(v) => setDraft({ ...draft, branch: v })}
              >
                <SelectTrigger className="mt-1">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">
                    {t("work_sites.no_branch", "No branch")}
                  </SelectItem>
                  {(branches.data?.data ?? []).map((b) => (
                    <SelectItem key={b.public_id} value={b.public_id}>
                      {b.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>

          <MapPicker
            googleMapsKey={mapConfig.data?.google_maps_key}
            value={{
              latitude: draft.latitude,
              longitude: draft.longitude,
              radius: draft.radius,
            }}
            onChange={(next) =>
              setDraft({
                ...draft,
                latitude: next.latitude,
                longitude: next.longitude,
                radius: next.radius,
              })
            }
          />

          <div>
            <Label>
              {t("work_sites.departments", "Departments that work here")}
            </Label>
            <div className="mt-2 grid gap-2 sm:grid-cols-2">
              {(departments.data?.data ?? []).map((d) => (
                <label
                  key={d.public_id}
                  className="flex items-center gap-2 text-sm"
                >
                  <Checkbox
                    checked={draft.departments.includes(d.public_id)}
                    onCheckedChange={(checked) =>
                      setDraft({
                        ...draft,
                        departments: checked
                          ? [...draft.departments, d.public_id]
                          : draft.departments.filter((x) => x !== d.public_id),
                      })
                    }
                  />
                  {d.name}
                </label>
              ))}
            </div>
          </div>

          <div>
            <Label htmlFor="site-employee">
              {t("work_sites.people", "People who work here")}
            </Label>
            <div className="flex gap-2">
              <div className="flex-1">
                <EmployeeSelect
                  id="site-employee"
                  value={adding}
                  onChange={(publicId) => {
                    setAdding("");
                    if (!draft.employees.includes(publicId)) {
                      setDraft({
                        ...draft,
                        employees: [...draft.employees, publicId],
                      });
                    }
                  }}
                />
              </div>
            </div>
            {draft.employees.length > 0 && (
              <div className="mt-2 flex flex-wrap gap-1.5">
                {draft.employees.map((publicId) => (
                  <Badge key={publicId} variant="secondary" className="gap-1">
                    {nameOf(publicId)}
                    <button
                      type="button"
                      aria-label={t("work_sites.remove_person", "Remove")}
                      onClick={() =>
                        setDraft({
                          ...draft,
                          employees: draft.employees.filter(
                            (x) => x !== publicId,
                          ),
                        })
                      }
                    >
                      <X className="h-3 w-3" />
                    </button>
                  </Badge>
                ))}
              </div>
            )}
          </div>

          <label className="flex items-center gap-2 text-sm">
            <Checkbox
              checked={draft.is_active}
              onCheckedChange={(checked) =>
                setDraft({ ...draft, is_active: checked === true })
              }
            />
            {t("work_sites.active", "People may punch here")}
          </label>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            {t("common.cancel", "Cancel")}
          </Button>
          <Button onClick={submit} disabled={save.isPending}>
            {save.isPending && (
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
            )}
            {t("common.save", "Save")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
