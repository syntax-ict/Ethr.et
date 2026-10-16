import type { Metadata } from "next";
import { staticExportIdParams } from "@/lib/static-export";
import { NewsArticle } from "@/features/news/components/news-article";

/**
 * One article. Exported once under the placeholder id and served for every
 * real one (see `@/lib/static-export`); kept out of search here for the
 * reason `../page.tsx` gives, and given the article's own head by the server
 * on the organisation's host.
 */
export const metadata: Metadata = {
  title: "News",
  description: "News and announcements.",
  robots: { index: false, follow: false },
};

export function generateStaticParams() {
  return staticExportIdParams();
}

export default async function Page({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  return <NewsArticle routeId={id} />;
}
