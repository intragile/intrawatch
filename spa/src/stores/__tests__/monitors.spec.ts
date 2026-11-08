import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { monitor } from '../../test/fixtures'
import { useMonitors } from '../monitors'

function installFrames() {
  const frames: FrameRequestCallback[] = []
  vi.stubGlobal('requestAnimationFrame', (cb: FrameRequestCallback) => frames.push(cb))
  return {
    count: () => frames.length,
    run: () => frames.splice(0).forEach(cb => cb(0)),
  }
}

describe('monitors store', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('applies 1000 events in one frame and ignores unknown ids', () => {
    const raf = installFrames()
    const store = useMonitors()
    for (let id = 1; id <= 100; id++)
      store.upsert(monitor({ id }))

    for (let i = 0; i < 1000; i++) {
      const id = i + 1
      store.applyStatus({
        monitor_id: id,
        from: 'up',
        to: 'down',
        at: '2026-01-01T00:00:00Z',
        previous_since: '2025-12-31T00:00:00Z',
        message: `m${id}`,
      })
      store.applyChecks({
        checks: [
          {
            monitor_id: id,
            status: 'down',
            latency_ms: id,
            checked_at: '2026-01-01T00:00:01Z',
          },
        ],
      })
    }

    expect(raf.count()).toBe(1)
    expect(store.items.get(1)?.state.status).toBe('up')

    raf.run()

    expect(store.items.get(1)?.state).toMatchObject({
      status: 'down',
      since: '2026-01-01T00:00:00Z',
      last_message: 'm1',
      last_checked_at: '2026-01-01T00:00:01Z',
      last_latency_ms: 1,
    })
    expect(store.items.get(100)?.state.status).toBe('down')
    expect(store.items.size).toBe(100)
    expect(store.items.has(101)).toBe(false)
    expect(store.beats.has(101)).toBe(false)
    expect(store.beats.size).toBe(100)
    expect(raf.count()).toBe(0)
  })

  it('beats are capped at 50', () => {
    const raf = installFrames()
    const store = useMonitors()
    store.upsert(monitor({ id: 7 }))

    store.applyChecks({
      checks: Array.from({ length: 80 }, (_, i) => ({
        monitor_id: 7,
        status: 'up' as const,
        latency_ms: i,
        checked_at: `t${i}`,
      })),
    })
    raf.run()
    store.applyChecks({
      checks: [
        {
          monitor_id: 7,
          status: 'down',
          latency_ms: 999,
          checked_at: 'last',
        },
      ],
    })
    raf.run()

    const beats = store.beats.get(7) ?? []
    expect(beats).toHaveLength(50)
    expect(beats.at(-1)).toEqual({ status: 'down', latency_ms: 999, at: 'last' })
    expect(beats[0]?.latency_ms).toBe(31)
  })

  it('remove drops the monitor and its beats', () => {
    const raf = installFrames()
    const store = useMonitors()
    store.upsert(monitor({ id: 3 }))
    store.applyChecks({
      checks: [
        {
          monitor_id: 3,
          status: 'up',
          latency_ms: 1,
          checked_at: 't',
        },
      ],
    })
    raf.run()
    store.remove(3)
    expect(store.items.has(3)).toBe(false)
    expect(store.beats.has(3)).toBe(false)
  })
  it('seeds a strip from the list and keeps live beats that are newer', () => {
    const store = useMonitors()
    store.upsert(monitor({ id: 4 }))
    store.beats.set(4, [
      { status: 'up', latency_ms: 10, at: '2026-01-01T00:00:00Z' },
      { status: 'down', latency_ms: null, at: '2026-01-01T00:03:00Z' },
    ])

    store.seedBeats(4, [
      { status: 'up', latency_ms: 11, at: '2026-01-01T00:01:00+00:00' },
      { status: 'degraded', latency_ms: 900, at: '2026-01-01T00:02:00+00:00' },
    ])

    expect(store.beats.get(4)?.map(b => b.status)).toEqual(['up', 'degraded', 'down'])
  })
})
