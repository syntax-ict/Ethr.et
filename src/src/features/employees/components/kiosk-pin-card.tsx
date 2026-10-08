"use client";

import { useState } from "react";
import { KeyRound, Loader2 } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { useSetKioskPin } from "@/features/employees/api";
import type { Employee } from "@/features/employees/types";
import { apiErrorMessage } from "@/lib/api/error-message";
import { useT } from "@/lib/i18n/useT";

/**
 * The PIN this employee enters at a kiosk when the organisation requires one.
 *
 * Nothing could set it before this existed, so turning on "Require PIN" in
 * attendance settings locked every employee out of every kiosk (audit N62).
 * The PIN is never shown: the API says only whether one is set.
 */
export function KioskPinCard({ employee }: { employee: Employee }) {
  const { t } = useT();
  const setPin = useSetKioskPin(employee.public_id);
  const [open, setOpen] = useState(false);
  const [pin, setPinValue] = useState("");
  const [error, setError] = useState<string | null>(null);

  function save(value: string | null) {
    setError(null);
    setPin.mutate(value, {
      onSuccess: () => {
        toast.success(
          value === null
            ? t("kiosk_pin.removed", "Kiosk PIN removed")
            : t("kiosk_pin.saved", "Kiosk PIN saved"),
        );
        setOpen(false);
        setPinValue("");
      },
      onError: (err) =>
        setError(
          apiErrorMessage(err, t("kiosk_pin.failed", "Couldn't save the PIN")),
        ),
    });
  }

  return (
    <Card>
      <CardContent className="flex flex-wrap items-center justify-between gap-3 p-4">
        <div className="flex items-center gap-3">
          <KeyRound className="h-4 w-4 text-muted-foreground" />
          <div>
            <p className="text-sm font-medium text-foreground">
              {t("kiosk_pin.title", "Kiosk PIN")}
            </p>
            <p className="text-xs text-muted-foreground">
              {employee.has_kiosk_pin
                ? t("kiosk_pin.is_set", "Set")
                : t("kiosk_pin.not_set", "Not set")}
            </p>
          </div>
        </div>
        <div className="flex gap-2">
          {employee.has_kiosk_pin && (
            <Button
              variant="outline"
              size="sm"
              onClick={() => save(null)}
              disabled={setPin.isPending}
            >
              {t("kiosk_pin.remove", "Remove")}
            </Button>
          )}
          <Button size="sm" onClick={() => setOpen(true)}>
            {employee.has_kiosk_pin
              ? t("kiosk_pin.change", "Change PIN")
              : t("kiosk_pin.set", "Set PIN")}
          </Button>
        </div>
      </CardContent>

      <Dialog
        open={open}
        onOpenChange={(next) => {
          setOpen(next);
          if (!next) {
            setPinValue("");
            setError(null);
          }
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t("kiosk_pin.title", "Kiosk PIN")}</DialogTitle>
            <DialogDescription>
              {t(
                "kiosk_pin.description",
                "The employee enters this at a kiosk when your organisation requires a PIN. Tell it to them in person; it cannot be shown again.",
              )}
            </DialogDescription>
          </DialogHeader>
          <form
            onSubmit={(e) => {
              e.preventDefault();
              save(pin);
            }}
            className="space-y-4"
          >
            <div>
              <Label htmlFor="kiosk-pin">
                {t("kiosk_pin.field", "New PIN")}
              </Label>
              <Input
                id="kiosk-pin"
                type="password"
                inputMode="numeric"
                autoComplete="off"
                maxLength={6}
                value={pin}
                onChange={(e) => setPinValue(e.target.value.replace(/\D/g, ""))}
                className="mt-1 font-mono"
                aria-invalid={error !== null}
                aria-describedby="kiosk-pin-help"
              />
              <p
                id="kiosk-pin-help"
                className={
                  error
                    ? "mt-1 text-xs text-destructive"
                    : "mt-1 text-xs text-muted-foreground"
                }
              >
                {error ?? t("kiosk_pin.hint", "Four to six digits.")}
              </p>
            </div>
            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setOpen(false)}
              >
                {t("common.cancel")}
              </Button>
              <Button
                type="submit"
                disabled={setPin.isPending || pin.length < 4}
              >
                {setPin.isPending && (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                )}
                {t("kiosk_pin.save", "Save PIN")}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </Card>
  );
}
