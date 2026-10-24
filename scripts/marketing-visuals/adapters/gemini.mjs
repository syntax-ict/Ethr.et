/**
 * Gemini adapter: gemini-2.5-flash-image through generateContent.
 *
 *   POST https://generativelanguage.googleapis.com/v1beta/models/<model>:generateContent
 *   header x-goog-api-key, body with responseModalities ["IMAGE"]
 *   response candidates[0].content.parts[].inlineData { mimeType, data }  (base64)
 *
 * The model has no negative prompt, so the "Avoid:" line travels inside the
 * prompt. Its aspect ratios include 16:9 and 3:2 but not 16:10, so a 16:10
 * scene is requested at 3:2, which the page crops. Output is around 1024 on
 * the short side, below the 1920 target; see the operations document.
 * Google's own guide now calls this model legacy and points newer models at a
 * different endpoint, so pass --model only for a model that still answers
 * generateContent.
 */

export const name = "gemini";
export const envKey = "GEMINI_API_KEY";
export const defaultModel = "gemini-2.5-flash-image";
export const approximateCostUsd = 0.04;

const BASE = "https://generativelanguage.googleapis.com/v1beta";
const NATIVE_ASPECTS = new Set(["1:1", "2:3", "3:2", "3:4", "4:3", "4:5", "5:4", "9:16", "16:9", "21:9"]);

export function aspectRatioFor(aspect) {
  if (NATIVE_ASPECTS.has(aspect)) return aspect;
  if (aspect === "16:10") return "3:2";
  return "16:9";
}

export async function generate({ prompt, aspect, model, apiKey, extraInput = {}, fetch }) {
  const body = {
    contents: [{ parts: [{ text: prompt }] }],
    generationConfig: {
      responseModalities: ["IMAGE"],
      imageConfig: { aspectRatio: aspectRatioFor(aspect), ...extraInput },
    },
  };
  const response = await fetch(`${BASE}/models/${model}:generateContent`, {
    method: "POST",
    headers: { "x-goog-api-key": apiKey, "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });
  if (!response.ok) {
    throw new Error(`Gemini refused the request: HTTP ${response.status} ${await response.text()}`);
  }
  const json = await response.json();
  const parts = json.candidates?.[0]?.content?.parts ?? [];
  const image = parts.find((part) => part.inlineData?.data);
  if (!image) {
    const reason = json.candidates?.[0]?.finishReason ?? json.promptFeedback?.blockReason ?? "no image part in the response";
    throw new Error(`Gemini returned no image: ${reason}`);
  }
  const bytes = new Uint8Array(Buffer.from(image.inlineData.data, "base64"));
  return { bytes, model, mimeType: image.inlineData.mimeType };
}
