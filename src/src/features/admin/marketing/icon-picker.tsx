"use client";

import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { MarketingIconGlyph } from "@/components/marketing/landing/marketing-icon-glyph";
import { useT } from "@/lib/i18n/useT";

const NONE = "__none__";

/**
 * One of the icons the API accepts, drawn as the page will draw it. The
 * list comes from the API (`icons` beside the sections), so a name added on
 * the server appears here without a second edit.
 */
export function IconPicker({
  id,
  value,
  icons,
  onChange,
}: {
  id: string;
  value: string | null;
  icons: string[];
  onChange: (icon: string | null) => void;
}) {
  const { t } = useT();

  return (
    <div className="space-y-1.5">
      <Label htmlFor={id}>{t("admin_marketing.field.icon", "Icon")}</Label>
      <Select
        value={value ?? NONE}
        onValueChange={(next) => onChange(next === NONE ? null : next)}
      >
        <SelectTrigger id={id} className="w-full">
          <span className="flex items-center gap-2">
            <MarketingIconGlyph name={value} className="h-4 w-4" />
            <SelectValue
              placeholder={t("admin_marketing.icon_none", "No icon")}
            />
          </span>
        </SelectTrigger>
        <SelectContent>
          <SelectItem value={NONE}>
            {t("admin_marketing.icon_none", "No icon")}
          </SelectItem>
          {icons.map((name) => (
            <SelectItem key={name} value={name}>
              <span className="flex items-center gap-2">
                <MarketingIconGlyph name={name} className="h-4 w-4" />
                {name}
              </span>
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </div>
  );
}
