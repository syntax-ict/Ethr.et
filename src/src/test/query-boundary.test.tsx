import { describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import type { UseQueryResult } from "@tanstack/react-query";

function mockQuery(
  overrides: Partial<UseQueryResult<unknown>>,
): UseQueryResult<unknown> {
  return {
    data: undefined,
    error: null,
    isError: false,
    isLoading: false,
    isSuccess: false,
    isPending: false,
    isLoadingError: false,
    isRefetchError: false,
    isFetched: false,
    isFetchedAfterMount: false,
    isFetching: false,
    isInitialLoading: false,
    isPaused: false,
    isPlaceholderData: false,
    isRefetching: false,
    isStale: false,
    status: "pending",
    fetchStatus: "idle",
    dataUpdatedAt: 0,
    errorUpdatedAt: 0,
    errorUpdateCount: 0,
    failureCount: 0,
    failureReason: null,
    refetch: vi.fn(),
    promise: Promise.resolve({} as unknown),
    ...overrides,
  } as unknown as UseQueryResult<unknown>;
}

describe("QueryBoundary", () => {
  it("shows loading skeleton when isLoading", () => {
    render(
      <QueryBoundary query={mockQuery({ isLoading: true })}>
        {() => <div>Data</div>}
      </QueryBoundary>,
    );
    expect(screen.queryByText("Data")).not.toBeInTheDocument();
  });

  it("shows error state with retry button", () => {
    const refetch = vi.fn();
    render(
      <QueryBoundary
        query={mockQuery({ isError: true, error: new Error("fail"), refetch })}
      >
        {() => <div>Data</div>}
      </QueryBoundary>,
    );
    expect(screen.getByText("Something went wrong")).toBeInTheDocument();
    fireEvent.click(screen.getByText("Try Again"));
    expect(refetch).toHaveBeenCalled();
  });

  it("shows empty state for empty array data", () => {
    render(
      <QueryBoundary query={mockQuery({ data: { data: [] }, isSuccess: true })}>
        {() => <div>Data</div>}
      </QueryBoundary>,
    );
    expect(screen.getByText("No data found")).toBeInTheDocument();
  });

  it("renders children with data on success", () => {
    render(
      <QueryBoundary
        query={mockQuery({
          data: { data: [{ id: 1 }] },
          isSuccess: true,
        })}
      >
        {(data) => <div>Got {JSON.stringify(data)}</div>}
      </QueryBoundary>,
    );
    expect(screen.getByText(/Got/)).toBeInTheDocument();
  });

  it("supports custom empty state", () => {
    render(
      <QueryBoundary
        query={mockQuery({ data: [], isSuccess: true })}
        empty={<div>ምንም ውሂብ አልተገኘም</div>}
      >
        {() => <div>Data</div>}
      </QueryBoundary>,
    );
    expect(screen.getByText("ምንም ውሂብ አልተገኘም")).toBeInTheDocument();
  });

  it("supports custom isEmpty function", () => {
    render(
      <QueryBoundary
        query={mockQuery({ data: { items: [] }, isSuccess: true })}
        isEmpty={(d: unknown) =>
          Array.isArray((d as { items: unknown[] }).items) &&
          (d as { items: unknown[] }).items.length === 0
        }
      >
        {() => <div>Data</div>}
      </QueryBoundary>,
    );
    expect(screen.getByText("No data found")).toBeInTheDocument();
  });
});
