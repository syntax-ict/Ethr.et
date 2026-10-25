/**
 * On-device liveness before an attendance selfie: one real face, in front of
 * the camera, doing what it was asked (a blink or a head turn, chosen at
 * random so a photo or a recording cannot be prepared for it).
 *
 * Everything runs on the phone with MediaPipe's face landmarker. The WASM is
 * served from this site (/mediapipe, copied from the npm package); the model
 * is fetched once from Google's MediaPipe model storage and cached by the
 * browser. No image, landmark or face template leaves the phone: only the
 * outcome is sent with the punch, and the server weighs it as one signal
 * among many (AttendanceRiskScorer). Nothing is refused for it.
 */
import type { FaceLandmarker } from "@mediapipe/tasks-vision";

export type LivenessResult = "passed" | "failed" | "no_face" | "unavailable";
export type LivenessPrompt = "blink" | "turn";

const WASM_BASE = "/mediapipe";
const MODEL_URL =
  "https://storage.googleapis.com/mediapipe-models/face_landmarker/face_landmarker/float16/1/face_landmarker.task";

let loading: Promise<FaceLandmarker | null> | null = null;

/** The landmarker, loaded once; null on a device that cannot run it. */
export function loadLandmarker(): Promise<FaceLandmarker | null> {
  loading ??= (async () => {
    try {
      const vision = await import("@mediapipe/tasks-vision");
      const files = await vision.FilesetResolver.forVisionTasks(WASM_BASE);
      const make = (delegate: "GPU" | "CPU") =>
        vision.FaceLandmarker.createFromOptions(files, {
          baseOptions: { modelAssetPath: MODEL_URL, delegate },
          runningMode: "VIDEO",
          numFaces: 2,
          outputFaceBlendshapes: true,
        });
      try {
        return await make("GPU");
      } catch {
        return await make("CPU");
      }
    } catch {
      loading = null;
      return null;
    }
  })();
  return loading;
}

export function randomPrompt(): LivenessPrompt {
  const bytes = new Uint8Array(1);
  crypto.getRandomValues(bytes);
  return bytes[0] % 2 === 0 ? "blink" : "turn";
}

/** How far the nose sits between the cheeks: about 0.5 facing the camera. */
function turnRatio(points: { x: number }[]): number | null {
  const nose = points[1];
  const left = points[234];
  const right = points[454];
  if (!nose || !left || !right || right.x === left.x) return null;
  return (nose.x - left.x) / (right.x - left.x);
}

function blinkScore(
  shapes: { categories: { categoryName: string; score: number }[] } | undefined,
): number | null {
  if (!shapes) return null;
  const pick = (name: string) =>
    shapes.categories.find((c) => c.categoryName === name)?.score ?? 0;
  return Math.max(pick("eyeBlinkLeft"), pick("eyeBlinkRight"));
}

/**
 * Watch the camera until the prompt is done or time runs out. Two faces at
 * once is a failure (someone holding up a colleague's photo beside their own
 * face); never a face at all is `no_face`.
 */
export async function checkLiveness(
  video: HTMLVideoElement,
  prompt: LivenessPrompt,
  timeoutMs = 7000,
): Promise<LivenessResult> {
  const landmarker = await loadLandmarker();
  if (!landmarker) return "unavailable";

  return new Promise((resolve) => {
    const started = performance.now();
    let sawFace = false;
    let multiple = 0;
    let frames = 0;
    let eyesOpen = false;
    let blinked = false;
    let facing = false;
    let turned = false;

    const step = () => {
      const now = performance.now();
      if (now - started > timeoutMs) {
        resolve(sawFace ? "failed" : "no_face");
        return;
      }
      if (video.readyState >= 2) {
        const result = landmarker.detectForVideo(video, now);
        frames++;
        const faces = result.faceLandmarks.length;
        if (faces > 1) multiple++;
        if (faces >= 1) {
          sawFace = true;
          if (prompt === "blink") {
            const score = blinkScore(result.faceBlendshapes?.[0]);
            if (score !== null && score < 0.2) eyesOpen = true;
            if (score !== null && score > 0.5 && eyesOpen) blinked = true;
          } else {
            const ratio = turnRatio(result.faceLandmarks[0]);
            if (ratio !== null && ratio > 0.4 && ratio < 0.6) facing = true;
            if (ratio !== null && facing && (ratio < 0.3 || ratio > 0.7)) {
              turned = true;
            }
          }
        }
        if (multiple > Math.max(3, frames * 0.2)) {
          resolve("failed");
          return;
        }
        if (blinked || turned) {
          resolve("passed");
          return;
        }
      }
      requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
  });
}
