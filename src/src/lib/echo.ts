/* eslint-disable @typescript-eslint/no-explicit-any */

// laravel-echo and pusher-js are optional runtime dependencies loaded dynamically.
// Types are declared loosely to avoid tsc errors when they are not yet installed.

let echoInstance: any = null;

export function getEcho(): any {
  if (typeof window === "undefined") return null;
  return echoInstance;
}

export async function initEcho(token: string): Promise<any> {
  if (typeof window === "undefined") return null;

  const reverbHost = process.env.NEXT_PUBLIC_REVERB_HOST ?? "localhost";
  const reverbPort = parseInt(
    process.env.NEXT_PUBLIC_REVERB_PORT ?? "8080",
    10,
  );
  const reverbScheme = process.env.NEXT_PUBLIC_REVERB_SCHEME ?? "http";
  const appKey = process.env.NEXT_PUBLIC_REVERB_APP_KEY ?? "ethr-key";

  try {
    const [{ default: LaravelEcho }, { default: Pusher }] = await Promise.all([
      import("laravel-echo" as any),
      import("pusher-js" as any),
    ]);

    (window as any).Pusher = Pusher;

    echoInstance = new LaravelEcho({
      broadcaster: "reverb",
      key: appKey,
      wsHost: reverbHost,
      wsPort: reverbPort,
      wssPort: reverbPort,
      forceTLS: reverbScheme === "https",
      enabledTransports: ["ws", "wss"],
      authEndpoint: "/api/v1/broadcasting/auth",
      auth: {
        headers: { Authorization: `Bearer ${token}` },
      },
    });

    return echoInstance;
  } catch {
    return null;
  }
}

export function disconnectEcho(): void {
  if (echoInstance) {
    echoInstance.disconnect();
    echoInstance = null;
  }
}
