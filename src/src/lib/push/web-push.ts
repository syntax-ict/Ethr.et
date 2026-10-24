/**
 * This browser's web push subscription: notifications while ETHR is closed.
 * The server must have VAPID keys (GET /push/config says so); without them
 * nothing here does anything. iOS delivers web push only to an installed app
 * (Add to Home Screen), from iOS 16.4.
 */
import { apiClient } from "@/api/client";

export interface PushConfig {
  enabled: boolean;
  public_key: string | null;
}

export function pushSupported(): boolean {
  return (
    typeof window !== "undefined" &&
    "serviceWorker" in navigator &&
    "PushManager" in window &&
    typeof Notification !== "undefined"
  );
}

/** VAPID keys arrive base64url-encoded; the browser wants raw bytes. */
function keyBytes(base64url: string): Uint8Array<ArrayBuffer> {
  const padded = (base64url + "=".repeat((4 - (base64url.length % 4)) % 4))
    .replace(/-/g, "+")
    .replace(/_/g, "/");
  const raw = atob(padded);
  const bytes = new Uint8Array(new ArrayBuffer(raw.length));
  for (let i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);
  return bytes;
}

export async function currentSubscription(): Promise<PushSubscription | null> {
  if (!pushSupported()) return null;
  const registration = await navigator.serviceWorker.getRegistration();
  return (await registration?.pushManager.getSubscription()) ?? null;
}

/** Ask permission (from a tap), subscribe, and register with the server. */
export async function subscribe(publicKey: string): Promise<boolean> {
  if (!pushSupported()) return false;
  const permission = await Notification.requestPermission();
  if (permission !== "granted") return false;
  const registration = await navigator.serviceWorker.ready;
  const subscription =
    (await registration.pushManager.getSubscription()) ??
    (await registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: keyBytes(publicKey),
    }));
  const json = subscription.toJSON();
  await apiClient.post("/push/subscriptions", {
    endpoint: json.endpoint,
    keys: json.keys,
    content_encoding: "aes128gcm",
  });
  return true;
}

export async function unsubscribe(): Promise<void> {
  const subscription = await currentSubscription();
  if (!subscription) return;
  await apiClient
    .delete("/push/subscriptions", {
      data: { endpoint: subscription.endpoint },
    })
    .catch(() => undefined);
  await subscription.unsubscribe();
}
