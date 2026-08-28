import { describe, it, expect } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import {
  ReportingTree,
  countPeople,
  reportingDepth,
  subtreeReports,
  managerIds,
} from "@/features/organization/components/reporting-chart";
import type { ReportingNode } from "@/features/organization/api";

function person(
  partial: Partial<ReportingNode> & { public_id: string; name: string },
): ReportingNode {
  return { employee_code: partial.public_id, ...partial };
}

// Chief → Manager → IC;  Chief → Analyst
const tree: ReportingNode[] = [
  person({
    public_id: "chief",
    name: "Chief",
    position: "CEO",
    direct_reports: [
      person({
        public_id: "mgr",
        name: "Manager",
        direct_reports: [person({ public_id: "ic", name: "Contributor" })],
      }),
      person({ public_id: "analyst", name: "Analyst" }),
    ],
  }),
];

describe("reporting-chart helpers", () => {
  it("counts everyone in the tree", () => {
    expect(countPeople(tree)).toBe(4);
  });

  it("measures reporting depth", () => {
    expect(reportingDepth(tree)).toBe(3);
  });

  it("rolls up the subtree report count", () => {
    expect(subtreeReports(tree[0])).toBe(3); // Manager, Contributor, Analyst
  });

  it("lists only people who manage someone", () => {
    expect(managerIds(tree).sort()).toEqual(["chief", "mgr"]);
  });
});

describe("<ReportingTree>", () => {
  it("renders everyone and their title", () => {
    render(<ReportingTree nodes={tree} />);
    for (const name of ["Chief", "Manager", "Contributor", "Analyst"]) {
      expect(screen.getByText(name)).toBeInTheDocument();
    }
    expect(screen.getByText("CEO")).toBeInTheDocument();
  });

  it("collapses and expands the hierarchy", () => {
    render(<ReportingTree nodes={tree} />);
    expect(screen.getByText("Contributor")).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: "Collapse all" }));
    expect(screen.queryByText("Contributor")).not.toBeInTheDocument();
    expect(screen.queryByText("Manager")).not.toBeInTheDocument();
    expect(screen.getByText("Chief")).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: "Expand all" }));
    expect(screen.getByText("Contributor")).toBeInTheDocument();
  });
});
