import "@testing-library/jest-dom";
import { afterAll, afterEach, beforeAll, beforeEach } from "vitest";
import { configure } from "@testing-library/dom";
import { server } from "./msw/server";
import { registerLocale } from "@/lib/i18n/translations";
import enTranslations from "@/lib/i18n/locales/en.json";

registerLocale("en", enTranslations);

// `findBy*` and `waitFor` are bounded by testing-library's own
// `asyncUtilTimeout`, not by vitest's `testTimeout` — raising the latter (see
// vitest.config.ts) never applied to them, so every async query in this suite
// stayed on the 1000ms default. That is the same contention the config comment
// describes: under 70 parallel jsdom+MSW workers a TanStack Query round trip
// routinely needs more than a second, and the test that loses the race fails
// while passing every time when run alone (observed: migration-step).
//
// Kept below vitest's 20s ceiling on purpose, so a genuinely broken component
// still fails as a testing-library error with its DOM dump rather than as a
// bare vitest timeout. This is a ceiling, not an expected duration.
configure({ asyncUtilTimeout: 10000 });

// jsdom ships no ResizeObserver; Radix primitives (Switch, Select, …) measure
// their trigger on mount and throw without it.
if (!("ResizeObserver" in globalThis)) {
  globalThis.ResizeObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
  } as unknown as typeof ResizeObserver;
}

// Radix Select scrolls the active option into view, and jsdom implements neither
// scrollIntoView nor the pointer-capture methods its trigger calls.
if (!Element.prototype.scrollIntoView) {
  Element.prototype.scrollIntoView = function scrollIntoView() {};
}
if (!Element.prototype.hasPointerCapture) {
  Element.prototype.hasPointerCapture = () => false;
  Element.prototype.setPointerCapture = () => {};
  Element.prototype.releasePointerCapture = () => {};
}

// Object URLs back the photo-upload preview; jsdom has no blob URL support.
if (!URL.createObjectURL) {
  URL.createObjectURL = () => "blob:mock";
  URL.revokeObjectURL = () => {};
}

beforeAll(() => server.listen({ onUnhandledRequest: "error" }));
beforeEach(() => {
  localStorage.setItem("locale", "en");
});
afterEach(() => server.resetHandlers());
afterAll(() => server.close());
