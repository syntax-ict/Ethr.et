import { useT } from "@/lib/i18n/useT";

type Translate = (key: string, fallback?: string) => string;

/** How much an announcement matters (App\Enums\AnnouncementPriority). */
export const ANNOUNCEMENT_PRIORITIES = [
  "low",
  "normal",
  "high",
  "urgent",
] as const;
export type AnnouncementPriorityValue =
  (typeof ANNOUNCEMENT_PRIORITIES)[number];

/** What the public news page can be filtered by (App\Enums\AnnouncementCategory). */
export const ANNOUNCEMENT_CATEGORIES = [
  "news",
  "notice",
  "event",
  "deadline",
] as const;
export type AnnouncementCategoryValue =
  (typeof ANNOUNCEMENT_CATEGORIES)[number];

/** Who an announcement is addressed to (App\Enums\AnnouncementTarget). */
export const ANNOUNCEMENT_TARGETS = ["all", "department", "branch"] as const;
export type AnnouncementTargetValue = (typeof ANNOUNCEMENT_TARGETS)[number];

/** "High" is read as "Important" everywhere a story's priority is named. */
export function announcementPriorityLabel(t: Translate, value: string): string {
  switch (value) {
    case "low":
      return t("common.low", "Low");
    case "normal":
      return t("common.normal", "Normal");
    case "high":
      return t("landing.important", "Important");
    case "urgent":
      return t("common.urgent", "Urgent");
    default:
      return value;
  }
}

export function announcementCategoryLabel(t: Translate, value: string): string {
  switch (value) {
    case "news":
      return t("news.category_news", "News");
    case "notice":
      return t("news.category_notice", "Notice");
    case "event":
      return t("news.category_event", "Event");
    case "deadline":
      return t("news.category_deadline", "Deadline");
    default:
      return value;
  }
}

export function announcementTargetLabel(t: Translate, value: string): string {
  switch (value) {
    case "all":
      return t("announcements.target_all", "Whole organisation");
    case "department":
      return t("common.department", "Department");
    case "branch":
      return t("common.branch", "Branch");
    default:
      return value;
  }
}

export function useAnnouncementPriorityLabel() {
  const { t } = useT();
  return (value: string) => announcementPriorityLabel(t, value);
}

export function useAnnouncementCategoryLabel() {
  const { t } = useT();
  return (value: string) => announcementCategoryLabel(t, value);
}

export function useAnnouncementTargetLabel() {
  const { t } = useT();
  return (value: string) => announcementTargetLabel(t, value);
}
