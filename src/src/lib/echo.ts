import type Echo from 'laravel-echo';

let echoInstance: Echo | null = null;

export function getEcho(): Echo | null {
  if (typeof window === 'undefined') return null;
  return echoInstance;
}

export async function initEcho(token: string): Promise<Echo | null> {
  if (typeof window === 'undefined') return null;

  const reverbHost = process.env.NEXT_PUBLIC_REVERB_HOST ?? 'localhost';
  const reverbPort = parseInt(process.env.NEXT_PUBLIC_REVERB_PORT ?? '8080', 10);
  const reverbScheme = process.env.NEXT_PUBLIC_REVERB_SCHEME ?? 'http';
  const appKey = process.env.NEXT_PUBLIC_REVERB_APP_KEY ?? 'ethr-key';

  try {
    const [{ default: LaravelEcho }, { default: Pusher }] = await Promise.all([
      import('laravel-echo'),
      import('pusher-js'),
    ]);

    // @ts-expect-error Pusher needs to be on window for Laravel Echo
    window.Pusher = Pusher;

    echoInstance = new LaravelEcho({
      broadcaster: 'reverb',
      key: appKey,
      wsHost: reverbHost,
      wsPort: reverbPort,
      wssPort: reverbPort,
      forceTLS: reverbScheme === 'https',
      enabledTransports: ['ws', 'wss'],
      authEndpoint: '/api/v1/broadcasting/auth',
      auth: {
        headers: {
          Authorization: `Bearer ${token}`,
        },
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
