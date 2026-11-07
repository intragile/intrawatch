import { describe, expect, it } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import { api } from '../../../api'
import { useSession } from '../../../stores/session'
import { me } from '../../../test/fixtures'
import { fill, mountComponent, submit } from '../../../test/mount'
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
