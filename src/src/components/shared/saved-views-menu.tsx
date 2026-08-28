"use client";

import { useState } from "react";
import { Bookmark, Plus, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { useT } from "@/lib/i18n/useT";
import type { SavedView } from "@/lib/hooks/useSavedViews";

interface SavedViewsMenuProps<TState> {
  views: SavedView<TState>[];
  onApply: (state: TState) => void;
  onSave: (name: string) => void;
  onDelete: (id: string) => void;
}

export function SavedViewsMenu<TState>({
  views,
  onApply,
  onSave,
  onDelete,
}: SavedViewsMenuProps<TState>) {
  const { t } = useT();
  const [saveDialogOpen, setSaveDialogOpen] = useState(false);
  const [name, setName] = useState("");

  function confirmSave() {
    const trimmed = name.trim();
    if (!trimmed) return;
    onSave(trimmed);
    setName("");
    setSaveDialogOpen(false);
  }

  return (
    <>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="outline" size="sm">
            <Bookmark className="mr-2 h-3.5 w-3.5" />
            {t("table.views", "Views")}
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" className="w-56">
          {views.length === 0 ? (
            <div className="px-2 py-1.5 text-sm text-muted-foreground">
              {t("table.no_saved_views", "No saved views yet")}
            </div>
          ) : (
            views.map((view) => (
              <DropdownMenuItem
                key={view.id}
                onSelect={() => onApply(view.state)}
                className="justify-between"
              >
                <span className="truncate">{view.name}</span>
                <button
                  type="button"
                  className="rounded p-0.5 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                  aria-label={t("table.delete_view", "Delete view")}
                  onClick={(e) => {
                    e.stopPropagation();
                    onDelete(view.id);
                  }}
                >
                  <X className="h-3.5 w-3.5" />
                </button>
              </DropdownMenuItem>
            ))
          )}
          <DropdownMenuSeparator />
          <DropdownMenuItem onSelect={() => setSaveDialogOpen(true)}>
            <Plus className="mr-2 h-3.5 w-3.5" />
            {t("table.save_current_view", "Save current view...")}
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>

      <Dialog open={saveDialogOpen} onOpenChange={setSaveDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {t("table.save_current_view", "Save current view...")}
            </DialogTitle>
          </DialogHeader>

          <div>
            <Label htmlFor="saved-view-name">
              {t("table.view_name", "View name")}
            </Label>
            <Input
              id="saved-view-name"
              value={name}
              onChange={(e) => setName(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === "Enter") confirmSave();
              }}
              placeholder={t(
                "table.view_name_placeholder",
                "e.g. Engineering, on probation",
              )}
              className="mt-1"
              autoFocus
            />
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setSaveDialogOpen(false)}>
              {t("common.cancel", "Cancel")}
            </Button>
            <Button onClick={confirmSave} disabled={!name.trim()}>
              {t("common.save", "Save")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
