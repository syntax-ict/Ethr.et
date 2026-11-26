/**
 * A first-in, first-out cap on how many calls run at once. A slot is released
 * in `finally`, so a call that rejects frees its slot like one that resolves
 * and the queue can never stall behind a failure.
 */
export function createLimiter(max: number) {
  let active = 0;
  const waiting: Array<() => void> = [];

  function release() {
    const next = waiting.shift();
    if (next) {
      // Hand the slot straight on: `active` stays as it is.
      next();
    } else {
      active -= 1;
    }
  }

  async function run<T>(fn: () => Promise<T>): Promise<T> {
    if (active < max) {
      active += 1;
    } else {
      await new Promise<void>((resolve) => waiting.push(resolve));
    }
    try {
      return await fn();
    } finally {
      release();
    }
  }

  return { run };
}
