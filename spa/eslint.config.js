import antfu from '@antfu/eslint-config'

export default antfu({
  vue: true,
  typescript: true,
  ignores: [
    'dist/**',
    'coverage/**',
    'test-results/**',
    'playwright-report/**',
  ],
})
