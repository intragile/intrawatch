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
applyLocale(i18n.global.locale.value, false)

createApp(App)
  .use(createPinia())
  .use(i18n)
  .use(router)
  .use(vuetify)
  .mount('#app')
