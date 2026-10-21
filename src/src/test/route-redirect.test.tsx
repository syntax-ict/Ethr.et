import { afterEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";

const replace = vi.hoisted(() => vi.fn());
vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace, push: vi.fn(), back: vi.fn() }),
  usePathname: () => "/",
  useSearchParams: () => new URLSearchParams(),
}));

import AttendanceSettingsMoved from "@/app/(dashboard)/attendance/settings/page";
import SettingsShiftsMoved from "@/app/(dashboard)/settings/shifts/page";

afterEach(() => {
  replace.mockClear();
  window.history.replaceState(null, "", "/");
});

// The static export has no server redirects, so a moved page replaces itself
// with its new address (FRONTEND_REDESIGN_PLAN.md, Phase 4).
describe("moved settings pages", () => {
  it("sends Attendance Rules to the settings hub, keeping the query", () => {
    window.history.replaceState(null, "", "/attendance/settings?x=1");
    render(<AttendanceSettingsMoved />);

    expect(replace).toHaveBeenCalledWith("/settings/attendance?x=1");
    expect(
      screen.getByRole("link", { name: "Go to the new page" }),
    ).toHaveAttribute("href", "/settings/attendance");
  });

  it("sends the old Shift Rules screen to the one shift route", () => {
    window.history.replaceState(null, "", "/settings/shifts");
    render(<SettingsShiftsMoved />);

    expect(replace).toHaveBeenCalledWith("/shifts");
  });
});
