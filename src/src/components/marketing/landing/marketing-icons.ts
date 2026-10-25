import {
  BarChart3,
  Bell,
  Briefcase,
  Building2,
  Calculator,
  CalendarDays,
  CheckCircle2,
  ClipboardList,
  Clock,
  Factory,
  FileClock,
  FileText,
  GraduationCap,
  Heart,
  Hospital,
  Hotel,
  Landmark,
  Languages,
  Lock,
  MapPin,
  QrCode,
  Receipt,
  ScrollText,
  Shield,
  SignalZero,
  Smartphone,
  Table,
  Users,
  Wallet,
  WifiOff,
  type LucideIcon,
} from "lucide-react";
import { type MarketingIcon } from "@/lib/marketing/icons";

/**
 * One lucide component per name the API accepts (`App\Support\MarketingContent::ICONS`,
 * mirrored in `lib/marketing/icons.ts`). A name outside the map draws nothing,
 * which `marketing-content-snapshot.test.ts` makes a failing test rather than
 * a blank space on the page.
 */
export const MARKETING_ICON_COMPONENTS: Record<MarketingIcon, LucideIcon> = {
  clock: Clock,
  wallet: Wallet,
  "calendar-days": CalendarDays,
  "wifi-off": WifiOff,
  "building-2": Building2,
  languages: Languages,
  users: Users,
  smartphone: Smartphone,
  "bar-chart-3": BarChart3,
  shield: Shield,
  landmark: Landmark,
  hospital: Hospital,
  factory: Factory,
  "graduation-cap": GraduationCap,
  hotel: Hotel,
  heart: Heart,
  briefcase: Briefcase,
  "file-text": FileText,
  "map-pin": MapPin,
  lock: Lock,
  "scroll-text": ScrollText,
  "check-circle-2": CheckCircle2,
  receipt: Receipt,
  "qr-code": QrCode,
  bell: Bell,
  "clipboard-list": ClipboardList,
  calculator: Calculator,
  table: Table,
  "signal-zero": SignalZero,
  "file-clock": FileClock,
};

export function marketingIcon(
  name: string | null | undefined,
): LucideIcon | null {
  if (!name) return null;
  return (
    (MARKETING_ICON_COMPONENTS as Record<string, LucideIcon>)[name] ?? null
  );
}
