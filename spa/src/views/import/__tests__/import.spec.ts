import type { ImportReportData, ImportRun } from '../../../api/types'
import { File as NodeFile } from 'node:buffer'
import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import ConfirmDialog from '../../../components/common/ConfirmDialog.vue'
import { useSession } from '../../../stores/session'
import { abilities, me, meWith, viewerCan, workspace } from '../../../test/fixtures'
import { click, mountApp } from '../../../test/mount'
import { fakeApi, json, problem } from '../../../test/server'
import { choose, page } from '../../workspace/__tests__/helpers'

const ID = '01JBBBBBBBBBBBBBBBBBBBBBBB'

function report(overrides: Partial<ImportReportData> = {}): ImportReportData {
  return {
    source: { kind: 'sqlite', version: '2.x' },
    dry_run: true,
    started_at: '2026-01-01T10:00:00+00:00',
    finished_at: '2026-01-01T10:00:01+00:00',
    duration_ms: 1000,
    counts: {
      monitor: { would_create: 3, invalid: 1, skipped: 1 },
      tag: { would_create: 2, existing: 1 },
    },
    entries: [
      {
        entity: 'monitor',
        source_id: 1,
        name: 'Fine monitor',
        outcome: 'would_create',
        target_id: null,
        notes: [],
      },
      {
        entity: 'monitor',
        source_id: 2,
        name: 'Broken monitor',
        outcome: 'invalid',
        target_id: null,
        notes: [
          { code: 'x', message: 'Interval is below the minimum.' },
        ],
        errors: {
          interval: ['The interval must be at least 20.'],
        },
      },
      {
        entity: 'monitor',
        source_id: 3,
        name: 'Push monitor',
        outcome: 'skipped',
        target_id: null,
        notes: [
          { code: 'unsupported', message: 'Type is not supported.' },
        ],
      },
      {
        entity: 'tag',
        source_id: 4,
        name: 'Shared tag',
        outcome: 'existing',
        target_id: 9,
        notes: [],
      },
    ],
    not_migrated: {
      users: 2,
      api_keys: 0,
      docker_hosts: 1,
      remote_browsers: 0,
    },
    history: {
      monitors: 3,
      hour_rows: 120,
      day_rows: 30,
      notes: [],
    },
    notes: [
      { code: 'run', message: 'Run-level note from the server.' },
    ],
    ...overrides,
  }
}

function run(overrides: Partial<ImportRun> = {}): ImportRun {
  return {
    id: ID,
    status: 'analyzing',
    source: 'upload',
    file_name: 'kuma.db',
    file_size: 2048,
    options: { history: true, paused: false },
    report: null,
    error: null,
    created_at: '2026-01-01T10:00:00+00:00',
    started_at: null,
    finished_at: null,
    ...overrides,
  }
}

const SESSION = {
  'GET /setup': json({ required: false }),
  'GET /me': json({
    data: me(),
  }),
}

function setFile(selector: string, file: File): void {
  const input = document.querySelector<HTMLInputElement>(selector)
  if (!input)
    throw new Error(`No element matches ${selector}`)
  Object.defineProperty(input, 'files', {
    value: [file],
    configurable: true,
  })
  input.dispatchEvent(new Event('change', { bubbles: true }))
}

async function submitUpload(): Promise<void> {
  await click('[data-test="import-upload"]')
}

function setHidden(hidden: boolean): void {
  vi.spyOn(document, 'hidden', 'get').mockReturnValue(hidden)
}

/**
 * jsdom's FormData and File are not understood by the Request implementation of Node, which the fetch
 * stub runs on. The native FormData is taken from a parsed body; the native File comes from node:buffer.
 */
async function useNativeFormData(): Promise<void> {
  const parsed = await new Request('http://localhost/', {
    method: 'POST',
    body: 'a=b',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  }).formData()
  vi.stubGlobal('FormData', parsed.constructor)
  vi.stubGlobal('File', NodeFile)
}

afterEach(() => {
  vi.useRealTimers()
})

describe('import screen', () => {
  beforeEach(useNativeFormData)

  it('uploads multipart with options and starts polling', async () => {
    vi.useFakeTimers({
      toFake: ['setInterval', 'clearInterval'],
    })
    let posted: FormData | undefined
    let contentType: string | null = null
    const server = fakeApi({
      ...SESSION,
      'GET /workspaces/acme/imports': json(page([])),
      'POST /workspaces/acme/imports': async (req) => {
        contentType = req.headers.get('content-type')
        posted = await req.formData()
        return json({
          data: run(),
        }, 202)
      },
      'GET /workspaces/acme/imports/01JBBBBBBBBBBBBBBBBBBBBBBB': [
        json({
          data: run(),
        }),
        json({
          data: run({
            status: 'analyzed',
            report: report(),
          }),
        }),
      ],
    })
    await mountApp('/w/acme/import')
    await expect.poll(() => document.querySelector('input[name="import_file"]')).not.toBeNull()

    setFile('input[name="import_file"]', new File(['SQLite format 3\0'], 'kuma.db'))
    await click('input[name="import_paused"]')
    await submitUpload()

    await expect.poll(() => posted).toBeDefined()
    expect(contentType).toMatch(/^multipart\/form-data; boundary=/)
    expect((posted?.get('file') as File).name).toBe('kuma.db')
    expect(posted?.get('history')).toBe('1')
    expect(posted?.get('paused')).toBe('1')
    expect(server.fn.mock.calls.length).toBeGreaterThan(0)

    await vi.advanceTimersByTimeAsync(2000)
    await expect.poll(() => server.of('GET /workspaces/acme/imports/01JBBBBBBBBBBBBBBBBBBBBBBB').length).toBeGreaterThanOrEqual(1)
    await vi.advanceTimersByTimeAsync(2000)
    await expect.poll(() => document.querySelector('[data-test="import-run"]')).not.toBeNull()
  })

  it('rejects a file over max_upload_mb without a request', async () => {
    const server = fakeApi({
      ...SESSION,
      'GET /workspaces/acme/imports': json(page([])),
    })
    await mountApp('/w/acme/import')
    await expect.poll(() => document.querySelector('input[name="import_file"]')).not.toBeNull()

    const big = new File(['x'], 'huge.db')
    Object.defineProperty(big, 'size', { value: 257 * 1024 * 1024 })
    setFile('input[name="import_file"]', big)
    await submitUpload()
    await flushPromises()

    expect(document.body.textContent).toContain('256 MB')
    expect(server.of('POST /workspaces/acme/imports')).toHaveLength(0)
  })

  it('422 for an unknown file lands on the file field', async () => {
    fakeApi({
      ...SESSION,
      'GET /workspaces/acme/imports': json(page([])),
      'POST /workspaces/acme/imports': problem(422, {
        errors: {
          file: ['The file is not an Uptime Kuma export.'],
        },
      }),
    })
    await mountApp('/w/acme/import')
    await expect.poll(() => document.querySelector('input[name="import_file"]')).not.toBeNull()

    setFile('input[name="import_file"]', new File(['nope'], 'notes.txt'))
    await submitUpload()

    await expect.poll(() => document.body.textContent).toContain('The file is not an Uptime Kuma export.')
    expect(document.querySelector('[data-test="import-run"]')).toBeNull()
  })

  it('shows counts and filters entries by outcome', async () => {
    fakeApi({
      ...SESSION,
      'GET /workspaces/acme/imports': json(page([
        run({
          status: 'analyzed',
          report: report(),
        }),
      ])),
      'GET /workspaces/acme/imports/01JBBBBBBBBBBBBBBBBBBBBBBB': json({
        data: run({
          status: 'analyzed',
          report: report(),
        }),
      }),
    })
    const { wrapper } = await mountApp('/w/acme/import')
    await expect.poll(() => document.querySelector('[data-test="import-counts"]')).not.toBeNull()

    const counts = document.querySelector('[data-test="import-counts"]')?.textContent ?? ''
    expect(counts).toContain('Monitors')
    expect(counts).toContain('Would create')
    expect(document.querySelector('[data-test="count-monitor-would_create"]')?.textContent?.trim()).toBe('3')
    expect(document.querySelector('[data-test="count-monitor-invalid"]')?.textContent?.trim()).toBe('1')
    expect(document.body.textContent).toContain('Run-level note from the server.')
    expect(document.body.textContent).toContain('Docker hosts')

    // The default view hides what would simply be created.
    const entries = (): string => document.querySelector('[data-test="import-entries"]')?.textContent ?? ''
    expect(entries()).toContain('Broken monitor')
    expect(entries()).toContain('Interval is below the minimum.')
    expect(entries()).toContain('The interval must be at least 20.')
    expect(entries()).toContain('Push monitor')
    expect(entries()).not.toContain('Fine monitor')

    choose(wrapper, 'filter-outcome', 'would_create')
    await expect.poll(entries).toContain('Fine monitor')
    expect(entries()).not.toContain('Broken monitor')

    choose(wrapper, 'filter-outcome', 'all')
    choose(wrapper, 'filter-entity', 'tag')
    await expect.poll(entries).toContain('Shared tag')
    expect(entries()).not.toContain('Fine monitor')
  })

  it('run asks for confirmation and polls to completed', async () => {
    vi.useFakeTimers({
      toFake: ['setInterval', 'clearInterval'],
    })
    const analyzed = run({
      status: 'analyzed',
      report: report(),
    })
    const server = fakeApi({
      ...SESSION,
      'GET /workspaces/acme/imports': json(page([analyzed])),
      'GET /workspaces/acme/imports/01JBBBBBBBBBBBBBBBBBBBBBBB': [
        json({ data: analyzed }),
        json({
          data: run({
            status: 'running',
            report: report(),
          }),
        }),
        json({
          data: run({
            status: 'completed',
            report: report({ dry_run: false }),
          }),
        }),
      ],
      'POST /workspaces/acme/imports/01JBBBBBBBBBBBBBBBBBBBBBBB/run': json({
        data: run({
          status: 'running',
          report: report(),
        }),
      }, 202),
    })
    const { wrapper } = await mountApp('/w/acme/import')
    await expect.poll(() => document.querySelector('[data-test="import-run"]')).not.toBeNull()

    await click('[data-test="import-run"]')
    await expect.poll(() => document.body.textContent).toContain('3 monitors')
    expect(server.of('POST /workspaces/acme/imports/01JBBBBBBBBBBBBBBBBBBBBBBB/run')).toHaveLength(0)
    await click('[data-test="confirm"]')
    await expect.poll(() => server.of('POST /workspaces/acme/imports/01JBBBBBBBBBBBBBBBBBBBBBBB/run')).toHaveLength(1)

    await vi.advanceTimersByTimeAsync(2000)
    await vi.advanceTimersByTimeAsync(2000)
    await expect.poll(() => document.querySelector('[data-test="import-done"]')).not.toBeNull()
    expect(wrapper.html()).toContain('/monitors')
  })

  it('polling stops on leave and pauses while hidden', async () => {
    vi.useFakeTimers({
      toFake: ['setInterval', 'clearInterval'],
    })
    const server = fakeApi({
      ...SESSION,
      'GET /workspaces/acme/imports': json(page([
        run(),
      ])),
      'GET /workspaces/acme/imports/01JBBBBBBBBBBBBBBBBBBBBBBB': json({
        data: run(),
      }),
    })
    const { router } = await mountApp('/w/acme/import')
    const polls = (): number => server.of('GET /workspaces/acme/imports/01JBBBBBBBBBBBBBBBBBBBBBBB').length
    await expect.poll(polls).toBeGreaterThanOrEqual(1)

    setHidden(true)
    const before = polls()
    await vi.advanceTimersByTimeAsync(6000)
    expect(polls()).toBe(before)

    // Coming back refetches at once.
    setHidden(false)
    document.dispatchEvent(new Event('visibilitychange'))
    await expect.poll(polls).toBe(before + 1)

    await router.push('/w/acme/members')
    await flushPromises()
    const afterLeave = polls()
    await vi.advanceTimersByTimeAsync(10_000)
    expect(polls()).toBe(afterLeave)
  })

  it('viewer and editor do not see the nav item or the screen', async () => {
    for (const can of [
      viewerCan,
      abilities(false, { ...viewerCan, manageMonitors: true }),
    ]) {
      fakeApi({
        'GET /setup': json({ required: false }),
        'GET /me': json({
          data: me({
            workspaces: [
              workspace({ role: 'viewer', can }),
            ],
          }),
        }),
      })
      const { router } = await mountApp('/w/acme/import')
      await flushPromises()

      expect(document.body.textContent).toContain('You do not have access to this screen.')
      expect(document.querySelector('input[name="import_file"]')).toBeNull()
      expect(document.querySelector('a[href$="/w/acme/import"]')).toBeNull()
      expect(router.currentRoute.value.name).toBe('ws.import')
      document.body.innerHTML = ''
    }
  })

  it('shows the nav item to admins', async () => {
    fakeApi({
      ...SESSION,
      'GET /workspaces/acme/imports': json(page([])),
    })
    await mountApp('/w/acme/import')
    await expect.poll(() => document.querySelector('a[href$="/w/acme/import"]')).not.toBeNull()
  })

  it('drops the state of the previous workspace on a switch and loads the new one', async () => {
    const server = fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: meWith({ slug: 'acme' }, { slug: 'beta', name: 'Beta', id: '01JAAAAAAAAAAAAAAAAAAAAAAB' }),
      }),
      'GET /workspaces/acme/imports': json(page([
        run({
          status: 'completed',
          file_name: 'acme-kuma.db',
          report: report({ dry_run: false }),
        }),
      ])),
      'GET /workspaces/beta/imports': json(page([])),
    })
    const { router } = await mountApp('/w/acme/import')
    await expect.poll(() => document.body.textContent).toContain('acme-kuma.db')

    await router.push('/w/beta/import')
    await expect.poll(() => server.of('GET /workspaces/beta/imports')).toHaveLength(1)
    await expect.poll(() => document.body.textContent).not.toContain('acme-kuma.db')
    expect(document.querySelector('[data-test="import-status"]')).toBeNull()
  })

  it('stops polling and shows a notice when the import is gone', async () => {
    vi.useFakeTimers({
      toFake: ['setInterval', 'clearInterval'],
    })
    const server = fakeApi({
      ...SESSION,
      'GET /workspaces/acme/imports': json(page([
        run(),
      ])),
      'GET /workspaces/acme/imports/01JBBBBBBBBBBBBBBBBBBBBBBB': problem(404),
    })
    await mountApp('/w/acme/import')
    const polls = (): number => server.of('GET /workspaces/acme/imports/01JBBBBBBBBBBBBBBBBBBBBBBB').length
    await expect.poll(() => document.body.textContent).toContain('no longer exists')
    const seen = polls()
    await vi.advanceTimersByTimeAsync(10_000)
    expect(polls()).toBe(seen)
    expect(document.querySelector('[data-test="import-status"]')).toBeNull()
  })

  it('does not offer to run an analysis with nothing to create', async () => {
    const empty = run({
      status: 'analyzed',
      report: report({
        counts: {
          tag: { existing: 1 },
        },
      }),
    })
    const server = fakeApi({
      ...SESSION,
      'GET /workspaces/acme/imports': json(page([empty])),
      'GET /workspaces/acme/imports/01JBBBBBBBBBBBBBBBBBBBBBBB': json({ data: empty }),
    })
    await mountApp('/w/acme/import')
    await expect.poll(() => document.querySelector('[data-test="import-run"]')).not.toBeNull()
    await click('[data-test="import-run"]')
    await expect.poll(() => document.querySelector('[data-test="confirm"]')).not.toBeNull()

    expect(document.querySelector('[data-test="confirm"]')?.hasAttribute('disabled')).toBe(true)
    expect(server.of('POST /workspaces/acme/imports/01JBBBBBBBBBBBBBBBBBBBBBBB/run')).toHaveLength(0)
  })

  it('makes no import request for a viewer, also after switching workspaces', async () => {
    const viewer = { role: 'viewer' as const, can: viewerCan }
    const server = fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: meWith({ slug: 'acme', ...viewer }, { slug: 'beta', id: '01JAAAAAAAAAAAAAAAAAAAAAAB', ...viewer }),
      }),
    })
    const { router } = await mountApp('/w/acme/import')
    await flushPromises()
    await router.push('/w/beta/import')
    await flushPromises()

    expect(document.body.textContent).toContain('You do not have access to this screen.')
    expect(server.calls.filter(c => c.path.includes('/imports'))).toHaveLength(0)
  })

  it('stops loading and hides the screen when the permission is lost after mounting', async () => {
    const server = fakeApi({
      ...SESSION,
      'GET /workspaces/acme/imports': json(page([
        run({
          status: 'completed',
          file_name: 'kept.db',
          report: report({ dry_run: false }),
        }),
      ])),
    })
    await mountApp('/w/acme/import')
    await expect.poll(() => document.body.textContent).toContain('kept.db')
    const before = server.of('GET /workspaces/acme/imports').length

    const current = useSession().current
    if (!current)
      throw new Error('No current workspace')
    current.can.update = false
    await flushPromises()

    expect(document.body.textContent).toContain('You do not have access to this screen.')
    expect(document.body.textContent).not.toContain('kept.db')
    expect(document.querySelector('input[name="import_file"]')).toBeNull()
    expect(server.of('GET /workspaces/acme/imports')).toHaveLength(before)
  })

  it('clears errors and the run dialog of the previous workspace on a switch', async () => {
    const analyzed = run({
      status: 'analyzed',
      report: report(),
    })
    fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: meWith({ slug: 'acme' }, { slug: 'beta', id: '01JAAAAAAAAAAAAAAAAAAAAAAB' }),
      }),
      'GET /workspaces/acme/imports': json(page([analyzed])),
      'GET /workspaces/acme/imports/01JBBBBBBBBBBBBBBBBBBBBBBB': problem(500, { title: 'Acme show exploded' }),
      'GET /workspaces/beta/imports': json(page([])),
    })
    const { wrapper, router } = await mountApp('/w/acme/import')
    await expect.poll(() => document.body.textContent).toContain('Acme show exploded')
    await click('[data-test="import-run"]')
    // The dialog may linger for its leave transition, so its open state is read from the component.
    const runDialog = (): unknown => wrapper.findAllComponents(ConfirmDialog)
      .find(d => d.props('title') === 'Run this import?')
      ?.props('modelValue')
    await expect.poll(runDialog).toBe(true)

    await router.push('/w/beta/import')
    await expect.poll(runDialog).toBe(false)
    expect(document.body.textContent).not.toContain('Acme show exploded')
  })

  it('ignores an import list of the previous workspace that arrives after a switch', async () => {
    let release: (r: Response) => void = () => {}
    const late = new Promise<Response>((resolve) => {
      release = resolve
    })
    const server = fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: meWith({ slug: 'acme' }, { slug: 'beta', id: '01JAAAAAAAAAAAAAAAAAAAAAAB' }),
      }),
      'GET /workspaces/acme/imports': () => late,
      'GET /workspaces/beta/imports': json(page([])),
    })
    const { router } = await mountApp('/w/acme/import')
    await expect.poll(() => server.of('GET /workspaces/acme/imports')).toHaveLength(1)
    await router.push('/w/beta/import')
    await expect.poll(() => server.of('GET /workspaces/beta/imports')).toHaveLength(1)

    release(json(page([
      run({
        status: 'analyzed',
        file_name: 'late-acme.db',
        report: report(),
      }),
    ])))
    await flushPromises()
    await flushPromises()

    expect(document.body.textContent).not.toContain('late-acme.db')
    expect(document.querySelector('[data-test="import-status"]')).toBeNull()
  })

  it('ignores a poll answer of the previous workspace that arrives after a switch', async () => {
    vi.useFakeTimers({
      toFake: ['setInterval', 'clearInterval'],
    })
    let release: (r: Response) => void = () => {}
    const late = new Promise<Response>((resolve) => {
      release = resolve
    })
    const server = fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: meWith({ slug: 'acme' }, { slug: 'beta', id: '01JAAAAAAAAAAAAAAAAAAAAAAB' }),
      }),
      'GET /workspaces/acme/imports': json(page([
        run(),
      ])),
      'GET /workspaces/acme/imports/01JBBBBBBBBBBBBBBBBBBBBBBB': () => late,
      'GET /workspaces/beta/imports': json(page([])),
    })
    const { router } = await mountApp('/w/acme/import')
    await expect.poll(() => server.of('GET /workspaces/acme/imports/01JBBBBBBBBBBBBBBBBBBBBBBB')).toHaveLength(1)
    await router.push('/w/beta/import')
    await expect.poll(() => server.of('GET /workspaces/beta/imports')).toHaveLength(1)

    release(json({
      data: run({
        status: 'analyzed',
        file_name: 'late-acme.db',
        report: report(),
      }),
    }))
    await flushPromises()
    await flushPromises()

    expect(document.querySelector('[data-test="import-status"]')).toBeNull()
    expect(document.body.textContent).not.toContain('late-acme.db')
    expect(document.querySelector('[data-test="import-run"]')).toBeNull()
  })
})
