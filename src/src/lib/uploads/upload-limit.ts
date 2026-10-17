"use client";

/**
 * The largest file the server really accepts, and the means to stay under it.
 *
 * PHP refuses an upload above its own limits before the application sees it,
 * so a form's 5 MB or 10 MB means nothing above them. Production was measured
 * on 2026-10-17 at PHP's stock 2 MB upload / 8 MB request, with the shipped
 * `.user.ini` not applied by the host. `GET /auth/me` reports the real figure
 * (`upload_limit_bytes`, read by `useUploadLimit` in `@/features/auth/api`);
 * forms refuse an oversize file with a clear message,
 * and photos are shrunk in the browser first, which keeps them well under it.
 */

/** A size as people read it: "2 MB", "512 KB". */
export function sizeLabel(bytes: number): string {
  if (bytes >= 1024 * 1024) {
    const mb = bytes / (1024 * 1024);
    return `${Number.isInteger(mb) ? mb : mb.toFixed(1)} MB`;
  }
  return `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

/**
 * Whether a file is over the server's limit when the form's own limit allows
 * it. The form keeps its own message for its own limit; this covers the gap
 * between the two, returning the limit to name, or null when the file fits.
 */
export function overServerLimit(
  file: File,
  formMax: number,
  serverLimit: number | null,
): number | null {
  if (serverLimit === null || serverLimit >= formMax) return null;
  return file.size > serverLimit ? serverLimit : null;
}

/**
 * A photo scaled so its longest edge is at most `maxEdge` and re-encoded,
 * which also drops its metadata. Returns the original file when the browser
 * cannot decode it, when it is already small enough, or when re-encoding
 * would not make it smaller. The server re-encodes whatever arrives, so this
 * changes the upload's size, not what is kept.
 */
export async function shrinkImage(
  file: File,
  {
    maxEdge,
    type,
    quality = 0.85,
  }: { maxEdge: number; type: "image/jpeg" | "image/png"; quality?: number },
): Promise<File> {
  if (
    typeof createImageBitmap !== "function" ||
    typeof document === "undefined"
  ) {
    return file;
  }
  let bitmap: ImageBitmap;
  try {
    bitmap = await createImageBitmap(file);
  } catch {
    return file;
  }
  const scale = Math.min(1, maxEdge / Math.max(bitmap.width, bitmap.height));
  if (scale === 1 && file.size <= 1024 * 1024) {
    bitmap.close();
    return file;
  }
  const canvas = document.createElement("canvas");
  canvas.width = Math.round(bitmap.width * scale);
  canvas.height = Math.round(bitmap.height * scale);
  const context = canvas.getContext("2d");
  if (!context) {
    bitmap.close();
    return file;
  }
  context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
  bitmap.close();
  const blob = await new Promise<Blob | null>((resolve) =>
    canvas.toBlob(resolve, type, quality),
  );
  if (!blob || blob.size >= file.size) return file;
  const extension = type === "image/png" ? "png" : "jpg";
  const name = file.name.replace(/\.[^.]+$/, "") + `.${extension}`;
  return new File([blob], name, { type, lastModified: file.lastModified });
}

/** The message for a file over the server's limit. */
export const SERVER_LIMIT_KEY = "upload.too_large_server";
export const SERVER_LIMIT_FALLBACK =
  "This file is :size. The largest file you can upload here is :max.";
