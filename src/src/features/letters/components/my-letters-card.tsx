"use client";

import { useState } from "react";
import { Download, FileSignature, Loader2 } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  downloadMyLetter,
  useMyLetters,
  useRequestLetter,
  type LetterRequest,
} from "@/features/letters/api";
import { apiErrorDetail } from "@/lib/api/error-message";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

/**
 * Ask HR for an employment letter and download it once issued. Until
 * 2026-10-20 there was no way to ask (blueprint ESS7).
 */
export function MyLettersCard() {
  const { t } = useT();
  const { formatDate } = useDateFormatters();
  const letters = useMyLetters();
  const request = useRequestLetter();
  const [purpose, setPurpose] = useState("");
  const [addressedTo, setAddressedTo] = useState("");
  const [includeSalary, setIncludeSalary] = useState(false);
  const [language, setLanguage] = useState<"en" | "am">("en");

  function submit() {
    request.mutate(
      {
        purpose: purpose.trim(),
        addressed_to: addressedTo.trim() || undefined,
        include_salary: includeSalary,
        language,
      },
      {
        onSuccess: () => {
          toast.success(t("letters.requested"));
          setPurpose("");
          setAddressedTo("");
          setIncludeSalary(false);
        },
        onError: (err: unknown) =>
          toast.error(apiErrorDetail(err) || t("letters.request_failed")),
      },
    );
  }

  async function download(letter: LetterRequest) {
    try {
      await downloadMyLetter(letter);
    } catch (err) {
      toast.error(apiErrorDetail(err) || t("letters.download_failed"));
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <FileSignature className="h-4 w-4" />
          {t("letters.title")}
        </CardTitle>
        <p className="text-sm text-muted-foreground">
          {t("letters.description")}
        </p>
      </CardHeader>
      <CardContent className="space-y-5">
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="sm:col-span-2">
            <Label htmlFor="letter_purpose">{t("letters.purpose")}</Label>
            <Input
              id="letter_purpose"
              value={purpose}
              onChange={(e) => setPurpose(e.target.value)}
              placeholder={t("letters.purpose_placeholder")}
              className="mt-1"
            />
          </div>
          <div>
            <Label htmlFor="letter_to">{t("letters.addressed_to")}</Label>
            <Input
              id="letter_to"
              value={addressedTo}
              onChange={(e) => setAddressedTo(e.target.value)}
              placeholder={t("letters.addressed_to_placeholder")}
              className="mt-1"
            />
          </div>
          <div>
            <Label htmlFor="letter_language">{t("letters.language")}</Label>
            <Select
              value={language}
              onValueChange={(v) => setLanguage(v === "am" ? "am" : "en")}
            >
              <SelectTrigger id="letter_language" className="mt-1">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="en">English</SelectItem>
                <SelectItem value="am">አማርኛ</SelectItem>
              </SelectContent>
            </Select>
          </div>
          <label className="flex items-center gap-2 text-sm sm:col-span-2">
            <Checkbox
              checked={includeSalary}
              onCheckedChange={(v) => setIncludeSalary(v === true)}
            />
            {t("letters.include_salary")}
          </label>
        </div>
        <Button
          onClick={submit}
          disabled={purpose.trim().length < 3 || request.isPending}
        >
          {request.isPending && (
            <Loader2 className="mr-1 h-4 w-4 animate-spin" />
          )}
          {t("letters.request")}
        </Button>

        {(letters.data?.length ?? 0) > 0 && (
          <ul className="divide-y border-t">
            {letters.data!.map((letter) => (
              <li
                key={letter.public_id}
                className="flex flex-wrap items-center gap-3 py-3"
              >
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium">
                    {letter.purpose}
                  </p>
                  <p className="text-xs text-muted-foreground">
                    {letter.created_at ? formatDate(letter.created_at) : ""}
                    {letter.reference ? ` · ${letter.reference}` : ""}
                    {letter.status === "rejected" && letter.rejection_reason
                      ? ` · ${letter.rejection_reason}`
                      : ""}
                  </p>
                </div>
                <Badge
                  variant={
                    letter.status === "issued"
                      ? "default"
                      : letter.status === "rejected"
                        ? "destructive"
                        : "secondary"
                  }
                >
                  {t(`letters.status_${letter.status}`)}
                </Badge>
                {letter.status === "issued" && (
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => download(letter)}
                  >
                    <Download className="mr-1 h-4 w-4" />
                    {t("letters.download")}
                  </Button>
                )}
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}
