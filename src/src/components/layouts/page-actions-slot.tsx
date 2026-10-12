"use client";

import { createContext, useContext, useState, type ReactNode } from "react";

/**
 * Where a page's header actions render: the right-hand side of the title bar.
 *
 * Pages declare their actions with `<PageHeader actions={…} />` in their own
 * tree, but the title and breadcrumb are drawn by `PageTitleBar` in the shell,
 * above the page. Until 2026-10-12 the actions therefore sat in a row of their
 * own under the title bar — a band of empty space with a button at its right
 * end, on every screen. The title bar now registers an element here and
 * `PageHeader` portals into it; with no title bar (the dashboard, a route
 * without metadata, a unit test) it renders in place as before.
 */
const SlotContext = createContext<HTMLElement | null>(null);
const SetSlotContext = createContext<(el: HTMLElement | null) => void>(
  () => {},
);

export function PageActionsSlotProvider({ children }: { children: ReactNode }) {
  const [slot, setSlot] = useState<HTMLElement | null>(null);

  return (
    <SetSlotContext.Provider value={setSlot}>
      <SlotContext.Provider value={slot}>{children}</SlotContext.Provider>
    </SetSlotContext.Provider>
  );
}

/** The element `PageHeader` portals into, or null when there is none. */
export function usePageActionsSlot(): HTMLElement | null {
  return useContext(SlotContext);
}

/** A ref callback for the title bar's actions container. */
export function useRegisterPageActionsSlot(): (el: HTMLElement | null) => void {
  return useContext(SetSlotContext);
}
