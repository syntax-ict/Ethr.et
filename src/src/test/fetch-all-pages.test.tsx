import { describe, it, expect } from "vitest";
import { renderHook, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { branchesApi } from "@/features/organization/api";

/**
 * List endpoints return 25 rows unless asked, at most 100 (CapPagination).
 * The Organization page and every branch/department/position picker took the
 * first page as the whole list, so a tenant's 26th branch could be neither
 * seen nor picked. Served here the way the API serves it: capped pages.
 */
function serveBranches(total: number) {
  const requested: Array<{ page: number; perPage: number }> = [];
  server.use(
    http.get("*/api/v1/organization/branches", ({ request }) => {
      const url = new URL(request.url);
      const page = Number(url.searchParams.get("page") ?? 1);
      const perPage = Math.min(
        Number(url.searchParams.get("per_page") ?? 25),
        100,
      );
      requested.push({ page, perPage });
      const lastPage = Math.max(1, Math.ceil(total / perPage));
      const from = (page - 1) * perPage;
      const rows = Array.from(
        { length: Math.max(0, Math.min(perPage, total - from)) },
        (_, i) => ({
          public_id: `BR${from + i + 1}`,
          name: `Branch ${from + i + 1}`,
          is_active: true,
        }),
      );
      return HttpResponse.json({
        data: rows,
        meta: {
          current_page: page,
          last_page: lastPage,
          per_page: perPage,
          total,
        },
        links: {},
      });
    }),
  );
  return requested;
}

function wrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  function Wrapper({ children }: { children: React.ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
  }
  return Wrapper;
}

describe("organization list hooks", () => {
  it("returns every branch, not the first page", async () => {
    const requested = serveBranches(130);

    const { result } = renderHook(() => branchesApi.useList(), {
      wrapper: wrapper(),
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.data).toHaveLength(130);
    expect(result.current.data!.data.at(-1)!.name).toBe("Branch 130");
    expect(requested).toEqual([
      { page: 1, perPage: 100 },
      { page: 2, perPage: 100 },
    ]);
  });

  it("makes one request when everything fits on a page", async () => {
    const requested = serveBranches(3);

    const { result } = renderHook(() => branchesApi.useList(), {
      wrapper: wrapper(),
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.data).toHaveLength(3);
    expect(requested).toHaveLength(1);
  });
});
