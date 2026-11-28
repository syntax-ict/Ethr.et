import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useState } from "react";

vi.mock("next/dynamic", () => ({ default: () => () => null }));

import {
  MapPicker,
  type PlaceValue,
} from "@/components/shared/map-picker/map-picker";

function Harness({ onCommit }: { onCommit: (r: number) => void }) {
  const [value, setValue] = useState<PlaceValue>({
    latitude: null,
    longitude: null,
    radius: 100,
  });
  return (
    <MapPicker
      value={value}
      onChange={(v) => {
        onCommit(v.radius);
        setValue(v);
      }}
    />
  );
}

describe("MapPicker radius", () => {
  it("lets 50 be typed past the minimum and commits it on blur", async () => {
    const user = userEvent.setup();
    const commits: number[] = [];
    render(<Harness onCommit={(r) => commits.push(r)} />);
    const field = screen.getByLabelText(/area radius/i);

    await user.clear(field);
    await user.type(field, "50");
    expect(field).toHaveValue(50);
    expect(commits).toEqual([]);

    await user.tab();
    expect(commits).toEqual([50]);
    expect(field).toHaveValue(50);
  });

  it("commits the minimum when a smaller number is left in the field", async () => {
    const user = userEvent.setup();
    const commits: number[] = [];
    render(<Harness onCommit={(r) => commits.push(r)} />);
    const field = screen.getByLabelText(/area radius/i);

    await user.clear(field);
    await user.type(field, "5");
    await user.tab();

    expect(commits).toEqual([10]);
    expect(field).toHaveValue(10);
  });
});
