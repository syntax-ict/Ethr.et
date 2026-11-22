import type { ArticleContent } from "@/features/news/components/article-view";
import type { Announcement } from "./api";

/**
 * A saved announcement as the shared article view draws it.
 *
 * One conversion, for every place in the app that reads a story in full: the
 * reading pane and the publisher's preview. It lived in both for a day and the
 * two had already drifted over attachment addresses, which is the drift this
 * whole upgrade exists to remove.
 *
 * `url` is cleared on every attachment deliberately. In the app
 * `attachmentsPresented()` fills no address, because the public download route
 * names the organisation, so a signed-in reader fetches the file through the
 * API as a blob instead. `ArticleView` renders a plain link only where it has
 * an address, so clearing it here keeps that one path honest even if the API
 * ever starts sending one.
 */
export function articleOf(a: Announcement): ArticleContent {
  return {
    public_id: a.public_id,
    title: a.title,
    body: a.body,
    category: a.category ?? null,
    featured: a.featured,
    priority: a.priority,
    published_at: a.published_at ?? null,
    cover: a.cover,
    attachments: (a.attachments ?? []).map((f) => ({ ...f, url: null })),
  };
}
