import { useCallback, useEffect, useRef, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { apiErrorDetail } from "@/lib/api/error-message";

/**
 * Bringing a held badge's punches in, one request at a time.
 *
 * The server replays for a few seconds and answers how many are left
 * (`remaining`), because the host ends a request at about thirty seconds and a
 * badge can hold hundreds of punches. This asks again with the same inputs
 * until nothing is left, badge after badge, and keeps the totals. It stops when
 * asked, and when a call fails (the network, a 409 because another request has
 * the badge, a 5xx); either way `carryOn()` asks again from where it stopped,
 * and a punch the server already replayed is not replayed twice.
 */
export interface BringInJob {
  device_public_id: string;
  badge: string;
  employee_public_id: string;
  /** Punches held for it when the screen was drawn, for the count still to go. */
  punches?: number;
}

export type BringInPhase = "idle" | "running" | "stopped" | "failed" | "done";

export interface BringInState {
  phase: BringInPhase;
  /** Summed over every call so far. */
  replayed: number;
  refused: number;
  /** Still to go: the badge being worked on, and the punches held for the badges after it. */
  remaining: number;
  /** Which badge is being worked on, from 1. */
  position: number;
  total: number;
  /** The server's reason for the last failure, when it gave one. */
  error: string | null;
}

interface AssignAnswer {
  replayed: number;
  refused: number;
  remaining?: number;
}

const IDLE: BringInState = {
  phase: "idle",
  replayed: 0,
  refused: 0,
  remaining: 0,
  position: 0,
  total: 0,
  error: null,
};

export function useBringInPunches(onSettled?: () => void) {
  const queryClient = useQueryClient();
  const [state, setState] = useState<BringInState>(IDLE);
  const jobs = useRef<BringInJob[]>([]);
  const index = useRef(0);
  const totals = useRef({ replayed: 0, refused: 0 });
  const left = useRef<number | null>(null);
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

  const laterPunches = useCallback(
    () =>
      jobs.current
        .slice(index.current + 1)
        .reduce((sum, job) => sum + (job.punches ?? 0), 0),
    [],
  );

  const publish = useCallback(
    (phase: BringInPhase, error: string | null = null) => {
      if (!alive.current) return;
      const current = jobs.current[index.current];
      setState({
        phase,
        replayed: totals.current.replayed,
        refused: totals.current.refused,
        remaining:
          phase === "done"
            ? 0
            : (left.current ?? current?.punches ?? 0) + laterPunches(),
        position: Math.min(index.current + 1, jobs.current.length),
        total: jobs.current.length,
        error,
      });
    },
    [laterPunches],
  );

  const finish = useCallback(
    (phase: BringInPhase, error: string | null = null) => {
      running.current = false;
      publish(phase, error);
      void queryClient.invalidateQueries({
        queryKey: ["devices", "unmatched-punches"],
      });
      void queryClient.invalidateQueries({ queryKey: ["attendance"] });
      settled.current?.();
    },
    [publish, queryClient],
  );

  const run = useCallback(async () => {
    if (running.current) return;
    running.current = true;
    stopRequested.current = false;
    publish("running");

    while (index.current < jobs.current.length) {
      const job = jobs.current[index.current]!;

      try {
        const { data } = await apiClient.post<AssignAnswer>(
          "/devices/unmatched-punches/assign",
          {
            device_public_id: job.device_public_id,
            badge: job.badge,
            employee_public_id: job.employee_public_id,
          },
        );
        totals.current.replayed += data.replayed;
        totals.current.refused += data.refused;
        left.current = data.remaining ?? 0;
      } catch (err) {
        finish("failed", apiErrorDetail(err) ?? null);
        return;
      }

      if (left.current === 0) {
        index.current += 1;
        left.current = null;
      }

      if (index.current >= jobs.current.length) break;

      if (stopRequested.current || !alive.current) {
        finish("stopped");
        return;
      }
      publish("running");
    }

    finish("done");
  }, [finish, publish]);

  /** Bring in these badges' punches, the first call starting now. */
  const start = useCallback(
    (list: BringInJob[]) => {
      if (running.current || list.length === 0) return;
      jobs.current = list;
      index.current = 0;
      left.current = null;
      totals.current = { replayed: 0, refused: 0 };
      void run();
    },
    [run],
  );

  /** Ask again from where it stopped or failed. */
  const carryOn = useCallback(() => {
    if (running.current || jobs.current.length === 0) return;
    void run();
  }, [run]);

  /** Finish the call in flight, then stop. */
  const stop = useCallback(() => {
    stopRequested.current = true;
  }, []);

  return { state, start, carryOn, stop };
}
