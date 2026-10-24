/**
 * OpenAI adapter: gpt-image-1 through the images endpoint.
 *
 *   POST https://api.openai.com/v1/images/generations
 *   header Authorization: Bearer, body { model, prompt, size, quality, output_format }
 *   response data[0].b64_json
 *
 * The model has no negative prompt, so the "Avoid:" line travels inside the
 * prompt. Its landscape size is 1536x1024 (3:2), used for both 16:9 and
 * 16:10 scenes and cropped by the page. output_format jpeg with
 * output_compression 85 keeps the file inside the 1 MB cap.
 */

export const name = "openai";
export const envKey = "OPENAI_API_KEY";
export const defaultModel = "gpt-image-1";
export const approximateCostUsd = 0.25;

const ENDPOINT = "https://api.openai.com/v1/images/generations";

export async function generate({ prompt, model, apiKey, extraInput = {}, fetch }) {
  const body = {
    model,
    prompt,
    n: 1,
    size: "1536x1024",
    quality: "high",
    output_format: "jpeg",
    output_compression: 85,
    ...extraInput,
  };
  const response = await fetch(ENDPOINT, {
    method: "POST",
    headers: { Authorization: `Bearer ${apiKey}`, "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });
  if (!response.ok) {
    throw new Error(`OpenAI refused the request: HTTP ${response.status} ${await response.text()}`);
  }
  const json = await response.json();
  const b64 = json.data?.[0]?.b64_json;
  if (!b64) throw new Error("OpenAI returned no b64_json image");
  const bytes = new Uint8Array(Buffer.from(b64, "base64"));
  return { bytes, model };
}
