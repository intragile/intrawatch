import { describe, expect, it } from 'vitest'
import { readRuntimeConfig } from './config'

function setMeta(content: string): void {
  const meta = document.createElement('meta')
  meta.name = 'intrawatch-config'
  meta.content = content
  document.head.append(meta)
}

describe('readRuntimeConfig', () => {
  it('reads the configuration injected by the server', () => {
    setMeta(JSON.stringify({
      version: '1.2.3',
      reverb: { key: 'abc' },
      locale: 'hu',
      import: { max_upload_mb: 64 },
    }))

    expect(readRuntimeConfig()).toEqual({
      version: '1.2.3',
      reverb: { key: 'abc' },
      locale: 'hu',
      import: { maxUploadMb: 64 },
    })
  })

  it('falls back to development defaults without the meta tag', () => {
    expect(readRuntimeConfig()).toEqual({
      version: 'dev',
      reverb: { key: import.meta.env.VITE_REVERB_KEY ?? '' },
      locale: 'en',
      import: { maxUploadMb: 256 },
    })
  })

  it('defaults the upload limit when the server omits or garbles it', () => {
    setMeta(JSON.stringify({
      version: '1',
      reverb: { key: '' },
      locale: 'en',
      import: { max_upload_mb: 'big' },
    }))

    expect(readRuntimeConfig().import.maxUploadMb).toBe(256)
  })

  it('falls back on invalid JSON', () => {
    setMeta('{not json')

    expect(readRuntimeConfig().version).toBe('dev')
  })

  it('falls back when the shape is wrong', () => {
    setMeta(JSON.stringify({ version: 3, reverb: null, locale: 'en' }))

    expect(readRuntimeConfig().version).toBe('dev')
  })
})
