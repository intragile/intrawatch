import { describe, expect, it } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import { api, ApiError } from '../../../api'
import { useSession } from '../../../stores/session'
import { me } from '../../../test/fixtures'
import { click, fill, mountComponent, submit } from '../../../test/mount'
import { fakeApi, json, problem } from '../../../test/server'
import ReloginDialog from '../ReloginDialog.vue'

const DummyForm = defineComponent({
  setup() {
    const name = ref('')
    const saved = ref(false)
    async function save() {
      await api.POST('/workspaces', {
        body: { name: name.value },
      })
      saved.value = true
    }
    return () => h('form', {
      onSubmit: (e: Event) => {
        e.preventDefault()
        void save()
      },
    }, [
      h('input', {
        'name': 'workspace',
        'value': name.value,
        'onInput': (e: Event) => { name.value = (e.target as HTMLInputElement).value },
        'aria-label': 'Workspace',
      }),
      saved.value
        ? h('p', { 'data-test': 'saved' }, 'saved')
        : null,
    ])
  },
})

const Host = defineComponent({
  setup() {
    return () => [
      h(DummyForm),
      h(ReloginDialog),
    ]
  },
})

describe('re-login dialog', () => {
  it('401 during save keeps form state and retries after re-login', async () => {
    const server = fakeApi({
      'POST /workspaces': [
        problem(401),
        json({
          data: { slug: 'second' },
        }, 201),
      ],
      'POST /auth/login': json({ two_factor: true }),
      'POST /auth/two-factor-challenge': json(null, 204),
      'GET /me': json({
        data: me(),
      }),
    })
    mountComponent(Host)
    useSession().me = me()

    fill('input[name="workspace"]', 'Second workspace')
    await submit('input[name="workspace"]')

    await expect.poll(() => document.querySelector('input[name="relogin_password"]')).not.toBeNull()
    expect(document.querySelector<HTMLInputElement>('input[name="relogin_email"]')?.value).toBe('ada@example.com')
    fill('input[name="relogin_password"]', 'correct horse battery')
    await submit('input[name="relogin_password"]')

    await expect.poll(() => document.querySelector('input[name="code"]')).not.toBeNull()
    fill('input[name="code"]', '123456')
    await submit('input[name="code"]')

    await expect.poll(() => document.querySelector('[data-test="saved"]')).not.toBeNull()
    const posts = server.of('POST /workspaces')
    expect(posts).toHaveLength(2)
    expect(posts[1]?.body).toEqual(posts[0]?.body)
    expect(posts[0]?.body).toEqual({ name: 'Second workspace' })
    expect(document.querySelector<HTMLInputElement>('input[name="workspace"]')?.value).toBe('Second workspace')
    expect(server.of('POST /auth/two-factor-challenge')[0]?.body).toEqual({ code: '123456' })
  })
})

describe('re-login prompt', () => {
  it('concurrent 401s share one dialog and one login', async () => {
    const server = fakeApi({
      'GET /workspaces': [
        problem(401),
        json({ data: [] }),
      ],
      'GET /workspaces/acme/members': [
        problem(401),
        json({
          data: [],
          meta: { next_cursor: null, limit: 50 },
        }),
      ],
      'POST /auth/login': json({ two_factor: false }),
      'GET /me': json({
        data: me(),
      }),
    })
    mountComponent(ReloginDialog)
    useSession().me = me()

    const first = api.GET('/workspaces')
    const second = api.GET('/workspaces/{workspace}/members', {
      params: {
        path: { workspace: 'acme' },
      },
    })

    await expect.poll(() => document.querySelector('input[name="relogin_password"]')).not.toBeNull()
    expect(document.querySelectorAll('input[name="relogin_password"]')).toHaveLength(1)
    fill('input[name="relogin_password"]', 'correct horse battery')
    await submit('input[name="relogin_password"]')

    await Promise.all([first, second])
    expect(server.of('POST /auth/login')).toHaveLength(1)
    expect(server.of('GET /workspaces')).toHaveLength(2)
    expect(server.of('GET /workspaces/acme/members')).toHaveLength(2)
  })

  it('cancelling rejects with the original ApiError and clears a pending challenge', async () => {
    fakeApi({
      'GET /workspaces': problem(401, { title: 'Unauthenticated.' }),
      'POST /auth/login': json({ two_factor: true }),
    })
    mountComponent(ReloginDialog)
    const session = useSession()
    session.me = me()

    const pending = api.GET('/workspaces')
    const outcome = pending.then(() => null, (e: unknown) => e)

    await expect.poll(() => document.querySelector('input[name="relogin_password"]')).not.toBeNull()
    fill('input[name="relogin_password"]', 'correct horse battery')
    await submit('input[name="relogin_password"]')
    await expect.poll(() => session.twoFactorPending).toBe(true)

    await click('[data-test="relogin-cancel"]')

    const error = await outcome
    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).status).toBe(401)
    expect((error as ApiError).title).toBe('Unauthenticated.')
    expect(session.twoFactorPending).toBe(false)
  })
})
