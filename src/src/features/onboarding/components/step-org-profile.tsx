"use client";

import { useState, useEffect } from "react";
import {
  Building2,
  Landmark,
  Hospital,
  Factory,
  Heart,
  Hotel,
  GraduationCap,
  Briefcase,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { cn } from "@/lib/utils";
import type { OrganizationTemplate } from "../types";

const templateIcons: Record<
  string,
  React.ComponentType<{ className?: string }>
> = {
  government: Landmark,
  bank: Landmark,
  hospital: Hospital,
  manufacturing: Factory,
  ngo: Heart,
  hotel: Hotel,
  university: GraduationCap,
  general: Briefcase,
};

interface StepOrgProfileProps {
  templates: OrganizationTemplate[];
  data: Record<string, unknown>;
  onNext: (data: Record<string, unknown>) => void;
}

export function StepOrgProfile({
  templates,
  data,
  onNext,
}: StepOrgProfileProps) {
  const [orgName, setOrgName] = useState(
    (data.organization_name as string) || "",
  );
  const [selectedTemplate, setSelectedTemplate] = useState(
    (data.template_slug as string) || "",
  );
  const [size, setSize] = useState((data.size_range as string) || "1-50");

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl font-semibold">Organization Profile</h2>
        <p className="mt-1 text-sm text-muted-foreground">
          Tell us about your organization so we can configure ETHR for you
        </p>
      </div>

      <div className="space-y-4">
        <div className="space-y-2">
          <Label htmlFor="org_name">Organization Name</Label>
          <Input
            id="org_name"
            value={orgName}
            onChange={(e) => setOrgName(e.target.value)}
            required
          />
        </div>

        <div className="space-y-2">
          <Label htmlFor="size">Organization Size</Label>
          <select
            id="size"
            value={size}
            onChange={(e) => setSize(e.target.value)}
            className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
          >
            <option value="1-50">1 - 50 employees</option>
            <option value="51-200">51 - 200 employees</option>
            <option value="201-500">201 - 500 employees</option>
            <option value="501-1000">501 - 1,000 employees</option>
            <option value="1000+">1,000+ employees</option>
          </select>
        </div>

        <div className="space-y-3">
          <Label>Industry Template</Label>
          <p className="text-xs text-muted-foreground">
            Select a template to pre-populate departments, shifts, and leave
            types
          </p>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {templates.map((template) => {
              const Icon = templateIcons[template.slug] || Building2;
              return (
                <button
                  key={template.slug}
                  type="button"
                  onClick={() => setSelectedTemplate(template.slug)}
                  className={cn(
                    "flex flex-col items-center gap-2 rounded-xl border p-4 text-center transition-all hover:shadow-md",
                    selectedTemplate === template.slug
                      ? "border-primary bg-primary/5 ring-1 ring-primary"
                      : "border-border",
                  )}
                >
                  <Icon className="h-6 w-6 text-primary" />
                  <span className="text-xs font-medium">{template.name}</span>
                </button>
              );
            })}
          </div>
        </div>
      </div>

      <div className="flex justify-end">
        <Button
          onClick={() =>
            onNext({
              organization_name: orgName,
              template_slug: selectedTemplate,
              size_range: size,
            })
          }
          disabled={!orgName}
        >
          Next
        </Button>
      </div>
    </div>
  );
}
