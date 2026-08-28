import { useLocalStorage } from "@/lib/hooks/useLocalStorage";

export interface SavedView<TState> {
  id: string;
  name: string;
  state: TState;
}

/**
 * Named, localStorage-persisted snapshots of arbitrary table state (search,
 * sort, column visibility, ...). Mirrors DataTable's own
 * `datatable:${tableId}:visibility` persistence pattern, so callers should
 * key this with something like `datatable:${tableId}:views`.
 */
export function useSavedViews<TState>(storageKey: string) {
  const [views, setViews] = useLocalStorage<SavedView<TState>[]>(
    storageKey,
    [],
  );

  function save(name: string, state: TState) {
    const id =
      typeof crypto.randomUUID === "function"
        ? crypto.randomUUID()
        : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
    setViews((prev) => [...prev, { id, name, state }]);
  }

  function remove(id: string) {
    setViews((prev) => prev.filter((v) => v.id !== id));
  }

  return { views, save, remove };
}
