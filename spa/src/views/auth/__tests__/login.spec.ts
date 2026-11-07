import { describe, expect, it } from 'vitest'
import { me } from '../../../test/fixtures'
import { click, fill, mountApp, submit } from '../../../test/mount'
import { fakeApi, json, problem } from '../../../test/server'

describe('login', () => {
  it('login with 2FA goes to two-factor and accepts a recovery code', async () => {
    const server = fakeApi({
      'GET /setup': json({ required: false }),
      'GET /me': [
        problem(401),
        json({
          data: me(),
        }),
      ],
      'POST /auth/login': json({ two_factor: true }),
      'POST /auth/two-factor-challenge': json(null, 204),
    })
    const { router } = await mountApp('/login')

    fill('input[name="email"]', 'ada@example.com')
    fill('input[name="password"]', 'correct horse battery')
    await submit('input[name="email"]')

    await expect.poll(() => router.currentRoute.value.name).toBe('two-factor')
    expect(server.of('GET /me')).toHaveLength(1)

    await expect.poll(() => document.querySelector('[data-test="use-recovery"]')).not.toBeNull()
    await click('[data-test="use-recovery"]')
    await expect.poll(() => document.querySelector('input[name="recovery_code"]')).not.toBeNull()
    fill('input[name="recovery_code"]', 'abcde-fghij')
    await submit('input[name="recovery_code"]')

    await expect.poll(() => router.currentRoute.value.name).toBe('ws.monitors')
    expect(router.currentRoute.value.params.workspace).toBe('acme')
    expect(server.of('POST /auth/two-factor-challenge')[0]?.body).toEqual({ recovery_code: 'abcde-fghij' })
  })
})
