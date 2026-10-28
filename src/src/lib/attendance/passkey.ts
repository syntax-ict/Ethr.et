/**
 * The phone an employee punches with, proved by a passkey.
 *
 * A passkey's private key stays in the phone's secure hardware and the phone
 * unlocks it with the owner's fingerprint, face or PIN. The server keeps the
 * public key and checks a fresh signature on every punch, so a friend's
 * phone, a second phone, another browser or a private window cannot punch
 * for someone else (AttendanceDeviceGate on the API).
 *
 * Online, the server issues a single-use challenge for each punch. Offline,
 * the phone signs the punch's own fields with the grant's nonce, so an
 * offline punch cannot be edited after it was signed (OfflineGrant on the
 * API). Both sides hash the same text: coordinates are fixed to six decimals.
 */
import {
  browserSupportsWebAuthn,
  platformAuthenticatorIsAvailable,
  startAuthentication,
  startRegistration,
  type AuthenticationResponseJSON,
  type PublicKeyCredentialCreationOptionsJSON,
  type PublicKeyCredentialRequestOptionsJSON,
  type RegistrationResponseJSON,
} from "@simplewebauthn/browser";

export type DeviceProof = AuthenticationResponseJSON;

/** Whether this browser can hold a passkey on the device itself. */
export async function passkeySupported(): Promise<boolean> {
  if (typeof window === "undefined" || !browserSupportsWebAuthn()) return false;
  try {
    return await platformAuthenticatorIsAvailable();
  } catch {
    return false;
  }
}

/** Create this phone's passkey from the server's options. */
export function registerPasskey(
  options: PublicKeyCredentialCreationOptionsJSON,
): Promise<RegistrationResponseJSON> {
  return startRegistration({ optionsJSON: options });
}

/** Sign one punch with this phone's passkey. */
export function signWithPasskey(
  options: PublicKeyCredentialRequestOptionsJSON,
): Promise<DeviceProof> {
  return startAuthentication({ optionsJSON: options });
}

const THIS_PHONE_KEY = "ethr.attendance.this-phone";

/**
 * Remember which registered phone this browser is: set when this browser
 * registers a phone or signs a punch with one. The server cannot tell, since
 * the employee's phone is the same answer on every browser; without this the
 * punch screen on a new phone said it was the registered one. Safari clears
 * storage after a week unused, so a missing mark means "not known", never
 * "not this phone".
 */
export function rememberThisPhone(publicId: string): void {
  try {
    window.localStorage.setItem(THIS_PHONE_KEY, publicId);
  } catch {
    // Storage blocked: the screen falls back to not knowing.
  }
  window.dispatchEvent(new Event(THIS_PHONE_KEY));
}

/** Subscribe to changes of the mark, for useSyncExternalStore. */
export function subscribeThisPhone(onChange: () => void): () => void {
  window.addEventListener(THIS_PHONE_KEY, onChange);
  return () => window.removeEventListener(THIS_PHONE_KEY, onChange);
}

/** The registered phone this browser was last seen to be, if known. */
export function thisPhone(): string | null {
  try {
    return window.localStorage.getItem(THIS_PHONE_KEY);
  } catch {
    return null;
  }
}

/** The browser refused to make a passkey because this phone already holds one of this person's. */
export function alreadyRegisteredHere(error: unknown): boolean {
  return (
    typeof error === "object" &&
    error !== null &&
    "code" in error &&
    (error as { code?: unknown }).code ===
      "ERROR_AUTHENTICATOR_PREVIOUSLY_REGISTERED"
  );
}

/** A coordinate rounded the way both sides hash it. */
export function fixedCoordinate(value: number): number {
  return Number(value.toFixed(6));
}

export interface OfflinePunchFields {
  idempotency_key: string;
  type: string;
  /** The punch time exactly as it will be sent (`timestamp` on the API). */
  captured_at: string;
  latitude?: number | null;
  longitude?: number | null;
}

/** The challenge an offline punch is signed over: sha256 of the grant nonce and the punch's fields. */
export async function offlineChallenge(
  nonce: string,
  fields: OfflinePunchFields,
): Promise<string> {
  const coordinate = (value?: number | null) =>
    typeof value === "number" && Number.isFinite(value) ? value.toFixed(6) : "";
  const text = [
    nonce,
    fields.idempotency_key,
    fields.type,
    fields.captured_at,
    coordinate(fields.latitude),
    coordinate(fields.longitude),
  ].join("|");
  const digest = await crypto.subtle.digest(
    "SHA-256",
    new TextEncoder().encode(text),
  );
  return toBase64Url(new Uint8Array(digest));
}

/** Sign an offline punch with the passkey the grant names. */
export async function signOfflinePunch(
  grant: { device_nonce: string; credential_id: string; rp_id: string },
  fields: OfflinePunchFields,
): Promise<DeviceProof> {
  return signWithPasskey({
    challenge: await offlineChallenge(grant.device_nonce, fields),
    rpId: grant.rp_id,
    allowCredentials: [{ id: grant.credential_id, type: "public-key" }],
    userVerification: "required",
    timeout: 60000,
  });
}

function toBase64Url(bytes: Uint8Array): string {
  let binary = "";
  for (const byte of bytes) binary += String.fromCharCode(byte);
  return btoa(binary)
    .replace(/\+/g, "-")
    .replace(/\//g, "_")
    .replace(/=+$/, "");
}
