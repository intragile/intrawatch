import type { AlertRaisedEvent, ChecksEvent, LiveEvents, StatusChangedEvent } from './events'
import { defineStore } from 'pinia'
import { onScopeDispose, ref, watch } from 'vue'
import { readRuntimeConfig } from '../config'
import { useMonitors } from '../stores/monitors'
import { useSession } from '../stores/session'
import { createEcho } from './echo'

export type LiveState = 'connecting' | 'live' | 'offline'

/** A tab hidden at least this long resynchronises when it becomes visible again. */
export const RESYNC_HIDDEN_MS = 30_000

/** The slice of an Echo channel the live layer relies on. */
interface ChannelLike {
  listen: (event: string, cb: (payload: never) => void) => unknown
  error?: (cb: () => void) => unknown
}

/** The slice of the Echo client the live layer relies on. */
export interface EchoLike {
  private: (channel: string) => ChannelLike
  leave: (channel: string) => void
  disconnect: () => void
  connector: {
    pusher: {
      connection: {
        state: string
        bind: (event: string, cb: (change: { previous?: string, current: string }) => void) => void
        unbind: (event: string, cb: (change: { previous?: string, current: string }) => void) => void
      }
    }
  }
}

type EchoFactory = () => EchoLike | Promise<EchoLike>

let factory: EchoFactory = () => createEcho(readRuntimeConfig().reverb.key) as unknown as Promise<EchoLike>

/** Replaces how the Echo client is created (tests inject a fake instead of opening a socket). */
export function setEchoFactory(next: EchoFactory): void {
  factory = next
}

function stateOf(pusherState: string): LiveState {
  if (pusherState === 'connected')
    return 'live'
  return pusherState === 'connecting' || pusherState === 'initialized'
    ? 'connecting'
    : 'offline'
}

/**
 * The workspace's live channel: follows the current workspace, exposes the connection state, feeds
 * the monitor store and relays events to views. Call it once at the application root to start it.
 */
export const useWorkspaceLive = defineStore('workspaceLive', () => {
  const session = useSession()
  const monitors = useMonitors()
  const state = ref<LiveState>('offline')

  const listeners: { [K in keyof LiveEvents]: Array<(e: LiveEvents[K]) => void> } = {
    status_changed: [],
    checks: [],
    alert: [],
  }
  const resyncListeners: Array<() => void> = []

  let echo: EchoLike | null = null
  let channelName: string | null = null
  let dropped = false
  let hiddenAt: number | null = null
  /** Bumped on every channel change so a client still loading for an old one is discarded. */
  let generation = 0

  function on<K extends keyof LiveEvents>(event: K, cb: (e: LiveEvents[K]) => void): () => void {
    const list = listeners[event]
    list.push(cb)
    return () => {
      const i = list.indexOf(cb)
      if (i >= 0)
        list.splice(i, 1)
    }
  }

  function onResync(cb: () => void): () => void {
    resyncListeners.push(cb)
    return () => {
      const i = resyncListeners.indexOf(cb)
      if (i >= 0)
        resyncListeners.splice(i, 1)
    }
  }

  function emit<K extends keyof LiveEvents>(event: K, payload: LiveEvents[K]): void {
    for (const cb of [...listeners[event]])
      cb(payload)
  }

  function resync(): void {
    for (const cb of [...resyncListeners])
      cb()
  }

  function onConnection(change: { previous?: string, current: string }): void {
    const next = stateOf(change.current)
    // pusher-js retries a lost link while still reporting connected, then goes through connecting;
    // leaving connected for any reason can lose events, whether or not it ever reaches unavailable.
    if (next === 'offline' || (change.previous === 'connected' && change.current !== 'connected'))
      dropped = true
    if (next === 'live' && dropped) {
      dropped = false
      state.value = next
      resync()
      return
    }
    state.value = next
  }

  function leave(): void {
    if (echo && channelName)
      echo.leave(channelName)
    channelName = null
  }

  function stop(): void {
    generation++
    leave()
    if (echo) {
      echo.connector.pusher.connection.unbind('state_change', onConnection)
      echo.disconnect()
    }
    echo = null
    dropped = false
    state.value = 'offline'
  }

  async function subscribe(name: string): Promise<void> {
    const mine = generation
    if (!echo) {
      state.value = 'connecting'
      let created: EchoLike
      try {
        created = await factory()
      }
      catch {
        if (mine === generation)
          state.value = 'offline'
        return
      }
      if (mine !== generation) {
        created.disconnect()
        return
      }
      echo = created
      echo.connector.pusher.connection.bind('state_change', onConnection)
    }
    channelName = name
    state.value = stateOf(echo.connector.pusher.connection.state)

    const channel = echo.private(name)
    channel.listen('.monitor.status_changed', ((e: StatusChangedEvent) => {
      monitors.applyStatus(e)
      emit('status_changed', e)
    }) as (payload: never) => void)
    channel.listen('.monitor.checks', ((e: ChecksEvent) => {
      monitors.applyChecks(e)
      emit('checks', e)
    }) as (payload: never) => void)
    channel.listen('.alert.raised', ((e: AlertRaisedEvent) => emit('alert', e)) as (payload: never) => void)
    channel.error?.(() => {
      state.value = 'offline'
    })
  }

  watch(() => session.current?.channel ?? null, (name) => {
    generation++
    leave()
    // Loaded monitors belong to the workspace and user that were current.
    monitors.clear()
    if (name === null)
      stop()
    else
      void subscribe(name)
  }, { immediate: true })

  if (typeof document !== 'undefined') {
    const onVisibility = () => {
      if (document.visibilityState === 'hidden') {
        hiddenAt ??= Date.now()
        return
      }
      const since = hiddenAt
      hiddenAt = null
      if (since !== null && Date.now() - since >= RESYNC_HIDDEN_MS && echo)
        resync()
    }
    document.addEventListener('visibilitychange', onVisibility)
    onScopeDispose(() => {
      document.removeEventListener('visibilitychange', onVisibility)
      stop()
    })
  }

  return { state, on, onResync }
})
