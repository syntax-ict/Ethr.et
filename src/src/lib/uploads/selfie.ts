"use client";

/**
 * An attendance selfie, small enough to send (attendance photo policy:
 * at most 1.5 MB, clear enough to verify attendance).
 *
 * The camera frame is scaled to at most 1280 px on its longest edge and
 * encoded as JPEG, stepping the quality down, then the size, until it fits.
 * The server refuses a selfie over 1.5 MB and keeps it smaller still.
 */

/** The largest selfie the server accepts, decoded (FileStorageService::SELFIE_UPLOAD_MAX_BYTES). */
export const SELFIE_MAX_BYTES = 1_572_864;

const EDGES = [1280, 960, 720] as const;
const QUALITIES = [0.85, 0.75, 0.65, 0.55] as const;

/** The decoded size of a base64 data URL, in bytes. */
export function dataUrlBytes(dataUrl: string): number {
  const base64 = dataUrl.slice(dataUrl.indexOf(",") + 1);
  const padding = base64.endsWith("==") ? 2 : base64.endsWith("=") ? 1 : 0;
  return Math.floor((base64.length * 3) / 4) - padding;
}

/**
 * The first encoding that fits, trying each quality at each edge from the
 * clearest down. Returns the smallest attempt when none fits, which the
 * server then refuses with a clear message rather than storing.
 */
export function fitSelfie(
  encode: (maxEdge: number, quality: number) => string,
  maxBytes: number = SELFIE_MAX_BYTES,
): string {
  let smallest: string | null = null;
  for (const edge of EDGES) {
    for (const quality of QUALITIES) {
      const dataUrl = encode(edge, quality);
      if (dataUrlBytes(dataUrl) <= maxBytes) return dataUrl;
      if (smallest === null || dataUrlBytes(dataUrl) < dataUrlBytes(smallest)) {
        smallest = dataUrl;
      }
    }
  }
  return smallest ?? "";
}

/** The current camera frame as a selfie data URL, within the size limit. */
export function captureSelfie(video: HTMLVideoElement): string {
  const width = video.videoWidth;
  const height = video.videoHeight;
  return fitSelfie((maxEdge, quality) => {
    const scale = Math.min(1, maxEdge / Math.max(width, height));
    const canvas = document.createElement("canvas");
    canvas.width = Math.round(width * scale);
    canvas.height = Math.round(height * scale);
    canvas
      .getContext("2d")
      ?.drawImage(video, 0, 0, canvas.width, canvas.height);
    return canvas.toDataURL("image/jpeg", quality);
  });
}
