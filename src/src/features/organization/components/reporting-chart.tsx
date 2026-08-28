"use client";

import { useMemo, useState } from "react";
import {
  ChevronDown,
  ChevronRight,
  Users,
  Layers,
  UserRound,
} from "lucide-react";
import { Card, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { EmployeeAvatar } from "@/components/shared/employee-avatar";
import { EmptyState } from "@/components/shared/empty-state";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { useT } from "@/lib/i18n/useT";
import {
  useReportingTree,
  type ReportingNode,
} from "@/features/organization/api";

// ── Pure helpers (exported for tests) ──────────────────────────────

/** Count every person in the forest. */
export function countPeople(nodes: ReportingNode[]): number {
  return nodes.reduce(
    (sum, n) => sum + 1 + countPeople(n.direct_reports ?? []),
    0,
  );
}

/** Deepest reporting level (1 = a flat list of top managers). */
export function reportingDepth(nodes: ReportingNode[]): number {
  if (nodes.length === 0) return 0;
  return (
    1 + Math.max(0, ...nodes.map((n) => reportingDepth(n.direct_reports ?? [])))
  );
}

/** Direct reports summed across the whole subtree beneath a person. */
export function subtreeReports(node: ReportingNode): number {
  const reports = node.direct_reports ?? [];
  return reports.reduce((sum, r) => sum + 1 + subtreeReports(r), 0);
}

/** IDs of everyone who manages at least one person — the collapsible nodes. */
export function managerIds(nodes: ReportingNode[]): string[] {
  const ids: string[] = [];
  for (const n of nodes) {
    const reports = n.direct_reports ?? [];
    if (reports.length > 0) {
      ids.push(n.public_id);
      ids.push(...managerIds(reports));
    }
  }
  return ids;
}

// ── Presentational tree ────────────────────────────────────────────

function ReportingNodeRow({
  node,
  depth,
  collapsed,
  onToggle,
}: {
  node: ReportingNode;
  depth: number;
  collapsed: Set<string>;
  onToggle: (id: string) => void;
}) {
  const { t } = useT();
  const reports = node.direct_reports ?? [];
  const hasReports = reports.length > 0;
  const isOpen = !collapsed.has(node.public_id);
  const rolled = subtreeReports(node);

  return (
    <li>
      <div
        className="group flex items-center gap-2 rounded-md py-1.5 pr-2 hover:bg-muted/50"
        style={{ paddingLeft: `${depth * 20}px` }}
      >
        {hasReports ? (
          <button
            type="button"
            onClick={() => onToggle(node.public_id)}
            aria-expanded={isOpen}
            aria-label={
              isOpen
                ? t("org.structure.collapse", "Collapse")
                : t("org.structure.expand", "Expand")
            }
            className="flex h-5 w-5 shrink-0 items-center justify-center rounded text-muted-foreground hover:bg-muted hover:text-foreground"
          >
            {isOpen ? (
              <ChevronDown className="h-4 w-4" />
            ) : (
              <ChevronRight className="h-4 w-4" />
            )}
          </button>
        ) : (
          <span className="h-5 w-5 shrink-0" aria-hidden="true" />
        )}

        <EmployeeAvatar
          name={node.name}
          photoThumbUrl={node.photo_thumb_url}
          photoUrl={node.photo_url}
          className="h-6 w-6 shrink-0 text-[10px]"
        />

        {/* See org-chart.tsx — truncation is intentional, but the full value
            must stay recoverable for sighted users. */}
        <span
          className="truncate text-sm font-medium text-foreground"
          title={node.name}
        >
          {node.name}
        </span>

        {node.position && (
          <span
            className="truncate text-xs text-muted-foreground"
            title={node.position}
          >
            {node.position}
          </span>
        )}

        {hasReports && (
          <span
            className="ml-auto flex shrink-0 items-center gap-1 text-xs text-muted-foreground"
            title={t(
              "org.reporting.reports_hint",
              ":direct direct reports, :rolled in total",
              { direct: reports.length, rolled },
            )}
          >
            <Users className="h-3.5 w-3.5" />
            <span className="font-mono tabular-nums">{reports.length}</span>
            {rolled !== reports.length && (
              <span className="font-mono tabular-nums text-muted-foreground/70">
                / {rolled}
              </span>
            )}
          </span>
        )}
      </div>

      {hasReports && isOpen && (
        <ul className="ml-2.5 border-l border-border">
          {reports.map((child) => (
            <ReportingNodeRow
              key={child.public_id}
              node={child}
              depth={depth + 1}
              collapsed={collapsed}
              onToggle={onToggle}
            />
          ))}
        </ul>
      )}
    </li>
  );
}

export function ReportingTree({ nodes }: { nodes: ReportingNode[] }) {
  const { t } = useT();
  const [collapsed, setCollapsed] = useState<Set<string>>(new Set());

  const toggle = (id: string) =>
    setCollapsed((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });

  const allManagers = useMemo(() => managerIds(nodes), [nodes]);
  const allCollapsed =
    allManagers.length > 0 && collapsed.size >= allManagers.length;

  const stats = useMemo(
    () => ({ people: countPeople(nodes), depth: reportingDepth(nodes) }),
    [nodes],
  );

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap gap-2">
          <Stat
            icon={UserRound}
            label={t("org.reporting.stat_people", "People")}
            value={stats.people}
          />
          <Stat
            icon={Layers}
            label={t("org.reporting.stat_levels", "Levels deep")}
            value={stats.depth}
          />
        </div>

        {allManagers.length > 0 && (
          <Button
            size="sm"
            variant="outline"
            onClick={() =>
              setCollapsed(allCollapsed ? new Set() : new Set(allManagers))
            }
          >
            {allCollapsed
              ? t("org.structure.expand_all", "Expand all")
              : t("org.structure.collapse_all", "Collapse all")}
          </Button>
        )}
      </div>

      <Card>
        <CardContent className="overflow-x-auto p-2">
          <ul className="min-w-fit">
            {nodes.map((node) => (
              <ReportingNodeRow
                key={node.public_id}
                node={node}
                depth={0}
                collapsed={collapsed}
                onToggle={toggle}
              />
            ))}
          </ul>
        </CardContent>
      </Card>
    </div>
  );
}

function Stat({
  icon: Icon,
  label,
  value,
}: {
  icon: React.ComponentType<{ className?: string }>;
  label: string;
  value: number;
}) {
  return (
    <div className="flex items-center gap-2 rounded-lg border bg-muted/30 px-3 py-1.5">
      <Icon className="h-4 w-4 text-muted-foreground" />
      <span className="font-mono text-sm font-semibold tabular-nums text-foreground">
        {value}
      </span>
      <span className="text-xs text-muted-foreground">{label}</span>
    </div>
  );
}

// ── Data-fetching wrapper ──────────────────────────────────────────

export function ReportingChart() {
  const { t } = useT();
  const query = useReportingTree();

  return (
    <QueryBoundary
      query={query}
      empty={
        <EmptyState
          icon={UserRound}
          title={t("org.reporting.empty_title", "No reporting lines yet")}
          description={t(
            "org.reporting.empty_desc",
            "Assign a supervisor on employee records to build the reporting hierarchy.",
          )}
        />
      }
    >
      {(nodes) => <ReportingTree nodes={nodes} />}
    </QueryBoundary>
  );
}
