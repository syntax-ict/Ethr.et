import { describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { SavedViewsMenu } from "@/components/shared/saved-views-menu";
import type { SavedView } from "@/lib/hooks/useSavedViews";

interface FixtureState {
  search: string;
}

const views: SavedView<FixtureState>[] = [
  { id: "v1", name: "Engineering", state: { search: "engineering" } },
  { id: "v2", name: "On probation", state: { search: "probation" } },
];

describe("<SavedViewsMenu>", () => {
  it("shows an empty state when there are no saved views", async () => {
    render(
      <SavedViewsMenu<FixtureState>
        views={[]}
        onApply={vi.fn()}
        onSave={vi.fn()}
        onDelete={vi.fn()}
      />,
    );
    await userEvent.click(screen.getByRole("button", { name: /views/i }));

    expect(screen.getByText("No saved views yet")).toBeInTheDocument();
    expect(screen.getByText("Save current view...")).toBeInTheDocument();
  });

  it("lists saved views and applies the clicked one", async () => {
    const onApply = vi.fn();
    render(
      <SavedViewsMenu<FixtureState>
        views={views}
        onApply={onApply}
        onSave={vi.fn()}
        onDelete={vi.fn()}
      />,
    );
    await userEvent.click(screen.getByRole("button", { name: /views/i }));
    await userEvent.click(screen.getByText("Engineering"));

    expect(onApply).toHaveBeenCalledWith({ search: "engineering" });
  });

  it("deletes a view without applying it", async () => {
    const onApply = vi.fn();
    const onDelete = vi.fn();
    render(
      <SavedViewsMenu<FixtureState>
        views={views}
        onApply={onApply}
        onSave={vi.fn()}
        onDelete={onDelete}
      />,
    );
    await userEvent.click(screen.getByRole("button", { name: /views/i }));
    // Radix closes the menu on select before React can process a bubbled
    // click, so the delete button's own stopPropagation needs a raw click
    // rather than userEvent's full pointer-down/up sequence. Two rows are
    // rendered (Engineering, On probation) — target Engineering's (v1).
    fireEvent.click(screen.getAllByRole("button", { name: /delete view/i })[0]);

    expect(onDelete).toHaveBeenCalledWith("v1");
    expect(onApply).not.toHaveBeenCalled();
  });

  it("saves the current state under a typed name", async () => {
    const onSave = vi.fn();
    render(
      <SavedViewsMenu<FixtureState>
        views={[]}
        onApply={vi.fn()}
        onSave={onSave}
        onDelete={vi.fn()}
      />,
    );
    await userEvent.click(screen.getByRole("button", { name: /views/i }));
    await userEvent.click(screen.getByText("Save current view..."));

    const saveButton = await screen.findByRole("button", { name: "Save" });
    expect(saveButton).toBeDisabled();

    await userEvent.type(screen.getByLabelText("View name"), "New leads");
    expect(saveButton).not.toBeDisabled();

    await userEvent.click(saveButton);

    expect(onSave).toHaveBeenCalledWith("New leads");
  });
});
