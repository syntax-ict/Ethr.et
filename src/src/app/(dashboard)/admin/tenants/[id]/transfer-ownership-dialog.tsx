"use client";

import { useState } from "react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import {
  useTransferTenantOwnership,
  type AdminTenantDetail,
} from "@/features/admin/api";
import { apiErrorMessage } from "@/lib/api/error-message";
import { useT } from "@/lib/i18n/useT";

/**
 * ETHR hands an organisation to another of its active administrators, for an
 * owner who has left or cannot be reached. The organisation's own owner does
 * this from Settings; here a reason is required, and it goes on the record.
 */
export function TransferOwnershipDialog({
  tenant,
  open,
  onOpenChange,
}: {
  tenant: AdminTenantDetail;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { t } = useT();
  const transfer = useTransferTenantOwnership();
  const [to, setTo] = useState("");
  const [reason, setReason] = useState("");
  const candidates = (tenant.admins ?? []).filter(
    (a) => a.public_id !== tenant.owner?.public_id,
  );

  function submit() {
    transfer.mutate(
      { publicId: tenant.public_id, userPublicId: to, reason },
      {
        onSuccess: () => {
          toast.success(
            t(
              "admin_tenant_detail_page.ownership_transferred",
              "Owner changed",
            ),
          );
          onOpenChange(false);
          setTo("");
          setReason("");
        },
        onError: (err) =>
          toast.error(
            apiErrorMessage(
              err,
              t(
                "admin_tenant_detail_page.ownership_failed",
                "The owner could not be changed",
              ),
            ),
          ),
      },
    );
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {t("admin_tenant_detail_page.transfer_ownership", "Change owner")}
          </DialogTitle>
          <DialogDescription>
            {t(
              "admin_tenant_detail_page.transfer_ownership_hint",
              "For an owner who has left or cannot be reached. The new owner must be an active administrator of the organisation. The change and its reason are recorded.",
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="new-owner">
              {t("admin_tenant_detail_page.new_owner", "New owner")}
            </Label>
            <Select value={to} onValueChange={setTo}>
              <SelectTrigger id="new-owner">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {candidates.map((a) => (
                  <SelectItem key={a.public_id} value={a.public_id}>
                    {a.name}
                    {a.email ? ` (${a.email})` : ""}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-2">
            <Label htmlFor="ownership-reason">
              {t("admin_tenant_detail_page.reason", "Reason")}
            </Label>
            <Textarea
              id="ownership-reason"
              value={reason}
              onChange={(e) => setReason(e.target.value)}
            />
          </div>
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            {t("common.cancel", "Cancel")}
          </Button>
          <Button
            onClick={submit}
            disabled={
              transfer.isPending || to === "" || reason.trim().length < 5
            }
          >
            {t("admin_tenant_detail_page.transfer_ownership", "Change owner")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
