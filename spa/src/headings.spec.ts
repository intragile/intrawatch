import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

/** Every .vue file below the given directory. */
function vueFiles(dir: string): string[] {
  return readdirSync(dir, { withFileTypes: true }).flatMap(entry => entry.isDirectory()
    ? vueFiles(join(dir, entry.name))
    : entry.name.endsWith('.vue')
      ? [
          join(dir, entry.name),
        ]
      : [])
}

describe('card title headings', () => {
  it('card titles choose their heading element with tag, not as', () => {
    // Vuetify's VCardTitle takes `tag`; an `as` attribute is passed through and leaves a plain div.
    const offenders = vueFiles(__dirname)
      .filter(file => /<v-card-title\b[^>]*\sas="/.test(readFileSync(file, 'utf8')))
      .map(file => file.slice(__dirname.length + 1))

    expect(offenders).toEqual([])
  })
})
