"use client";

import { useMemo, useState } from "react";
import {
  Building2,
  ChevronDown,
  ChevronRight,
  Users,
  Network,
  Layers,
} from "lucide-react";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { EmptyState } from "@/components/shared/empty-state";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { useT } from "@/lib/i18n/useT";
import { localizedName } from "@/lib/i18n/localizedName";
import {
  useOrganizationTree,
  type DepartmentTreeNode,
} from "@/features/organization/api";

// ── Pure helpers (exported for tests) ──────────────────────────────

/** Every node's own direct headcount, summed across the whole subtree. */
export function subtreeEmployees(node: DepartmentTreeNode): number {
  const own = node.employees_count ?? 0;
  const children = node.children_recursive ?? [];
  return children.reduce((sum, c) => sum + subtreeEmployees(c), own);
}

/** Total node count across a forest of trees. */
export function countDepartments(nodes: DepartmentTreeNode[]): number {
  return nodes.reduce(
    (sum, n) => sum + 1 + countDepartments(n.children_recursive ?? []),
    0,
  );
}

/** Deepest nesting level in a forest (1 = a flat list of roots). */
export function treeDepth(nodes: DepartmentTreeNode[]): number {
  if (nodes.length === 0) return 0;
  return (
    1 + Math.max(0, ...nodes.map((n) => treeDepth(n.children_recursive ?? [])))
  );
}

/** IDs of every node that has at least one child — the collapsible ones. */
export function collapsibleIds(nodes: DepartmentTreeNode[]): string[] {
  const ids: string[] = [];
  for (const n of nodes) {
    const children = n.children_recursive ?? [];
    if (children.length > 0) {
      ids.push(n.public_id);
      ids.push(...collapsibleIds(children));
    }
  }
  return ids;
}

// ── Presentational tree (data passed in, no fetching) ──────────────

function OrgNode({
  node,
  depth,
  collapsed,
  onToggle,
}: {
  node: DepartmentTreeNode;
  depth: number;
  collapsed: Set<string>;
  onToggle: (id: string) => void;
}) {
  const { t, locale } = useT();
  const children = node.children_recursive ?? [];
  const hasChildren = children.length > 0;
  const isOpen = !collapsed.has(node.public_id);
  const direct = node.employees_count ?? 0;
  const rolled = subtreeEmployees(node);

  return (
    <li>
      <div
        className="group flex items-center gap-2 rounded-md py-1.5 pr-2 hover:bg-muted/50"
        style={{ paddingLeft: `${depth * 20}px` }}
      >
        {hasChildren ? (
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

        <Building2 className="h-4 w-4 shrink-0 text-muted-foreground" />

        {/* Truncation here is intentional — the tree has to stay one row per
            node — but a department name clipped to "Human Resourc…" with no
            way to see the rest is just lost data. `title` makes the full value
            recoverable. Screen readers are unaffected either way: CSS
            truncation does not alter the accessible text, so this is purely
            for sighted users. */}
        <span
          className="truncate text-sm font-medium text-foreground"
          title={localizedName(node, locale)}
        >
          {localizedName(node, locale)}
        </span>

        {node.code && (
          <span className="shrink-0 font-mono text-xs text-muted-foreground">
            {node.code}
          </span>
        )}

        {node.branch?.name && (
          <Badge variant="outline" className="shrink-0 text-[10px] font-normal">
            {node.branch.name}
          </Badge>
        )}

        {!node.is_active && (
          <Badge variant="outline" className="shrink-0 text-[10px]">
            {t("org.inactive", "Inactive")}
          </Badge>
        )}

        <span
          className="ml-auto flex shrink-0 items-center gap-1 text-xs text-muted-foreground"
          title={
            hasChildren
              ? t(
                  "org.structure.headcount_hint",
                  ":direct in this department, :rolled including sub-departments",
                  { direct, rolled },
                )
              : t("org.structure.headcount_direct_hint", ":direct employees", {
                  direct,
                })
          }
        >
          <Users className="h-3.5 w-3.5" />
          <span className="font-mono tabular-nums">{direct}</span>
          {hasChildren && rolled !== direct && (
            <span className="font-mono tabular-nums text-muted-foreground/70">
              / {rolled}
            </span>
          )}
        </span>
      </div>

      {hasChildren && isOpen && (
        <ul className="ml-2.5 border-l border-border">
          {children.map((child) => (
            <OrgNode
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

export function OrgTree({ nodes }: { nodes: DepartmentTreeNode[] }) {
  const { t } = useT();
  const [collapsed, setCollapsed] = useState<Set<string>>(new Set());

  const toggle = (id: string) =>
    setCollapsed((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });

  const allCollapsible = useMemo(() => collapsibleIds(nodes), [nodes]);
  const allCollapsed =
    allCollapsible.length > 0 && collapsed.size >= allCollapsible.length;

  const stats = useMemo(
    () => ({
      departments: countDepartments(nodes),
      employees: nodes.reduce((sum, n) => sum + subtreeEmployees(n), 0),
      depth: treeDepth(nodes),
    }),
    [nodes],
  );

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap gap-2">
          <Stat
            icon={Network}
            label={t("org.structure.stat_departments", "Departments")}
            value={stats.departments}
          />
          <Stat
            icon={Users}
            label={t("org.structure.stat_employees", "Employees")}
            value={stats.employees}
          />
          <Stat
            icon={Layers}
            label={t("org.structure.stat_depth", "Levels deep")}
            value={stats.depth}
          />
        </div>

        {allCollapsible.length > 0 && (
          <Button
            size="sm"
            variant="outline"
            onClick={() =>
              setCollapsed(allCollapsed ? new Set() : new Set(allCollapsible))
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
              <OrgNode
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

// ── Data-fetching wrapper (used by the page) ───────────────────────

export function OrgChart() {
  const { t } = useT();
  const query = useOrganizationTree();

  return (
    <QueryBoundary
      query={query}
      empty={
        <EmptyState
          icon={Network}
          title={t("org.structure.empty_title", "No departments yet")}
          description={t(
            "org.structure.empty_desc",
            "Create departments in the Departments tab to see your organization chart.",
          )}
        />
      }
    >
      {(nodes) => <OrgTree nodes={nodes} />}
    </QueryBoundary>
  );
}
