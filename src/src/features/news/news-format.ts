import { formatDate } from "@/lib/utils/date";
import { formatEthiopian, toEthiopian } from "@/lib/calendar/ethiopian";

const TIME_ZONE = "Africa/Addis_Ababa";

/**
 * A story's publication date as its reader reads dates: the Ethiopian
 * calendar in Amharic, the Gregorian in English. The day is the one in Addis
 * Ababa, where the organisation published it.
 */
export function newsDate(iso: string, locale: string): string {
  if (locale !== "am") return formatDate(iso, TIME_ZONE);

  const parts = new Intl.DateTimeFormat("en-CA", {
    timeZone: TIME_ZONE,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(new Date(iso));
  const [y, m, d] = parts.split("-").map(Number);

  return y && m && d ? formatEthiopian(toEthiopian(y, m, d), "am") : parts;
}

/**
 * A story's text as paragraphs and links, from plain text. The server sends
 * text only (markup is stripped before it leaves); this splits it on blank
 * lines and finds https links, so nothing in a story can become markup on
 * the page. A link keeps its trailing punctuation outside it.
 */
export type Segment =
  { kind: "text"; text: string } | { kind: "link"; href: string };

const URL_PATTERN = /https:\/\/[^\s<>"'`]+/g;
const TRAILING = /[.,;:!?)\]]+$/;

export function paragraphs(body: string): string[] {
  return body
    .replace(/\r\n?/g, "\n")
    .split(/\n{2,}/)
    .map((p) => p.trim())
    .filter(Boolean);
}

export function segments(paragraph: string): Segment[] {
  const out: Segment[] = [];
  let last = 0;
  for (const match of paragraph.matchAll(URL_PATTERN)) {
    const start = match.index ?? 0;
    let href = match[0];
    const trailing = href.match(TRAILING)?.[0] ?? "";
    href = href.slice(0, href.length - trailing.length);
    if (start > last)
      out.push({ kind: "text", text: paragraph.slice(last, start) });
    out.push({ kind: "link", href });
    last = start + href.length;
  }
  if (last < paragraph.length)
    out.push({ kind: "text", text: paragraph.slice(last) });
  return out;
}
