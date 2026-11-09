import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { i18n } from '../../../i18n'
import HeartbeatBar from '../HeartbeatBar.vue'

function render(props: InstanceType<typeof HeartbeatBar>['$props']) {
  return mount(HeartbeatBar, {
    props,
    global: {
      plugins: [i18n],
    },
  })
}

const beat = (status: 'up' | 'down', at: string) => ({ status, latency_ms: null, at })

describe('heartbeatBar', () => {
  it('keeps empty slots for a monitor that has not been checked yet', () => {
    const wrapper = render({ beats: [], slots: 10 })

    expect(wrapper.findAll('.beat--empty')).toHaveLength(10)
    expect(wrapper.find('[data-test="heartbeat-placeholder"]').exists()).toBe(false)
  })

  it('explains an unevaluated group instead of showing empty slots', () => {
    const wrapper = render({ beats: undefined, slots: 10, group: true })

    const placeholder = wrapper.find('[data-test="heartbeat-placeholder"]')
    expect(placeholder.exists()).toBe(true)
    expect(placeholder.text()).toBe(i18n.global.t('monitors.beats.groupNone'))
    expect(placeholder.attributes('title')).toBe(i18n.global.t('monitors.beats.groupNone'))
    expect(placeholder.attributes('aria-label')).toBe(i18n.global.t('monitors.beats.groupNone'))
    expect(wrapper.findAll('.beat')).toHaveLength(0)
  })

  it('keeps an empty group frame silent while its beats are loading', () => {
    const wrapper = render({
      beats: undefined,
      slots: 10,
      group: true,
      pending: true,
    })

    const placeholder = wrapper.find('[data-test="heartbeat-placeholder"]')
    expect(placeholder.exists()).toBe(true)
    expect(placeholder.text()).toBe('')
    expect(placeholder.attributes('aria-busy')).toBe('true')
  })

  it('draws a group\'s evaluations and labels them as such', () => {
    const wrapper = render({
      beats: [
        beat('up', '2026-11-09T08:00:00Z'),
        beat('down', '2026-11-09T08:01:00Z'),
      ],
      slots: 4,
      group: true,
    })

    expect(wrapper.find('[data-test="heartbeat-placeholder"]').exists()).toBe(false)
    expect(wrapper.findAll('.beat--empty')).toHaveLength(2)
    expect(wrapper.find('[role="img"]').attributes('aria-label')).toBe(
      i18n.global.t('monitors.beats.groupLabel', { count: 2, up: 1, down: 1, other: 0 }),
    )
  })
})
