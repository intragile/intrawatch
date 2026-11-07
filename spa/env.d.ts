/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_REVERB_KEY?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
