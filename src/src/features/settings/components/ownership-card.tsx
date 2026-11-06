"use client";

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { apiClient } from "@/api/client";
import { apiErrorMessage } from "@/lib/api/error-message";
import { useT } from "@/lib/i18n/useT";

interface Ownership {
  owner_public_id: string | null;
  owner_name: string | null;
  is_owner: boolean;
  /** The other active administrators, sent to the owner only. */
  candidates: Array<{ public_id: string; name: string }>;
}

const OWNERSHIP_KEY = ["settings", "ownership"] as const;

/**
 * Who owns the organisation (`GET /settings/ownership`): the person ETHR's
 * support calls, and the only one who may hand ownership on. The owner can
 * pass it to another active administrator after typing their password.
 */
export function OwnershipCard() {
  const { t } = useT();
  const qc = useQueryClient();
  const { data } = useQuery({
    queryKey: OWNERSHIP_KEY,
    queryFn: async (): Promise<Ownership> =>
      (await apiClient.get("/settings/ownership")).data,
  });
  const [to, setTo] = useState("");
  const [password, setPassword] = useState("");
  const transfer = useMutation({
    mutationFn: async () =>
      (
        await apiClient.post("/settings/ownership/transfer", {
          user_public_id: to,
          password,
        })
      ).data,
    onSuccess: () => {
      toast.success(t("settings_ownership.transferred", "Ownership handed on"));
      setTo("");
      setPassword("");
      qc.invalidateQueries({ queryKey: OWNERSHIP_KEY });
    },
    onError: (err) =>
      toast.error(
        apiErrorMessage(
          err,
          t("settings_ownership.failed", "Ownership could not be handed on"),
        ),
      ),
  });

  if (!data?.owner_name) return null;

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">
          {t("settings_ownership.title", "Owner of the organisation")}
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-3 text-sm">
        <p>
          <span className="font-medium text-foreground">{data.owner_name}</span>{" "}
          <span className="text-muted-foreground">
            {t(
              "settings_ownership.explainer",
              "ETHR support calls the owner, and only the owner can hand ownership to another administrator.",
            )}
          </span>
        </p>
        {data.is_owner && data.candidates.length > 0 && (
          <div className="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
            <div className="space-y-1">
              <Label htmlFor="ownership-to">
                {t("settings_ownership.new_owner", "Hand ownership to")}
              </Label>
              <Select value={to} onValueChange={setTo}>
                <SelectTrigger id="ownership-to">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {data.candidates.map((c) => (
                    <SelectItem key={c.public_id} value={c.public_id}>
                      {c.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1">
              <Label htmlFor="ownership-password">
                {t("settings_ownership.password", "Your password")}
              </Label>
              <Input
                id="ownership-password"
                type="password"
                autoComplete="current-password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
              />
            </div>
            <Button
              variant="outline"
              onClick={() => transfer.mutate()}
              disabled={transfer.isPending || to === "" || password === ""}
            >
              {t("settings_ownership.transfer", "Hand on ownership")}
            </Button>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
