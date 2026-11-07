import { createPinia } from 'pinia'
import { createApp } from 'vue'
import App from './App.vue'
import { installAuthHandlers } from './auth/prompts'
import { applyLocale, i18n } from './i18n'
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

// Mount once the active locale's messages are in, so the first paint is already translated. If they
// cannot be fetched, the app still mounts with the English fallback.
void applyLocale(i18n.global.locale.value, false).finally(() => app.mount('#app'))
