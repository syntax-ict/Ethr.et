import { afterEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useState } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import { ClockTimeInput } from "@/components/shared/clock-time-input";

/**
 * The time input that replaces `<Input type="time">` on the attendance and
 * shift forms. Whatever the reader sees, the value it hands back is a 24-hour
 * `HH:MM`, because that is what the API takes.
 */
function Harness({ onChange }: { onChange: (value: string) => void }) {
  const [value, setValue] = useState("");
  return (
    <ClockTimeInput
      id="when"
      aria-label="When"
      value={value}
      onChange={(next) => {
        setValue(next);
        onChange(next);
      }}
    />
  );
}

function renderInput(onChange: (value: string) => void) {
  // The formatters read the tenant's zone from the signed-in session.
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "U1", name: "Almaz", role: "employee" },
        tenant: { public_id: "T1", timezone: "Africa/Addis_Ababa" },
        permissions: [],
      }),
    ),
  );
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <CalendarProvider>
        <Harness onChange={onChange} />
      </CalendarProvider>
    </QueryClientProvider>,
  );
}

afterEach(() => {
  localStorage.removeItem("ethr.calendar");
  localStorage.removeItem("ethr.clock");
});

describe("<ClockTimeInput> on the Ethiopian clock", () => {
  it("turns 1:30 ጠዋት into 07:30, and 1:30 ምሽት into 19:30", async () => {
    localStorage.setItem("locale", "am");
    localStorage.setItem("ethr.calendar", "ethiopian");
    const onChange = vi.fn();
    const user = userEvent.setup();
    const { container } = renderInput(onChange);

    expect(container.querySelector('input[type="time"]')).toBeNull();
    const [hour, period] = screen.getAllByRole("combobox");

    await user.click(period);
    await user.click(await screen.findByRole("option", { name: "ጠዋት" }));
    await user.click(hour);
    await user.click(await screen.findByRole("option", { name: "1" }));
    expect(onChange).toHaveBeenLastCalledWith("07:00");

    // The minute box shows 00 once an hour is chosen; it is cleared first,
    // as a person would, because it holds two digits at most.
    const minute = screen.getByRole("textbox", { name: /When/ });
    await user.clear(minute);
    await user.type(minute, "30");
    expect(onChange).toHaveBeenLastCalledWith("07:30");
    // The 24-hour time it stands for is written underneath.
    expect(screen.getByText(/07:30/)).toBeInTheDocument();

    await user.click(screen.getAllByRole("combobox")[1]);
    await user.click(await screen.findByRole("option", { name: "ምሽት" }));
    expect(onChange).toHaveBeenLastCalledWith("19:30");
  });
});

describe("<ClockTimeInput> on the 24-hour clock", () => {
  it("is the native time input", async () => {
    localStorage.setItem("locale", "en");
    localStorage.setItem("ethr.calendar", "gregorian");
    const onChange = vi.fn();
    const { container } = renderInput(onChange);

    const input = container.querySelector('input[type="time"]');
    expect(input).not.toBeNull();
    expect(input).toHaveAttribute("id", "when");
    expect(screen.queryByRole("combobox")).toBeNull();
  });
});
