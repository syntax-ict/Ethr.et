import { describe, it, expect, vi, beforeEach } from "vitest";
import { renderHook, waitFor, act } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { useCurrentPermissions, useCurrentUser } from "@/features/auth/api";
import { useUpdateUser, useUsers } from "@/features/users/api";
import { useUpdateCustomRole } from "@/features/roles/api";

/**
 * Cache contracts for the two modules that decide what the UI will let someone
 * do.
 *
 * `usePermissions()` — every `can.*` flag and every `<RoleGate>` — reads the
 * `permissions` array from `/auth/me`, and that query has a five-minute
 * `staleTime`. So a mutation that changes someone's permissions and does not
 * invalidate it leaves the interface authorizing against the old set for up to
 * five minutes.
 *
 * To be precise about the severity: the server still enforces. This is not a
 * privilege escalation — it is an interface that disagrees with the API it is
 * talking to, in both directions. A removed permission keeps being offered and
 * then fails; a granted one stays hidden, so the admin who just granted it
 * concludes it did not work.
 *
 * These tests assert the refetch, not the `invalidateQueries` call, because the
 * refetch is the part the user experiences. They also assert the cases that
 * must *not* refetch: an invalidation that is broader than the change is its own
 * defect, and without pinning that a fix can pass by invalidating everything.
 */

const ME_USER = {
  public_id: "01HZME0000000000000000001",
  name: "Abebe",
  email: "abebe@acme.et",
  role: "tenant_admin",
};

let meRequests = 0;
let usersRequests = 0;

function mockEndpoints() {
  meRequests = 0;
  usersRequests = 0;

  server.use(
    http.get("*/auth/me", () => {
      meRequests += 1;
      return HttpResponse.json({
        user: ME_USER,
        permissions: ["employee.create"],
        tenant: null,
      });
    }),
    http.get("*/users", () => {
      usersRequests += 1;
      return HttpResponse.json({ data: [], meta: { total: 0 } });
    }),
    http.patch("*/users/:id", ({ params }) =>
      HttpResponse.json({ public_id: params.id, role: "hr_admin" }),
    ),
    http.put("*/roles/:id", ({ params }) =>
      HttpResponse.json({ public_id: params.id, permissions: [] }),
    ),
  );
}

function wrapper() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return (
      <QueryClientProvider client={client}>{children}</QueryClientProvider>
    );
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  mockEndpoints();
});

describe("changing a user", () => {
  it("re-reads my own permissions when I change my own account", async () => {
    // A tenant admin editing their own row in Settings -> Users. Their role or
    // custom role changes, and every gate in the interface is derived from the
    // permissions that came with the old one.
    const { result } = renderHook(
      () => ({
        me: useCurrentUser(),
        perms: useCurrentPermissions(),
        update: useUpdateUser(),
      }),
      { wrapper: wrapper() },
    );

    await waitFor(() => expect(result.current.me.data).toBeDefined());
    expect(meRequests).toBe(1);

    await act(async () => {
      await result.current.update.mutateAsync({
        publicId: ME_USER.public_id,
        payload: { role: "hr_admin" },
      });
    });

    await waitFor(() => expect(meRequests).toBe(2));
  });

  it("does not re-read my permissions when I change somebody else", async () => {
    // The counterpart, and the reason the fix above cannot simply invalidate
    // everything: an admin working through a list of staff would otherwise
    // refetch their own identity on every single edit.
    const { result } = renderHook(
      () => ({
        me: useCurrentUser(),
        update: useUpdateUser(),
      }),
      { wrapper: wrapper() },
    );

    await waitFor(() => expect(result.current.me.data).toBeDefined());
    expect(meRequests).toBe(1);

    await act(async () => {
      await result.current.update.mutateAsync({
        publicId: "01HZSOMEONEELSE0000000001",
        payload: { role: "employee" },
      });
    });

    // Give any stray invalidation a chance to land before concluding.
    await new Promise((r) => setTimeout(r, 50));
    expect(meRequests).toBe(1);
  });

  it("refreshes the user list either way", async () => {
    const { result } = renderHook(
      () => ({
        users: useUsers(),
        update: useUpdateUser(),
      }),
      { wrapper: wrapper() },
    );

    await waitFor(() => expect(result.current.users.data).toBeDefined());
    expect(usersRequests).toBe(1);

    await act(async () => {
      await result.current.update.mutateAsync({
        publicId: "01HZSOMEONEELSE0000000001",
        payload: { status: "suspended" },
      });
    });

    await waitFor(() => expect(usersRequests).toBe(2));
  });
});

describe("changing a custom role's permissions", () => {
  it("re-reads my permissions, because I may hold the role just edited", async () => {
    // `/auth/me` returns the resolved permission set but not which custom role
    // produced it, so the client cannot tell whether this edit affects the
    // person making it. Editing a role is a rare administrative action and the
    // refetch is one small request, so it is taken unconditionally rather than
    // guessed at.
    const { result } = renderHook(
      () => ({
        me: useCurrentUser(),
        update: useUpdateCustomRole("01HZROLE00000000000000001"),
      }),
      { wrapper: wrapper() },
    );

    await waitFor(() => expect(result.current.me.data).toBeDefined());
    expect(meRequests).toBe(1);

    await act(async () => {
      await result.current.update.mutateAsync({
        permissions: ["employee.create", "payroll.process"],
      });
    });

    await waitFor(() => expect(meRequests).toBe(2));
  });

  it("does not re-read them when only the role's name changes", async () => {
    // Renaming a role cannot change anybody's abilities, so nothing about the
    // current session needs re-reading.
    const { result } = renderHook(
      () => ({
        me: useCurrentUser(),
        update: useUpdateCustomRole("01HZROLE00000000000000001"),
      }),
      { wrapper: wrapper() },
    );

    await waitFor(() => expect(result.current.me.data).toBeDefined());
    expect(meRequests).toBe(1);

    await act(async () => {
      await result.current.update.mutateAsync({ name: "Regional Manager" });
    });

    await new Promise((r) => setTimeout(r, 50));
    expect(meRequests).toBe(1);
  });
});
