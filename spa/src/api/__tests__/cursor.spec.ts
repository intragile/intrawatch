import { describe, expect, it, vi } from 'vitest'
import { useCursorList } from '..'

interface Page { data: number[], meta: { next_cursor: string | null } }

function deferred<T>() {
  let resolve!: (v: T) => void
  const promise = new Promise<T>(r => (resolve = r))
  return { promise, resolve }
}

const pages: Record<string, Page> = {
  start: {
    data: [1, 2],
    meta: { next_cursor: 'p2' },
  },
  p2: {
    data: [3, 4],
    meta: { next_cursor: 'p3' },
  },
  p3: {
    data: [5],
    meta: { next_cursor: null },
  },
}

describe('useCursorList', () => {
  it('cursor list appends pages, stops at null cursor, collapses concurrent loadMore', async () => {
    const fetchPage = vi.fn(async (cursor: string | null) => pages[cursor ?? 'start']!)
    const list = useCursorList(fetchPage)

    await Promise.all([
      list.loadMore(),
      list.loadMore(),
    ])
    expect(fetchPage).toHaveBeenCalledTimes(1)
    expect(list.items.value).toEqual([1, 2])
    expect(list.hasMore.value).toBe(true)

    await list.loadMore()
    await list.loadMore()
    expect(list.items.value).toEqual([1, 2, 3, 4, 5])
    expect(list.hasMore.value).toBe(false)

    await list.loadMore()
    expect(fetchPage).toHaveBeenCalledTimes(3)
    expect(list.loading.value).toBe(false)
  })

  it('reload discards a stale in-flight page', async () => {
    const stale = deferred<Page>()
    const fetchPage = vi.fn()
      .mockReturnValueOnce(stale.promise)
      .mockResolvedValueOnce({
        data: [9],
        meta: { next_cursor: null },
      })
    const list = useCursorList<number>(fetchPage)

    const first = list.loadMore()
    await list.reload()
    stale.resolve({
      data: [1, 2],
      meta: { next_cursor: 'p2' },
    })
    await first

    expect(list.items.value).toEqual([9])
    expect(list.hasMore.value).toBe(false)
    expect(list.loading.value).toBe(false)
  })

  it('reloadLoaded refetches the same number of pages', async () => {
    let version = 1
    const fetchPage = vi.fn(async (cursor: string | null) => {
      const p = pages[cursor ?? 'start']!
      return {
        data: p.data.map(n => n * version),
        meta: p.meta,
      }
    })
    const list = useCursorList(fetchPage)
    await list.loadMore()
    await list.loadMore()
    fetchPage.mockClear()

    version = 10
    await list.reloadLoaded()
    expect(fetchPage.mock.calls.map(c => c[0])).toEqual([null, 'p2'])
    expect(list.items.value).toEqual([10, 20, 30, 40])
    expect(list.hasMore.value).toBe(true)
  })

  it('records an ApiError and keeps loaded items', async () => {
    const boom = new Error('x')
    const fetchPage = vi.fn()
      .mockResolvedValueOnce(pages.start)
      .mockRejectedValueOnce(boom)
    const list = useCursorList<number>(fetchPage)
    await list.loadMore()
    await list.loadMore()
    expect(list.items.value).toEqual([1, 2])
    expect(list.error.value).toBeTruthy()
    expect(list.hasMore.value).toBe(true)
  })

  it('loadMore during reloadLoaded collapses into it', async () => {
    const gate = deferred<Page>()
    const fetchPage = vi.fn(async (cursor: string | null) => pages[cursor ?? 'start']!)
    const list = useCursorList<number>(fetchPage)
    await list.loadMore()
    await list.loadMore()
    fetchPage.mockClear()
    fetchPage.mockImplementationOnce(() => gate.promise)

    const reloading = list.reloadLoaded()
    const more = list.loadMore()
    expect(fetchPage).toHaveBeenCalledTimes(1)
    gate.resolve(pages.start!)
    await Promise.all([reloading, more])

    expect(list.items.value).toEqual([1, 2, 3, 4])
    expect(list.loading.value).toBe(false)
    await list.loadMore()
    expect(list.items.value).toEqual([1, 2, 3, 4, 5])
  })
})
