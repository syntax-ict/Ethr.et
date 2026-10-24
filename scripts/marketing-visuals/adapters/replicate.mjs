/**
 * Replicate adapter: FLUX 1.1 pro by Black Forest Labs.
 *
 *   POST https://api.replicate.com/v1/models/<model>/predictions   (Prefer: wait)
 *   GET  https://api.replicate.com/v1/predictions/<id>             until succeeded
 *   GET  <output url>                                              the image bytes
 *
 * FLUX has no negative prompt field, so the "Avoid:" line travels inside the
 * prompt. Its aspect_ratio enum has 16:9 but not 16:10, and custom sizes are
 * multiples of 32 up to 1440 wide, so a 16:10 scene is requested as 1440x896:
 * below the 1920 target, inside the page's crop tolerance. Pass
 * --model black-forest-labs/flux-1.1-pro-ultra for larger output; it takes the
 * same request shape without width and height.
 */

export const name = "replicate";
export const envKey = "REPLICATE_API_TOKEN";
export const defaultModel = "black-forest-labs/flux-1.1-pro";
export const approximateCostUsd = 0.04;

const BASE = "https://api.replicate.com/v1";
const NATIVE_ASPECTS = new Set(["1:1", "16:9", "3:2", "2:3", "4:5", "5:4", "9:16", "3:4", "4:3"]);
const MAX_SIDE = 1440;

export function sizeInput(aspect, width, height, model) {
  const isUltra = model.includes("ultra");
  if (NATIVE_ASPECTS.has(aspect) || isUltra) {
    return { aspect_ratio: NATIVE_ASPECTS.has(aspect) ? aspect : "16:9" };
  }
  const scale = Math.min(1, MAX_SIDE / width);
  const round32 = (n) => Math.max(256, Math.floor((n * scale) / 32) * 32);
  return { aspect_ratio: "custom", width: round32(width), height: round32(height) };
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

export async function generate({ prompt, aspect, width, height, model, apiKey, extraInput = {}, fetch, log }) {
  const headers = {
    Authorization: `Bearer ${apiKey}`,
    "Content-Type": "application/json",
    Prefer: "wait",
  };
  const input = {
    prompt,
    ...sizeInput(aspect, width, height, model),
    output_format: "jpg",
    output_quality: 85,
    safety_tolerance: 2,
    prompt_upsampling: false,
    ...extraInput,
  };

  const created = await fetch(`${BASE}/models/${model}/predictions`, {
    method: "POST",
    headers,
    body: JSON.stringify({ input }),
  });
  if (!created.ok) {
    throw new Error(`Replicate refused the prediction: HTTP ${created.status} ${await created.text()}`);
  }
  let prediction = await created.json();

  const deadline = Date.now() + 5 * 60 * 1000;
  while (!["succeeded", "failed", "canceled"].includes(prediction.status)) {
    if (Date.now() > deadline) throw new Error(`Replicate prediction ${prediction.id} did not finish within five minutes`);
    await sleep(2000);
    const polled = await fetch(`${BASE}/predictions/${prediction.id}`, { headers: { Authorization: headers.Authorization } });
    if (!polled.ok) throw new Error(`Replicate poll failed: HTTP ${polled.status}`);
    prediction = await polled.json();
    log?.(`  ${prediction.status}`);
  }
  if (prediction.status !== "succeeded") {
    throw new Error(`Replicate prediction ${prediction.id} ${prediction.status}: ${prediction.error ?? "no error given"}`);
  }

  const url = Array.isArray(prediction.output) ? prediction.output[0] : prediction.output;
  if (typeof url !== "string") throw new Error("Replicate returned no output URL");
  const download = await fetch(url);
  if (!download.ok) throw new Error(`Downloading the image failed: HTTP ${download.status}`);
  const bytes = new Uint8Array(await download.arrayBuffer());
  return { bytes, model: `${model}${prediction.version ? `@${prediction.version}` : ""}` };
}
