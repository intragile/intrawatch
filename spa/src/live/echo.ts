import type Echo from 'laravel-echo'
import type { ChannelAuthorizationCallback } from 'pusher-js/types/src/core/auth/options'
import { ensureCsrf, readXsrfToken } from '../api'

const AUTH_ENDPOINT = '/broadcasting/auth'

interface ChannelRef {
  name: string
}

/** Authorises one private channel subscription with the session cookie and the CSRF header. */
async function authorize(socketId: string, channelName: string, callback: ChannelAuthorizationCallback): Promise<void> {
  try {
    await ensureCsrf()
    const token = readXsrfToken()
    const res = await globalThis.fetch(AUTH_ENDPOINT, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        ...(token
          ? { 'X-XSRF-TOKEN': token }
          : {}),
      },
      body: JSON.stringify({ socket_id: socketId, channel_name: channelName }),
    })
    if (!res.ok)
      throw new Error(`Channel authorisation failed with status ${res.status}`)
    callback(null, await res.json())
  }
  catch (error) {
    callback(error instanceof Error
      ? error
      : new Error(String(error)), null)
  }
}

/** Connection options for the Reverb server proxied on the page's own origin. */
export function buildEchoOptions(key: string) {
  const secure = location.protocol === 'https:'
  const port = location.port === ''
    ? undefined
    : Number(location.port)

  return {
    broadcaster: 'reverb' as const,
    key,
    wsHost: location.hostname,
    wsPort: port ?? 80,
    wssPort: port ?? 443,
    forceTLS: secure,
    enabledTransports: ['ws', 'wss'] as Array<'ws' | 'wss'>,
    authorizer: (channel: ChannelRef) => ({
      authorize: (socketId: string, callback: ChannelAuthorizationCallback) => {
        void authorize(socketId, channel.name, callback)
      },
    }),
  }
}

/** Creates the Echo client; opening it connects the socket immediately. The libraries load on first use. */
export async function createEcho(key: string): Promise<Echo<'reverb'>> {
  const [{ default: EchoClient }, { default: Pusher }] = await Promise.all([
    import('laravel-echo'),
    import('pusher-js'),
  ]);
  (globalThis as { Pusher?: unknown }).Pusher = Pusher
  return new EchoClient(buildEchoOptions(key) as never)
}
