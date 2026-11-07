import { createPinia } from 'pinia'
import { createApp } from 'vue'
import App from './App.vue'
import { installAuthHandlers } from './auth/prompts'
import { applyLocale, detectLocale, FALLBACK_LOCALE, i18n, loadLocaleMessages } from './i18n'
import vuetify from './plugins/vuetify'
import { createAppRouter } from './router'
import { installGuards } from './router/guards'

const router = createAppRouter()
installGuards(router)
installAuthHandlers()

const app = createApp(App)
  .use(createPinia())
  .use(i18n)
  .use(router)
  .use(vuetify)

// Mount once the active locale's messages are in, so the first paint is already translated. The fallback
// locale is fetched afterwards when it is not the active one; if a fetch fails the app still mounts.
void applyLocale(detectLocale(), false)
  .finally(() => {
    app.mount('#app')
    void loadLocaleMessages(FALLBACK_LOCALE).catch(() => {})
  })
