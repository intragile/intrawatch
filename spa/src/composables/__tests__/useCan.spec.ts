import { describe, expect, it } from 'vitest'
import { defineComponent, h, nextTick } from 'vue'
import { useSession } from '../../stores/session'
import { me, viewerCan, workspace } from '../../test/fixtures'
import { mountComponent } from '../../test/mount'
import { fakeApi, json } from '../../test/server'
import { useCan } from '../useCan'

const Toolbar = defineComponent({
  setup() {
    const can = useCan()
    return () => h('div', [
      h('span', { 'data-test': 'list' }, 'monitors'),
      can('manageMonitors')
        ? h('button', { 'data-test': 'create' }, 'New monitor')
        : null,
    ])
  },
})

describe('useCan', () => {
  it('useCan hides write actions for viewer', async () => {
    mountComponent(Toolbar)
    const session = useSession()
    session.me = me({
      workspaces: [
        workspace({ role: 'viewer', can: viewerCan }),
      ],
    })
    session.currentSlug = 'acme'
    await nextTick()

    expect(document.querySelector('[data-test="list"]')).not.toBeNull()
    expect(document.querySelector('[data-test="create"]')).toBeNull()
  })

  it('a role change picked up by the next refresh updates the controls', async () => {
    fakeApi({
      'GET /me': json({
        data: me({
          workspaces: [
            workspace({ role: 'viewer', can: viewerCan }),
          ],
        }),
      }),
    })
    mountComponent(Toolbar)
    const session = useSession()
    session.me = me()
    session.currentSlug = 'acme'
    await nextTick()
    expect(document.querySelector('[data-test="create"]')).not.toBeNull()

    await session.load()
    await nextTick()
    expect(document.querySelector('[data-test="create"]')).toBeNull()
  })

  it('is false without a current workspace', () => {
    mountComponent(Toolbar)
    useSession().me = me()
    expect(document.querySelector('[data-test="create"]')).toBeNull()
  })
})
