// Fails when src/api/schema.d.ts is out of date with ../docs/openapi.json.
import { spawnSync } from 'node:child_process'
import { mkdtempSync, readFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import process from 'node:process'

const committedPath = 'src/api/schema.d.ts'
const dir = mkdtempSync(join(tmpdir(), 'api-check-'))
const generatedPath = join(dir, 'schema.d.ts')

try {
  const run = spawnSync(
    'npx',
    [
      '--no-install',
      'openapi-typescript',
      '../docs/openapi.json',
      '-o',
      generatedPath,
    ],
    {
      stdio: ['ignore', 'ignore', 'inherit'],
    },
  )
  if (run.status !== 0) {
    console.error('api:check: openapi-typescript failed')
    process.exit(1)
  }

  if (!readFileSync(committedPath).equals(readFileSync(generatedPath))) {
    console.error(`api:check: ${committedPath} is out of date with ../docs/openapi.json.`)
    console.error('Run: npx openapi-typescript ../docs/openapi.json -o src/api/schema.d.ts')
    process.exit(1)
  }
  console.log('api:check: schema.d.ts is up to date')
}
finally {
  rmSync(dir, { recursive: true, force: true })
}
