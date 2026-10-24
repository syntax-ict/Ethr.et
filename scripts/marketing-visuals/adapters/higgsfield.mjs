/**
 * Higgsfield adapter: the Soul text-to-image model through the Higgsfield API.
 *
 * Confirmed from docs.higgsfield.ai on 2026-10-24:
 *   POST https://api.higgsfield.ai/higgsfield-ai/soul/v2/standard
 *   header Authorization: Key <key id>:<key secret>
 *   body { prompt }
 *   response { status: "queued", request_id, status_url, cancel_url }
 *   GET <status_url> until status is completed, failed, nsfw or canceled;
 *   a completed response carries images: [{ url }]
 *
 * Not confirmed: any field that sets the aspect ratio or size. The documented
 * example sends only a prompt, so this adapter sends only a prompt and leaves
 * the size to the model. If the model page documents a size field, pass it
 * with --input-json, for example --input-json '{"aspect_ratio":"16:9"}', and
 * the image checks will tell you whether the result fits. Until then expect a
 * 16:9 scene to need --force after a manual export, or use the web UI and
 * --import, which is the route the operations document recommends for this
 * provider.
 *
 * The credential is one environment variable, HIGGSFIELD_API_KEY, holding
 * "<key id>:<key secret>" as the header expects. HIGGSFIELD_API_KEY_ID and
 * HIGGSFIELD_API_KEY_SECRET are accepted as a pair instead.
 */

export const name = "higgsfield";
export const envKey = "HIGGSFIELD_API_KEY";
export const defaultModel = "higgsfield-ai/soul/v2/standard";
export const approximateCostUsd = 0.05;

const BASE = "https://api.higgsfield.ai";
const TERMINAL = new Set(["completed", "failed", "nsfw", "canceled"]);

export function credential(apiKey, env = {}) {
  if (apiKey && apiKey.includes(":")) return apiKey;
  if (env.HIGGSFIELD_API_KEY_ID && env.HIGGSFIELD_API_KEY_SECRET) {
    return `${env.HIGGSFIELD_API_KEY_ID}:${env.HIGGSFIELD_API_KEY_SECRET}`;
  }
  const error = new Error(
    "HIGGSFIELD_API_KEY must hold \"<key id>:<key secret>\", or set HIGGSFIELD_API_KEY_ID and HIGGSFIELD_API_KEY_SECRET.",
  );
  error.exitCode = 2;
  throw error;
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

export async function generate({ prompt, model, apiKey, env = {}, extraInput = {}, fetch, log }) {
  const headers = {
    Authorization: `Key ${credential(apiKey, env)}`,
    "Content-Type": "application/json",
  };
  const submitted = await fetch(`${BASE}/${model}`, {
    method: "POST",
    headers,
    body: JSON.stringify({ prompt, ...extraInput }),
  });
  if (!submitted.ok) {
    throw new Error(`Higgsfield refused the request: HTTP ${submitted.status} ${await submitted.text()}`);
  }
  let request = await submitted.json();
  if (!request.status_url) throw new Error("Higgsfield returned no status_url; the endpoint shape may have changed");

  const deadline = Date.now() + 10 * 60 * 1000;
  while (!TERMINAL.has(request.status)) {
    if (Date.now() > deadline) throw new Error(`Higgsfield request ${request.request_id} did not finish within ten minutes`);
    await sleep(3000);
    const polled = await fetch(request.status_url, { headers: { Authorization: headers.Authorization } });
    if (!polled.ok) throw new Error(`Higgsfield status poll failed: HTTP ${polled.status}`);
    request = { ...request, ...(await polled.json()) };
    log?.(`  ${request.status}`);
  }
  if (request.status !== "completed") {
    throw new Error(`Higgsfield request ${request.request_id} ${request.status}: ${request.error ?? "no error given"}`);
  }
  const url = request.images?.[0]?.url;
  if (!url) throw new Error("Higgsfield completed without an images[0].url");
  const download = await fetch(url);
  if (!download.ok) throw new Error(`Downloading the image failed: HTTP ${download.status}`);
  const bytes = new Uint8Array(await download.arrayBuffer());
  return { bytes, model };
}
