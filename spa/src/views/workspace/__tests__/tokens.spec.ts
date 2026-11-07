/// <reference types="node" />
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { useSession } from '../../../stores/session'
import { me } from '../../../test/fixtures'
import { click, fill, mountApp, submit } from '../../../test/mount'
import { fakeApi, json } from '../../../test/server'
import { TOKEN_ABILITIES } from '../../../utils/tokenAbilities'
import { page } from './helpers'

const PLAIN = 'iw_plaintext_secret_value'

const tokenRow = {
  id: 7,
  name: 'CI deploy',
  abilities: ['monitors:read'],
  last_used_at: null,
  expires_at: null,
  created_at: '2026-01-01T10:00:00+00:00',
}

describe('api tokens', () => {
  it('token plaintext is shown once and cleared on close', async () => {
    const server = fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: me(),
      }),
      'GET /workspaces/acme/tokens': json(page([tokenRow])),
      'POST /workspaces/acme/tokens': json({ id: '8', token: PLAIN }, 201),
    })
    const { router } = await mountApp('/w/acme/tokens')
    await expect.poll(() => document.body.textContent).toContain('CI deploy')

    fill('input[name="token_name"]', 'Backup job')
    await click('input[value="monitors:read"]')
    await click('input[value="audit:read"]')
    await submit('input[name="token_name"]')

    await expect.poll(() => document.querySelector<HTMLInputElement>('input[name="token_plaintext"]')?.value).toBe(PLAIN)
    expect(server.of('POST /workspaces/acme/tokens')[0]?.body).toEqual({
      name: 'Backup job',
      abilities: ['monitors:read', 'audit:read'],
    })
    // The list is refreshed behind the dialog; the secret is not part of it.
    await expect.poll(() => server.of('GET /workspaces/acme/tokens').length).toBe(2)

    await click('[data-test="token-done"]')
    // The dialog may linger for its leave transition, but without the secret.
    await expect.poll(() => document.querySelector<HTMLInputElement>('input[name="token_plaintext"]')?.value ?? '').toBe('')
    expect(document.body.innerHTML).not.toContain(PLAIN)
    expect(JSON.stringify(useSession().$state)).not.toContain(PLAIN)
    expect(router.currentRoute.value.fullPath).not.toContain(PLAIN)
    expect(JSON.stringify(Object.entries(localStorage))).not.toContain(PLAIN)
  })

  it('offers exactly the abilities of the API schema', () => {
    const spec = JSON.parse(readFileSync(resolve(process.cwd(), '../docs/openapi.json'), 'utf8')) as {
      components: { schemas: { TokenAbility: { enum: string[] } } }
    }
    expect([...TOKEN_ABILITIES].sort()).toEqual([...spec.components.schemas.TokenAbility.enum].sort())
  })

  it('revokes a token after confirmation', async () => {
    const server = fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': json({
        data: me(),
      }),
      'GET /workspaces/acme/tokens': [
        json(page([tokenRow])),
        json(page([])),
      ],
      'DELETE /workspaces/acme/tokens/7': json(null, 204),
    })
    await mountApp('/w/acme/tokens')
    await expect.poll(() => document.querySelector('[data-test="revoke-7"]')).not.toBeNull()

    await click('[data-test="revoke-7"]')
    await click('[data-test="confirm"]')

    await expect.poll(() => server.of('DELETE /workspaces/acme/tokens/7')).toHaveLength(1)
    await expect.poll(() => document.body.textContent).not.toContain('CI deploy')
  })
})
