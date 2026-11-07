import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useMonitors } from '../../stores/monitors'
import { useSession } from '../../stores/session'
import { meWith, monitor } from '../../test/fixtures'
import { buildEchoOptions } from '../echo'
import { RESYNC_HIDDEN_MS, setEchoFactory, useWorkspaceLive } from '../useWorkspaceLive'

type Handler = (payload: unknown) => void

class FakeChannel {
  handlers = new Map<string, Handler>()
  listen(event: string, cb: Handler) {
    this.handlers.set(event, cb)
    return this
  }

  error() {
    return this
  }
}

class FakeEcho {
  channels = new Map<string, FakeChannel>()
  left: string[] = []
  disconnected = false
  stateHandlers: Array<(change: { previous?: string, current: string }) => void> = []
  connector = {
    pusher: {
      connection: {
        state: 'connecting',
        bind: (_event: string, cb: (change: { previous?: string, current: string }) => void) => {
          this.stateHandlers.push(cb)
        },
        unbind: () => {},
      },
    },
  }

  private(name: string) {
    const channel = new FakeChannel()
    this.channels.set(name, channel)
    return channel
  }

  leave(name: string) {
    this.left.push(name)
  }

  disconnect() {
    this.disconnected = true
  }

  setState(current: string) {
    const previous = this.connector.pusher.connection.state
    this.connector.pusher.connection.state = current
    this.stateHandlers.forEach(h => h({ previous, current }))
  }
}

function setup(channel: string | null = 'workspace.12') {
  setActivePinia(createPinia())
  const echo = new FakeEcho()
  setEchoFactory(async () => echo as never)
  const session = useSession()
  session.me = meWith({ slug: 'acme', channel }, { slug: 'beta', channel: 'workspace.99' })
  session.currentSlug = 'acme'
  const live = useWorkspaceLive()
  return { echo, session, live }
}

describe('workspace live', () => {
  beforeEach(() => {
    vi.stubGlobal('requestAnimationFrame', () => 1)
  })

  it('subscribes to the channel from the workspace payload and leaves on switch', async () => {
    const { echo, session, live } = setup()
    await flushPromises()

    expect([
      ...echo.channels.keys(),
    ]).toEqual(['workspace.12'])
    expect(live.state).toBe('connecting')
    echo.setState('connected')
    expect(live.state).toBe('live')

    session.currentSlug = 'beta'
    await flushPromises()
    expect(echo.left).toContain('workspace.12')
    expect([
      ...echo.channels.keys(),
    ]).toEqual(['workspace.12', 'workspace.99'])

    session.currentSlug = null
    await flushPromises()
    expect(echo.left).toContain('workspace.99')
    expect(live.state).toBe('offline')
  })

  it('resyncs after a short reconnect that never reaches unavailable', async () => {
    const { echo, live } = setup()
    await flushPromises()
    const resync = vi.fn()
    live.onResync(resync)

    echo.setState('connected')
    expect(resync).not.toHaveBeenCalled()
    echo.setState('connecting')
    expect(live.state).toBe('connecting')
    echo.setState('connected')
    expect(resync).toHaveBeenCalledTimes(1)
  })

  it('discards a client that finishes loading after the channel changed', async () => {
    setActivePinia(createPinia())
    const echo = new FakeEcho()
    let release: (e: never) => void = () => {}
    setEchoFactory(() => new Promise((resolve) => {
      release = resolve as (e: never) => void
    }))
    const session = useSession()
    session.me = meWith({ slug: 'acme', channel: 'workspace.12' })
    session.currentSlug = 'acme'
    const live = useWorkspaceLive()
    session.currentSlug = null
    await flushPromises()
    release(echo as never)
    await flushPromises()

    expect(echo.disconnected).toBe(true)
    expect(echo.channels.size).toBe(0)
    expect(live.state).toBe('offline')
  })

  it('clears loaded monitors on workspace switch and sign-out', async () => {
    const { session } = setup()
    await flushPromises()
    const store = useMonitors()
    store.upsert(monitor({ id: 1 }))
    session.currentSlug = 'beta'
    await flushPromises()
    expect(store.items.size).toBe(0)

    store.upsert(monitor({ id: 2 }))
    session.me = null
    await flushPromises()
    expect(store.items.size).toBe(0)
  })

  it('stays offline and never subscribes without a channel', async () => {
    const { echo, live } = setup(null)
    await flushPromises()
    expect(echo.channels.size).toBe(0)
    expect(live.state).toBe('offline')
  })

  it('dispatches broadcast events to listeners', async () => {
    const { echo, live } = setup()
    await flushPromises()
    const seen: unknown[] = []
    live.on('alert', e => seen.push(e))
    echo.channels.get('workspace.12')?.handlers.get('.alert.raised')?.({
      alert_event_ids: [1],
    })
    expect(seen).toEqual([
      {
        alert_event_ids: [1],
      },
    ])
  })

  it('resync fires after reconnect and after long hidden tab', async () => {
    vi.useFakeTimers()
    try {
      const { echo, live } = setup()
      await flushPromises()
      const resync = vi.fn()
      live.onResync(resync)

      echo.setState('connected')
      expect(resync).not.toHaveBeenCalled()

      echo.setState('unavailable')
      expect(live.state).toBe('offline')
      echo.setState('connected')
      expect(resync).toHaveBeenCalledTimes(1)

      const visibility = vi.spyOn(document, 'visibilityState', 'get')
      visibility.mockReturnValue('hidden')
      document.dispatchEvent(new Event('visibilitychange'))
      vi.advanceTimersByTime(RESYNC_HIDDEN_MS - 1000)
      visibility.mockReturnValue('visible')
      document.dispatchEvent(new Event('visibilitychange'))
      expect(resync).toHaveBeenCalledTimes(1)

      visibility.mockReturnValue('hidden')
      document.dispatchEvent(new Event('visibilitychange'))
      vi.advanceTimersByTime(RESYNC_HIDDEN_MS)
      visibility.mockReturnValue('visible')
      document.dispatchEvent(new Event('visibilitychange'))
      expect(resync).toHaveBeenCalledTimes(2)
    }
    finally {
      vi.useRealTimers()
    }
  })

  it('authorizer sends XSRF and session cookie', async () => {
    document.cookie = 'XSRF-TOKEN=tok%3D1'
    const fetchMock = vi.fn(async (url: string, _init?: RequestInit) => url === '/sanctum/csrf-cookie'
      ? new Response(null, { status: 204 })
      : Response.json({ auth: 'key:sig' }))
    vi.stubGlobal('fetch', fetchMock)

    const options = buildEchoOptions('abc')
    expect(options).toMatchObject({
      broadcaster: 'reverb',
      key: 'abc',
      wsHost: location.hostname,
      enabledTransports: ['ws', 'wss'],
      forceTLS: false,
    })

    const auth = await new Promise<{ error: Error | null, data: unknown }>((resolve) => {
      options.authorizer({ name: 'private-workspace.12' })
        .authorize('1.2', (error, data) => resolve({ error, data }))
    })

    expect(auth).toEqual({
      error: null,
      data: { auth: 'key:sig' },
    })
    const call = fetchMock.mock.calls.find(c => c[0] === '/broadcasting/auth')
    const init = call?.[1] as RequestInit
    expect(init.method).toBe('POST')
    expect(init.credentials).toBe('same-origin')
    expect((init.headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('tok=1')
    expect(JSON.parse(init.body as string)).toEqual({ socket_id: '1.2', channel_name: 'private-workspace.12' })
  })
})
