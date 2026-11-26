import { useCallback, useEffect, useRef, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { apiErrorDetail } from "@/lib/api/error-message";

/**
 * Joining days that were recorded as two records, one request at a time.
 *
 * The server joins for a few seconds and answers how many conflicts are still
 * to look at (`remaining`) and where it stopped (`next_after`), because the
 * host ends a request at about thirty seconds and a person can have dozens.
 * This asks again from that point until nothing is left, and keeps the totals.
 * It stops when asked, and when a call fails; either way `carryOn()` asks
 * again from where it stopped. A conflict that is not a split day, or that a
 * payroll run has paid, is left as it was and counted in `left`.
 */
export type JoinTarget =
  | { conflictIds: string[]; employeePublicId?: never }
  | { employeePublicId: string; conflictIds?: never };

export type JoinPhase = "idle" | "running" | "stopped" | "failed" | "done";

export interface JoinState {
  phase: JoinPhase;
  /** Summed over every call so far. */
  joined: number;
  left: number;
  /** Still to look at, as the last answer said. */
  remaining: number;
  error: string | null;
}

interface JoinAnswer {
  joined: number;
  left: { conflict_id: string; reason: string }[];
  remaining: number;
  next_after: string | null;
}

const IDLE: JoinState = {
  phase: "idle",
  joined: 0,
  left: 0,
  remaining: 0,
  error: null,
};

export function useJoinSplitDays(onSettled?: () => void) {
  const queryClient = useQueryClient();
  const [state, setState] = useState<JoinState>(IDLE);
  const target = useRef<JoinTarget | null>(null);
  const after = useRef<string | null>(null);
  const totals = useRef({ joined: 0, left: 0, remaining: 0 });
  const stopRequested = useRef(false);
  const running = useRef(false);
  const alive = useRef(true);
  const settled = useRef(onSettled);

  useEffect(() => {
    settled.current = onSettled;
  }, [onSettled]);

  useEffect(() => {
    alive.current = true;
    return () => {
      alive.current = false;
      stopRequested.current = true;
    };
  }, []);

  const publish = useCallback(
    (phase: JoinPhase, error: string | null = null) => {
      if (!alive.current) return;
      setState({ phase, ...totals.current, error });
    },
    [],
  );

  const finish = useCallback(
    (phase: JoinPhase, error: string | null = null) => {
      running.current = false;
      publish(phase, error);
      void queryClient.invalidateQueries({ queryKey: ["attendance"] });
      settled.current?.();
    },
    [publish, queryClient],
  );

  const run = useCallback(async () => {
    const current = target.current;
    if (running.current || current === null) return;
    running.current = true;
    stopRequested.current = false;
    publish("running");

    while (true) {
      try {
        const { data } = await apiClient.post<JoinAnswer>(
          "/attendance/conflicts/join",
          {
            ...(current.conflictIds
              ? { conflict_ids: current.conflictIds }
              : { employee_public_id: current.employeePublicId }),
            ...(after.current ? { after: after.current } : {}),
          },
        );
        totals.current.joined += data.joined;
        totals.current.left += data.left.length;
        totals.current.remaining = data.remaining;
        after.current = data.next_after;
      } catch (err) {
        finish("failed", apiErrorDetail(err) ?? null);
        return;
      }

      if (totals.current.remaining === 0 || after.current === null) break;

      if (stopRequested.current || !alive.current) {
        finish("stopped");
        return;
      }
      publish("running");
    }

    totals.current.remaining = 0;
    finish("done");
  }, [finish, publish]);

  const start = useCallback(
    (next: JoinTarget) => {
      if (running.current) return;
      target.current = next;
      after.current = null;
      totals.current = { joined: 0, left: 0, remaining: 0 };
      void run();
    },
    [run],
  );

  /** Ask again from where it stopped or failed. */
  const carryOn = useCallback(() => {
    if (running.current || target.current === null) return;
    void run();
  }, [run]);

  /** Finish the call in flight, then stop. */
  const stop = useCallback(() => {
    stopRequested.current = true;
  }, []);

  return { state, start, carryOn, stop };
}
