"use client";

import { useState, type FormEvent, type ReactNode } from "react";
import type { UseQueryResult } from "@tanstack/react-query";
import { Search } from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  PaginationControls,
  type PaginationMeta,
} from "@/components/shared/pagination-controls";
import { useQueryFilters } from "@/lib/hooks/useQueryFilters";
import {
  NEWS_CATEGORIES,
  type NewsCategory,
  type NewsQuery,
} from "@/features/auth/landing-feed";
import { NewsBoundary } from "./news-state";

/**
 * The search, category and page of a list of stories, kept in the address by
 * `useQueryFilters`, with `extra` keys of the page's own beside them (the
 * signed-in list keeps the open story's `read` there). One hook owns the
 * address, so a filtered, paged list with a story open is one shareable
 * link, and the other parameters in it (`org`) are left as they are.
 *
 * `push` adds a history entry per change, so the back button walks back
 * through the filters, as the public page has always done; without it a
 * change replaces the entry.
 */
export function useStoryFilters<
  E extends Record<string, string> = Record<never, string>,
>({ extra, push = false }: { extra?: E; push?: boolean } = {}) {
  const { values, set, clear } = useQueryFilters({
    q: "",
    category: "",
    page: "1",
    ...extra,
  } as { q: string; category: string; page: string } & E);

  const page = Number(values.page);
  const query: NewsQuery = {
    q: values.q.slice(0, 100),
    category: NEWS_CATEGORIES.includes(values.category as NewsCategory)
      ? (values.category as NewsCategory)
      : null,
    page: Number.isInteger(page) && page > 0 ? page : 1,
  };

  return {
    query,
    /** Every key, the extra ones included, as the address holds it. */
    values,
    /** Writes keys as text; `options.push` overrides the hook's `push`. */
    set: (patch: Parameters<typeof set>[0], options?: { push?: boolean }) =>
      set(patch, { push: options?.push ?? push }),
    clear,
    /** Changes the list's own filters, scrolling to the top on a new page. */
    change(patch: Partial<NewsQuery>) {
      const next = { ...query, ...patch };
      set(
        {
          q: next.q.trim(),
          category: next.category ?? "",
          page: String(next.page),
        } as Parameters<typeof set>[0],
        { push },
      );
      if (next.page !== query.page) window.scrollTo({ top: 0 });
    },
  };
}

export type StoryFilters = Pick<
  ReturnType<typeof useStoryFilters>,
  "query" | "change"
>;

export interface StoryPage<T> {
  data: T[];
  meta?: PaginationMeta;
  /** The categories the stories were published under, when the source says. */
  categories?: readonly unknown[];
}

/**
 * The browsing body of a list of stories: an optional search box and
 * category filter, the stories as a list of cards, and the shared pager.
 * The public news page and the signed-in announcements list are both this;
 * what differs is the data source and what each wants switched on.
 *
 * The data source is `query` (a paged query run with `filters.query`), and
 * `search` and `category` say what it can answer: the public endpoint takes
 * both, the signed-in one neither yet, so those controls are off for it.
 * Cards come from `renderItem`; the browser puts each in a list item.
 *
 * `lead` makes a featured first story on an unfiltered first page span the
 * grid (the public page). `header` shares the row with the search box.
 */
export function StoryBrowser<
  T extends { public_id: string; featured?: boolean },
>({
  filters,
  query,
  search = false,
  category = false,
  lead = false,
  layout = "grid",
  header,
  renderItem,
  loading,
  empty,
  error,
}: {
  filters: StoryFilters;
  query: UseQueryResult<StoryPage<T>>;
  search?: boolean;
  category?: boolean;
  lead?: boolean;
  layout?: "grid" | "list";
  header?: ReactNode;
  renderItem: (item: T, state: { lead: boolean }) => ReactNode;
  loading: ReactNode;
  /** What an empty list says; `filtered` is true when a filter caused it. */
  empty: (state: { filtered: boolean; clear: () => void }) => ReactNode;
  error?: ReactNode;
}) {
  const { t } = useT();
  const current = filters.query;
  const [draft, setDraft] = useState(current.q);
  const [seen, setSeen] = useState(current.q);
  // The address is the source of the search: stepping back or forward
  // through it puts its words back in the box.
  if (seen !== current.q) {
    setSeen(current.q);
    setDraft(current.q);
  }

  const filtered =
    (search && current.q.trim() !== "") ||
    (category && current.category !== null);
  const categories = category
    ? (query.data?.categories ?? []).filter((c): c is NewsCategory =>
        NEWS_CATEGORIES.includes(c as NewsCategory),
      )
    : [];

  function submitSearch(event: FormEvent) {
    event.preventDefault();
    filters.change({ q: draft, page: 1 });
  }

  return (
    <>
      {(header || search) && (
        <div className="mt-4 flex flex-col gap-6 @lg:flex-row @lg:items-end @lg:justify-between">
          {header}
          {search && (
            <form
              role="search"
              onSubmit={submitSearch}
              className="flex w-full gap-2 @lg:w-80"
            >
              <label htmlFor="news-search" className="sr-only">
                {t("news.search_label", "Search news")}
              </label>
              <div className="relative min-w-0 flex-1">
                <Search
                  className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                  aria-hidden="true"
                />
                <Input
                  id="news-search"
                  type="search"
                  value={draft}
                  maxLength={100}
                  onChange={(e) => setDraft(e.target.value)}
                  placeholder={t("news.search_placeholder", "Search news")}
                  className="pl-9"
                />
              </div>
              <Button type="submit" variant="outline">
                {t("news.search", "Search")}
              </Button>
            </form>
          )}
        </div>
      )}

      {categories.length > 0 && (
        <div
          role="group"
          aria-label={t("news.filter_label", "Filter by category")}
          className="mt-6 flex flex-wrap gap-2"
        >
          {[null, ...categories].map((value) => {
            const active = current.category === value;
            return (
              <button
                key={value ?? "all"}
                type="button"
                aria-pressed={active}
                onClick={() => filters.change({ category: value, page: 1 })}
                className={cn(
                  "rounded-full border px-3.5 py-1.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                  active
                    ? "border-primary bg-primary text-primary-foreground"
                    : "border-border bg-card text-foreground hover:bg-muted",
                )}
              >
                {value === null
                  ? t("news.all", "All")
                  : t(`news.category_${value}`, value)}
              </button>
            );
          })}
        </div>
      )}

      <div
        className={cn((header || search) && "mt-8")}
        // Said aloud when a search or filter changes the results. Not on a
        // list with neither: a live region is exempt from the `aria-hidden`
        // a modal puts on the page behind it, which would leave this list
        // reachable under the reading panel.
        aria-live={search || category ? "polite" : undefined}
        aria-busy={query.isFetching}
      >
        <NewsBoundary
          query={query}
          loading={loading}
          error={error}
          empty={empty({
            filtered,
            clear: () => filters.change({ q: "", category: null, page: 1 }),
          })}
          isEmpty={(page) => page.data.length === 0}
        >
          {(page) => {
            const first = page.data[0];
            const top =
              lead && !filtered && current.page === 1 && first?.featured
                ? first
                : null;
            const total = page.meta?.total;

            return (
              <>
                {filtered && total !== undefined && (
                  <p className="mb-4 text-sm text-muted-foreground">
                    {total === 1
                      ? t("news.result_one", "1 story")
                      : t("news.results", ":count stories", { count: total })}
                  </p>
                )}
                <ul
                  className={
                    layout === "grid"
                      ? "grid gap-4 @sm:grid-cols-2 @lg:grid-cols-3"
                      : "space-y-3"
                  }
                >
                  {page.data.map((item) => (
                    <li
                      key={item.public_id}
                      className={cn("min-w-0", item === top && "col-span-full")}
                    >
                      {renderItem(item, { lead: item === top })}
                    </li>
                  ))}
                </ul>
                {page.meta && page.meta.last_page > 1 && (
                  <div className="mt-6">
                    <PaginationControls
                      meta={page.meta}
                      onPageChange={(next) => filters.change({ page: next })}
                      disabled={query.isFetching}
                    />
                  </div>
                )}
              </>
            );
          }}
        </NewsBoundary>
      </div>
    </>
  );
}
