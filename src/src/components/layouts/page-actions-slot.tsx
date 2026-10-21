"use client";

import {
  createContext,
  useContext,
  useEffect,
  useState,
  type ReactNode,
} from "react";

/**
 * Where a page's header actions render: the right-hand side of the title bar.
 *
 * Pages declare their actions with `<PageActions actions={…} />` in their own
 * tree, but the title and breadcrumb are drawn by `PageTitleBar` in the shell,
 * above the page. Until 2026-10-12 the actions therefore sat in a row of their
 * own under the title bar — a band of empty space with a button at its right
 * end, on every screen. The title bar now registers an element here and
 * `PageActions` portals into it; with no title bar (the dashboard, a route
 * without metadata, a unit test) it renders in place as before.
 */
const SlotContext = createContext<HTMLElement | null>(null);
const SetSlotContext = createContext<(el: HTMLElement | null) => void>(
  () => {},
);

/**
 * A detail page names the record it shows, so the title bar can end its
 * breadcrumb with it ("People › Employees › Abel Bauch") and leave the
 * heading to the page's own header. Without it the bar repeated the list's
 * title and description above the person's profile.
 */
const EntityContext = createContext<string | null>(null);
const SetEntityContext = createContext<(name: string | null) => void>(() => {});

export function PageActionsSlotProvider({ children }: { children: ReactNode }) {
  const [slot, setSlot] = useState<HTMLElement | null>(null);
  const [entity, setEntity] = useState<string | null>(null);

  return (
    <SetSlotContext.Provider value={setSlot}>
      <SlotContext.Provider value={slot}>
        <SetEntityContext.Provider value={setEntity}>
          <EntityContext.Provider value={entity}>
            {children}
          </EntityContext.Provider>
        </SetEntityContext.Provider>
      </SlotContext.Provider>
    </SetSlotContext.Provider>
  );
}

/** Called by a detail page with the record's name once it has loaded. */
export function useEntityTitle(name: string | null | undefined): void {
  const setEntity = useContext(SetEntityContext);

  useEffect(() => {
    setEntity(name ?? null);
    return () => setEntity(null);
  }, [name, setEntity]);
}

/** The current detail page's record name, for the title bar. */
export function useCurrentEntityTitle(): string | null {
  return useContext(EntityContext);
}

/** The element `PageActions` portals into, or null when there is none. */
export function usePageActionsSlot(): HTMLElement | null {
  return useContext(SlotContext);
}

/** A ref callback for the title bar's actions container. */
export function useRegisterPageActionsSlot(): (el: HTMLElement | null) => void {
  return useContext(SetSlotContext);
}
