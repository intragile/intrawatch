import process from 'node:process'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'
import vuetify from 'vite-plugin-vuetify'

// The Laravel backend the dev server proxies to (`php artisan serve` by default).
const backend = process.env.VITE_BACKEND ?? 'http://127.0.0.1:8000'

export default defineConfig({
  base: '/admin/',
  // vue-i18n feature flags: Composition API only, no devtools in production.
  define: {
    __VUE_I18N_FULL_INSTALL__: true,
    __VUE_I18N_LEGACY_API__: false,
    __INTLIFY_PROD_DEVTOOLS__: false,
  },
  plugins: [
    vue(),
    vuetify({ autoImport: true }),
  ],
  build: {
    outDir: 'dist',
    assetsDir: 'assets',
    manifest: false,
    emptyOutDir: true,
  },
  server: {
    proxy: {
      '/api': { target: backend, changeOrigin: false },
      '/sanctum': { target: backend, changeOrigin: false },
      '/broadcasting': { target: backend, changeOrigin: false },
      '/app': { target: backend, ws: true, changeOrigin: false },
    },
  },
})
