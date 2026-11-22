import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import {
  SIGN_OUT_GUARD_READ_MS,
  useGuardedSignOut,
} from "@/features/auth/components/sign-out-guard";

const mocks = vi.hoisted(() => ({
  logout: vi.fn(),
  pending: vi.fn(),
  visits: vi.fn(),
  offline: { isOffline: false, isOnline: true },
  user: { employee_public_id: "01HEMP" } as { employee_public_id?: string },
}));

vi.mock("@/features/auth/api", () => ({
  useCurrentUser: () => ({ data: mocks.user }),
  useLogout: () => ({ mutate: mocks.logout }),
}));
vi.mock("@/lib/offline-queue", () => ({
  getPendingCount: (...a: unknown[]) => mocks.pending(...a),
}));
vi.mock("@/lib/attendance/visit-queue", () => ({
  queuedVisits: () => mocks.visits(),
}));
vi.mock("@/lib/hooks/useOfflineStatus", () => ({
  useOfflineStatus: () => mocks.offline,
}));

function Harness() {
  const { requestSignOut, signOutDialog } = useGuardedSignOut();
  return (
    <>
      <button onClick={requestSignOut}>Log Out</button>
      {signOutDialog}
    </>
  );
}

beforeEach(() => {
  mocks.logout.mockReset();
  mocks.pending.mockReset().mockResolvedValue(0);
  mocks.visits.mockReset().mockReturnValue([]);
  mocks.offline = { isOffline: false, isOnline: true };
  mocks.user = { employee_public_id: "01HEMP" };
});
afterEach(() => vi.useRealTimers());

describe("useGuardedSignOut", () => {
  it("signs out at once with nothing waiting and a connection", async () => {
    const user = userEvent.setup();
    render(<Harness />);
    await user.click(screen.getByRole("button", { name: "Log Out" }));
    expect(mocks.logout).toHaveBeenCalledTimes(1);
    expect(screen.queryByRole("dialog")).toBeNull();
    expect(mocks.pending).toHaveBeenCalledWith("01HEMP");
  });

  it("warns with the count of unsent punches, and Stay signed in keeps the session", async () => {
    mocks.pending.mockResolvedValue(3);
    const user = userEvent.setup();
    render(<Harness />);
    await user.click(screen.getByRole("button", { name: "Log Out" }));
    expect(await screen.findByRole("dialog")).toBeInTheDocument();
    expect(
      screen.getByText(/3 punches on this phone have not been sent yet/),
    ).toBeInTheDocument();
    expect(screen.queryByText(/You are offline/)).toBeNull();
    await user.click(screen.getByRole("button", { name: "Stay signed in" }));
    expect(mocks.logout).not.toHaveBeenCalled();
    expect(screen.queryByRole("dialog")).toBeNull();
  });

  it("uses the singular for one punch", async () => {
    mocks.pending.mockResolvedValue(1);
    const user = userEvent.setup();
    render(<Harness />);
    await user.click(screen.getByRole("button", { name: "Log Out" }));
    expect(
      await screen.findByText(/1 punch on this phone has not been sent yet/),
    ).toBeInTheDocument();
  });

  it("Sign out anyway signs out", async () => {
    mocks.pending.mockResolvedValue(2);
    const user = userEvent.setup();
    render(<Harness />);
    await user.click(screen.getByRole("button", { name: "Log Out" }));
    await user.click(
      await screen.findByRole("button", { name: "Sign out anyway" }),
    );
    expect(mocks.logout).toHaveBeenCalledTimes(1);
  });

  it("warns about unsent field visits", async () => {
    mocks.visits.mockReturnValue([{}, {}]);
    const user = userEvent.setup();
    render(<Harness />);
    await user.click(screen.getByRole("button", { name: "Log Out" }));
    expect(
      await screen.findByText(
        /2 field visits on this phone have not been sent/,
      ),
    ).toBeInTheDocument();
    expect(mocks.logout).not.toHaveBeenCalled();
  });

  it("warns when offline even with nothing waiting", async () => {
    mocks.offline = { isOffline: true, isOnline: false };
    const user = userEvent.setup();
    render(<Harness />);
    await user.click(screen.getByRole("button", { name: "Log Out" }));
    expect(
      await screen.findByText(/You are offline\. Until this phone/),
    ).toBeInTheDocument();
    expect(screen.queryByText(/have not been sent/)).toBeNull();
    expect(mocks.logout).not.toHaveBeenCalled();
  });

  it("does not read punches for a user with no employee record", async () => {
    mocks.user = {};
    const user = userEvent.setup();
    render(<Harness />);
    await user.click(screen.getByRole("button", { name: "Log Out" }));
    expect(mocks.pending).not.toHaveBeenCalled();
    expect(mocks.logout).toHaveBeenCalledTimes(1);
  });

  it("signs out when the read fails", async () => {
    mocks.pending.mockRejectedValue(new Error("no indexeddb"));
    const user = userEvent.setup();
    render(<Harness />);
    await user.click(screen.getByRole("button", { name: "Log Out" }));
    expect(mocks.logout).toHaveBeenCalledTimes(1);
  });

  it("signs out when the IndexedDB read never answers", async () => {
    vi.useFakeTimers();
    mocks.pending.mockReturnValue(new Promise(() => {}));
    render(<Harness />);
    await act(async () => {
      screen.getByRole("button", { name: "Log Out" }).click();
    });
    expect(mocks.logout).not.toHaveBeenCalled();
    await act(async () => {
      await vi.advanceTimersByTimeAsync(SIGN_OUT_GUARD_READ_MS);
    });
    expect(mocks.logout).toHaveBeenCalledTimes(1);
    expect(screen.queryByRole("dialog")).toBeNull();
  });
});
