"use client";

import { useState } from "react";
import { Loader2, Plus, Trash2, FileText } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { EmptyState } from "@/components/shared/empty-state";
import { DualCalendarDateInput } from "@/components/shared/dual-calendar-date-input";
import { FormField } from "@/components/patterns/FormField";
import { FormErrorSummary } from "@/components/patterns/FormErrorSummary";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { Controller } from "react-hook-form";
import { useT } from "@/lib/i18n/useT";
import { useZodForm } from "@/lib/forms/use-zod-form";
import { rules, fieldMessage } from "@/lib/forms/rules";
import { z } from "zod";
import { toast } from "sonner";
import { toastError } from "@/lib/errors";
import {
  useDeleteEmployeeDocument,
  useEmployeeDocuments,
  useUploadEmployeeDocument,
  type DocumentUpload,
} from "../api";

/** Mirrors `StoreDocumentRequest::rules()['type']`; tsc fails if it drifts. */
const DOCUMENT_TYPES = [
  "contract",
  "certificate",
  "id_copy",
  "academic",
  "medical",
  "other",
] as const satisfies readonly DocumentUpload["type"][];

/** `max:10240` in the FormRequest is kilobytes. */
const MAX_FILE_BYTES = 10240 * 1024;

/** `mimes:pdf,jpg,jpeg,png,webp,doc,docx,txt`, as file extensions. */
const ALLOWED_EXTENSIONS = [
  "pdf",
  "jpg",
  "jpeg",
  "png",
  "webp",
  "doc",
  "docx",
  "txt",
] as const;

/**
 * Mirrors `StoreDocumentRequest` exactly.
 *
 * It did not before, and the mismatch was fatal: this form posted `file` plus a
 * free-text `document_type`, while the endpoint requires `title` (never sent)
 * and `type` (never sent, and constrained to the six values above). Every
 * upload 422'd, and the failure surfaced as a generic "Upload failed" toast
 * that named no field — so a broken feature looked like a flaky one.
 */
const documentSchema = z.object({
  title: rules.requiredText(255),
  type: z.enum(DOCUMENT_TYPES),
  expiry_date: rules.optionalDate(),
});
type DocumentValues = z.infer<typeof documentSchema>;

export function DocumentsTab({ employeeId }: { employeeId: string }) {
  const { t } = useT();
  const [uploadOpen, setUploadOpen] = useState(false);
  const [file, setFile] = useState<File | null>(null);
  const [fileError, setFileError] = useState<string | null>(null);

  const {
    register,
    control,
    submit,
    reset,
    setRootError,
    rootError,
    formState: { errors, isSubmitting },
  } = useZodForm<DocumentValues>({
    schema: documentSchema,
    defaultValues: { title: "", type: "contract", expiry_date: "" },
  });

  /**
   * The file lives outside the schema — Zod would have to validate a `File`
   * instance, which does not survive `defaultValues`/`reset` cleanly — but it
   * is checked against the same limits the server enforces, so a 10 MB cap is
   * reported before the upload rather than after it.
   */
  function validateFile(candidate: File | null): string | null {
    if (!candidate) {
      return t("employee.documents.file_required", "Choose a file to upload");
    }
    const extension = candidate.name.split(".").pop()?.toLowerCase() ?? "";
    if (
      !ALLOWED_EXTENSIONS.includes(
        extension as (typeof ALLOWED_EXTENSIONS)[number],
      )
    ) {
      return t(
        "employee.documents.file_type",
        "Allowed types: PDF, JPG, PNG, WEBP, DOC, DOCX, TXT",
      );
    }
    if (candidate.size > MAX_FILE_BYTES) {
      return t(
        "employee.documents.file_too_large",
        "The file must be 10 MB or smaller",
      );
    }
    return null;
  }

  // Through QueryBoundary: a failed load used to fall through to "No
  // documents", which is a claim about the employee, not about the request.
  const query = useEmployeeDocuments(employeeId);
  const uploadDoc = useUploadEmployeeDocument(employeeId);
  const deleteDoc = useDeleteEmployeeDocument(employeeId);

  async function onSubmit(values: DocumentValues) {
    const problem = validateFile(file);
    setFileError(problem);
    if (problem || !file) {
      // Not a schema field, so it cannot block submit on its own — surface it
      // in the summary too, otherwise a valid-looking form appears to do
      // nothing when only the file is missing.
      setRootError(problem);
      return;
    }
    await uploadDoc.mutateAsync({ ...values, file });
    toast.success(t("employee.documents.uploaded", "Document uploaded"));
    setUploadOpen(false);
    setFile(null);
    setFileError(null);
    reset();
  }

  function onDelete(docId: string) {
    deleteDoc.mutate(docId, {
      onSuccess: () =>
        toast.success(t("employee.documents.deleted", "Document deleted")),
      onError: (error) =>
        toastError(
          error,
          t(
            "employee.documents.delete_failed",
            "Could not delete the document",
          ),
        ),
    });
  }

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between">
        <CardTitle className="text-base">
          {t("employee.documents.title", "Documents")}
        </CardTitle>
        <Button size="sm" onClick={() => setUploadOpen(true)}>
          <Plus className="mr-2 h-3 w-3" /> {t("common.upload", "Upload")}
        </Button>
      </CardHeader>
      <CardContent>
        <QueryBoundary
          query={query}
          loading={<Skeleton className="h-20 w-full" />}
          empty={
            <EmptyState
              icon={FileText}
              title={t("employee.documents.empty_title", "No documents")}
              description={t(
                "employee.documents.empty_desc",
                "Upload contracts, IDs, and other documents",
              )}
            />
          }
        >
          {(docs) => (
            <div className="space-y-2">
              {docs.map((d) => (
                <div
                  key={d.public_id}
                  className="flex items-center justify-between rounded-lg border p-3"
                >
                  <div className="flex items-center gap-3">
                    <FileText className="h-4 w-4 text-muted-foreground" />
                    <div>
                      <p className="text-sm font-medium">{d.title}</p>
                      <p className="text-xs text-muted-foreground">
                        {t(`employee.documents.type_${d.type}`, d.type)}
                      </p>
                    </div>
                  </div>
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => onDelete(d.public_id)}
                    aria-label={`${t("common.delete", "Delete")} ${d.title}`}
                  >
                    <Trash2 className="h-4 w-4 text-destructive" />
                  </Button>
                </div>
              ))}
            </div>
          )}
        </QueryBoundary>
      </CardContent>

      <Dialog open={uploadOpen} onOpenChange={setUploadOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {t("employee.documents.upload_title", "Upload Document")}
            </DialogTitle>
          </DialogHeader>
          <form
            onSubmit={submit(
              onSubmit,
              t("employee.documents.upload_failed", "Upload failed"),
            )}
            className="space-y-4"
            noValidate
          >
            <FormErrorSummary message={rootError} />

            <FormField
              id="doc_title"
              label={t("employee.documents.title_label", "Title")}
              required
              error={fieldMessage(t, errors.title?.message)}
            >
              <Input
                {...register("title")}
                placeholder={t(
                  "employee.documents.title_placeholder",
                  "Employment contract 2026",
                )}
                className="mt-1"
              />
            </FormField>

            <FormField
              id="doc_type"
              label={t("employee.documents.type_label", "Document Type")}
              required
              error={fieldMessage(t, errors.type?.message)}
            >
              {(control_) => (
                <Controller
                  name="type"
                  control={control}
                  render={({ field }) => (
                    <Select value={field.value} onValueChange={field.onChange}>
                      <SelectTrigger {...control_} className="mt-1">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {DOCUMENT_TYPES.map((value) => (
                          <SelectItem key={value} value={value}>
                            {t(`employee.documents.type_${value}`, value)}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                />
              )}
            </FormField>

            <FormField
              id="doc_expiry"
              label={
                <>
                  {t("employee.documents.expiry_label", "Expiry date")}{" "}
                  <span className="text-xs text-muted-foreground">
                    ({t("leave_page.optional", "optional")})
                  </span>
                </>
              }
              error={fieldMessage(t, errors.expiry_date?.message)}
            >
              {(control_) => (
                <Controller
                  name="expiry_date"
                  control={control}
                  render={({ field }) => (
                    <DualCalendarDateInput
                      {...control_}
                      value={field.value}
                      onChange={field.onChange}
                      className="mt-1"
                    />
                  )}
                />
              )}
            </FormField>

            <FormField
              id="doc_file"
              label={t("employee.documents.file_label", "File")}
              required
              hint={t(
                "employee.documents.file_hint",
                "PDF, JPG, PNG, WEBP, DOC, DOCX or TXT · up to 10 MB",
              )}
              error={fileError ?? undefined}
            >
              <Input
                type="file"
                accept={ALLOWED_EXTENSIONS.map((e) => `.${e}`).join(",")}
                onChange={(e) => {
                  const picked = e.target.files?.[0] ?? null;
                  setFile(picked);
                  setFileError(validateFile(picked));
                }}
                className="mt-1"
              />
            </FormField>

            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setUploadOpen(false)}
              >
                {t("common.cancel", "Cancel")}
              </Button>
              <Button type="submit" disabled={isSubmitting}>
                {isSubmitting && (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                )}
                {t("common.upload", "Upload")}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </Card>
  );
}
