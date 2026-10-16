import type { Metadata } from "next";
import { NewsListing } from "@/features/news/components/news-listing";

/**
 * An organisation's news page. Exported once for everyone and kept out of
 * search here: on the shared host no organisation is known when the file is
 * served. On an organisation's own host the server serves this file with
 * that organisation's head, its listing choice replacing this line
 * (NewsPageController, .htaccess group 1a).
 */
export const metadata: Metadata = {
  title: "News",
  description: "News and announcements.",
  robots: { index: false, follow: false },
};

export default function Page() {
  return <NewsListing />;
}
