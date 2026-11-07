import type { Component } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { h } from 'vue'
import { createMemoryHistory } from 'vue-router'
import { VApp } from 'vuetify/components'
import App from '../App.vue'
import { installAuthHandlers } from '../auth/prompts'
import { i18n } from '../i18n'
import vuetify from '../plugins/vuetify'
import { createAppRouter } from '../router'
import { installGuards } from '../router/guards'

/** Mounts the whole application (shell, guards, dialogs) at the given path. */
export async function mountApp(path: string) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = createAppRouter(createMemoryHistory())
  installGuards(router)
  installAuthHandlers()
  router.push(path)
  await router.isReady()
  const wrapper = mount(App, {
    attachTo: document.body,
    global: {
      plugins: [pinia, router, i18n, vuetify],
    },
  })
  return { wrapper, router, pinia }
}

/** Mounts a single component inside a `v-app` with the application plugins. */
export function mountComponent(component: Component) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = createAppRouter(createMemoryHistory())
  installAuthHandlers()
  const wrapper = mount({
    render: () => h(VApp, () => h(component)),
  }, {
    attachTo: document.body,
    global: {
      plugins: [pinia, router, i18n, vuetify],
    },
  })
  return { wrapper, router, pinia }
}

/** Sets an input's value and fires the events Vuetify listens to. */
export function fill(selector: string, value: string): void {
  const el = document.querySelector<HTMLInputElement>(selector)
  if (!el)
    throw new Error(`No element matches ${selector}`)
  el.value = value
  el.dispatchEvent(new Event('input', { bubbles: true }))
}

/** Clicks after pending renders have flushed, as a real user always would. */
export async function click(selector: string): Promise<void> {
  await flushPromises()
  const el = document.querySelector<HTMLElement>(selector)
  if (!el)
    throw new Error(`No element matches ${selector}`)
  el.click()
}

/** Submits the form that contains the matched element, after pending renders have flushed. */
export async function submit(selector: string): Promise<void> {
  await flushPromises()
  const el = document.querySelector<HTMLElement>(selector)
  const form = el instanceof HTMLFormElement
    ? el
    : el?.closest('form')
  if (!form)
    throw new Error(`No form for ${selector}`)
  form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
}
