import process from 'node:process'

/** The image under test; e2e/run.sh starts it and exports the URL. */
export const BASE_URL = process.env.E2E_BASE_URL ?? 'http://localhost:33300'

/** A custom status domain mapped to the same container, so the browser sends it as the Host header. */
export const CUSTOM_DOMAIN = 'status.e2e.test'
