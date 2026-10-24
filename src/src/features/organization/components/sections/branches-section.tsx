"use client";

import { Building2, MapPin } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { TableRowActions } from "@/components/shared/simple-table";
import {
  ActiveToggle,
  CrudField,
  CrudFieldRow,
  CrudSection,
  useCrudSection,
} from "@/components/shared/crud-section";
import { branchesApi, type Branch } from "@/features/organization/api";
import { MapPicker } from "@/components/shared/map-picker/map-picker";
import { useMapConfig } from "@/features/attendance/api";
import { useT } from "@/lib/i18n/useT";
import { useCanDeleteOrg } from "./shared";

const EMPTY = {
  name: "",
  name_am: "",
  code: "",
  address: "",
  city: "",
  phone: "",
  latitude: "",
  longitude: "",
  geofence_radius_meters: "100",
  is_active: true,
};

export function BranchesSection() {
  const { t } = useT();
  const canDelete = useCanDeleteOrg();
  const list = branchesApi.useList();
  const crud = useCrudSection({
    create: branchesApi.useCreate(),
    update: branchesApi.useUpdate(),
    remove: branchesApi.useDelete(),
    empty: EMPTY,
    toForm: (b: Branch) => ({
      name: b.name,
      name_am: b.name_am ?? "",
      code: b.code ?? "",
      address: b.address ?? "",
      city: b.city ?? "",
      phone: b.phone ?? "",
      latitude: b.latitude?.toString() ?? "",
      longitude: b.longitude?.toString() ?? "",
      geofence_radius_meters: b.geofence_radius_meters?.toString() ?? "100",
      is_active: b.is_active,
    }),
    toPayload: (f) => ({
      name: f.name,
      name_am: f.name_am || null,
      code: f.code || null,
      address: f.address || null,
      city: f.city || null,
      phone: f.phone || null,
      latitude: f.latitude ? parseFloat(f.latitude) : null,
      longitude: f.longitude ? parseFloat(f.longitude) : null,
      geofence_radius_meters: f.geofence_radius_meters
        ? parseInt(f.geofence_radius_meters)
        : null,
      is_active: f.is_active,
    }),
    messages: {
      created: t("org.branch.created", "Branch created"),
      updated: t("org.branch.updated", "Branch updated"),
      deleteTitle: (b) =>
        t("org.branch.delete_confirm", 'Delete branch ":name"?', {
          name: b.name,
        }),
    },
  });
  const { form, set } = crud;
  const mapConfig = useMapConfig();
  const items = list.data?.data ?? [];

  return (
    <CrudSection
      crud={crud}
      title={t("org.tab.branches", "Branches")}
      query={list}
      isEmpty={items.length === 0}
      emptyIcon={Building2}
      emptyTitle={t("org.branch.empty_title", "No branches")}
      emptyDescription={t(
        "org.branch.empty_desc",
        "Add your first branch to organize multi-location operations",
      )}
      dialogTitle={{
        create: t("org.branch.new_title", "New Branch"),
        edit: t("org.branch.edit_title", "Edit Branch"),
      }}
      dialogClassName="max-w-2xl"
      form={
        <>
          <CrudFieldRow>
            <CrudField label={t("org.field.name", "Name")} required>
              <Input
                value={form.name}
                onChange={(e) => set("name", e.target.value)}
                required
              />
            </CrudField>
            <CrudField label={t("org.field.name_am", "Name (Amharic)")}>
              <Input
                value={form.name_am}
                onChange={(e) => set("name_am", e.target.value)}
              />
            </CrudField>
            <CrudField label={t("org.field.code", "Code")}>
              <Input
                value={form.code}
                onChange={(e) => set("code", e.target.value)}
                placeholder="HQ, BR01..."
              />
            </CrudField>
            <CrudField label={t("common.phone", "Phone")}>
              <Input
                value={form.phone}
                onChange={(e) => set("phone", e.target.value)}
              />
            </CrudField>
          </CrudFieldRow>
          <CrudField label={t("org.field.address", "Address")}>
            <Input
              value={form.address}
              onChange={(e) => set("address", e.target.value)}
            />
          </CrudField>
          <CrudField label={t("org.field.city", "City")}>
            <Input
              value={form.city}
              onChange={(e) => set("city", e.target.value)}
            />
          </CrudField>
          <CrudField label={t("org.field.location", "Location and area")}>
            <MapPicker
              googleMapsKey={mapConfig.data?.google_maps_key}
              value={{
                latitude: form.latitude === "" ? null : Number(form.latitude),
                longitude:
                  form.longitude === "" ? null : Number(form.longitude),
                radius: Number(form.geofence_radius_meters) || 100,
              }}
              onChange={(next) => {
                set(
                  "latitude",
                  next.latitude === null ? "" : String(next.latitude),
                );
                set(
                  "longitude",
                  next.longitude === null ? "" : String(next.longitude),
                );
                set("geofence_radius_meters", String(next.radius));
              }}
            />
          </CrudField>
          <ActiveToggle
            checked={form.is_active}
            onChange={(v) => set("is_active", v)}
          />
        </>
      }
    >
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {items.map((b) => (
          <Card key={b.public_id} className="transition-shadow hover:shadow-md">
            <CardContent className="p-4">
              <div className="flex items-start justify-between">
                <div className="min-w-0">
                  <p className="truncate font-semibold text-foreground">
                    {b.name}
                  </p>
                  {b.code && (
                    <p className="font-mono text-xs text-muted-foreground">
                      {b.code}
                    </p>
                  )}
                  {b.address && (
                    <p className="mt-1 flex items-start gap-1 text-xs text-muted-foreground">
                      <MapPin
                        aria-hidden="true"
                        className="mt-0.5 h-3 w-3 shrink-0"
                      />
                      <span className="line-clamp-2">{b.address}</span>
                    </p>
                  )}
                  <div className="mt-2 flex flex-wrap gap-1">
                    {!b.is_active && (
                      <Badge variant="outline" className="text-xs">
                        {t("org.inactive", "Inactive")}
                      </Badge>
                    )}
                    {/* These read "3 dept" and "12 emp" in every language. */}
                    {b.departments_count != null && (
                      <Badge variant="outline" className="text-xs">
                        {t(
                          "org.branch.departments_count",
                          ":count departments",
                          {
                            count: b.departments_count,
                          },
                        )}
                      </Badge>
                    )}
                    {b.employees_count != null && (
                      <Badge variant="outline" className="text-xs">
                        {t("org.branch.employees_count", ":count employees", {
                          count: b.employees_count,
                        })}
                      </Badge>
                    )}
                  </div>
                </div>
                <TableRowActions
                  onEdit={() => crud.openEdit(b)}
                  onDelete={canDelete ? () => crud.requestDelete(b) : undefined}
                />
              </div>
            </CardContent>
          </Card>
        ))}
      </div>
    </CrudSection>
  );
}
