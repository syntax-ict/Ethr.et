import { describe, it, expect } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import {
  OrgTree,
  subtreeEmployees,
  countDepartments,
  treeDepth,
  collapsibleIds,
} from "@/features/organization/components/org-chart";
import type { DepartmentTreeNode } from "@/features/organization/api";

function node(
  partial: Partial<DepartmentTreeNode> & { public_id: string; name: string },
): DepartmentTreeNode {
  return { is_active: true, employees_count: 0, ...partial };
}

// Engineering(2) → Backend(3), Frontend(1) → Web(4);  HR(5)
const tree: DepartmentTreeNode[] = [
  node({
    public_id: "eng",
    name: "Engineering",
    employees_count: 2,
    children_recursive: [
      node({ public_id: "be", name: "Backend", employees_count: 3 }),
      node({
        public_id: "fe",
        name: "Frontend",
        employees_count: 1,
        children_recursive: [
          node({ public_id: "web", name: "Web", employees_count: 4 }),
        ],
      }),
    ],
  }),
  node({ public_id: "hr", name: "HR", employees_count: 5, is_active: false }),
];

describe("org-chart helpers", () => {
  it("rolls up subtree headcount", () => {
    expect(subtreeEmployees(tree[0])).toBe(10); // 2 + 3 + (1 + 4)
    expect(subtreeEmployees(tree[1])).toBe(5);
  });

  it("counts every department in the forest", () => {
    expect(countDepartments(tree)).toBe(5);
  });

  it("measures the deepest nesting level", () => {
    expect(treeDepth(tree)).toBe(3);
  });

  it("lists only nodes that have children", () => {
    expect(collapsibleIds(tree).sort()).toEqual(["eng", "fe"]);
  });
});

describe("<OrgTree>", () => {
  it("renders every department name", () => {
    render(<OrgTree nodes={tree} />);
    for (const name of ["Engineering", "Backend", "Frontend", "Web", "HR"]) {
      expect(screen.getByText(name)).toBeInTheDocument();
    }
  });

  it("collapses and expands the whole tree", () => {
    render(<OrgTree nodes={tree} />);
    expect(screen.getByText("Backend")).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: "Collapse all" }));
    // Children hidden, roots remain.
    expect(screen.queryByText("Backend")).not.toBeInTheDocument();
    expect(screen.queryByText("Web")).not.toBeInTheDocument();
    expect(screen.getByText("Engineering")).toBeInTheDocument();
    expect(screen.getByText("HR")).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: "Expand all" }));
    expect(screen.getByText("Backend")).toBeInTheDocument();
    expect(screen.getByText("Web")).toBeInTheDocument();
  });

  it("marks inactive departments", () => {
    render(<OrgTree nodes={tree} />);
    expect(screen.getByText("Inactive")).toBeInTheDocument();
  });
});
