import { defineConfig } from "vitest/config";
import path from "path";

export default defineConfig({
  test: {
    environment: "jsdom",
    environmentOptions: {
      jsdom: {
        url: "http://localhost:3000",
      },
    },
    globals: true,
    setupFiles: ["./src/test/setup.ts"],
    exclude: ["**/node_modules/**", "**/e2e/**"],
    include: ["src/**/*.test.{ts,tsx}"],
    // Vitest's 5s default is not survivable for this suite. 48 files run in
    // parallel workers, each mounting a jsdom environment and an MSW server;
    // under that contention `waitFor` on a TanStack Query hook routinely
    // exceeded 5s and reported `isSuccess`/`isError` as false. Those same
    // tests pass every time when the file is run alone, so the failures were
    // pure scheduling noise — which made the gate untrustworthy rather than
    // useful. These limits are a timeout ceiling, not an expected duration.
    testTimeout: 20000,
    hookTimeout: 20000,
  },
  resolve: {
    alias: {
      "@": path.resolve(__dirname, "./src"),
    },
  },
});
