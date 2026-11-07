import { describe, expect, it } from 'vitest'
import { defineComponent, h } from 'vue'
import PasswordConfirmDialog from '../../../components/common/PasswordConfirmDialog.vue'
import { useSession } from '../../../stores/session'
import { me } from '../../../test/fixtures'
import { click, fill, mountComponent, submit } from '../../../test/mount'
import { fakeApi, json, problem } from '../../../test/server'
import AccountView from '../AccountView.vue'

const Host = defineComponent({
  setup() {
    return () => [
      h(AccountView),
      h(PasswordConfirmDialog),
    ]
  },
})

describe('account two-factor', () => {
  it('2FA enable shows recovery codes once and requires confirm-password on 423', async () => {
    const server = fakeApi({
      'POST /auth/user/two-factor-authentication': [
        problem(423, { title: 'Password confirmation required.' }),
        json(''),
      ],
      'POST /auth/user/confirm-password': json('', 201),
      'GET /auth/user/two-factor-qr-code': json({ svg: '<svg xmlns="http://www.w3.org/2000/svg"></svg>', url: 'otpauth://totp/x' }),
      'GET /auth/user/two-factor-secret-key': json({ secretKey: 'JBSWY3DPEHPK3PXP' }),
      'POST /auth/user/confirmed-two-factor-authentication': json(''),
      'GET /auth/user/two-factor-recovery-codes': json(['alpha-one', 'bravo-two']),
      'GET /me': json({
        data: me({ two_factor_enabled: true }),
      }),
    })
    mountComponent(Host)
    useSession().me = me()

    await expect.poll(() => document.querySelector('[data-test="2fa-enable"]')).not.toBeNull()
    await click('[data-test="2fa-enable"]')

    await expect.poll(() => document.querySelector('input[name="confirm_password"]')).not.toBeNull()
    fill('input[name="confirm_password"]', 'correct horse battery')
    await submit('input[name="confirm_password"]')

    await expect.poll(() => document.body.textContent).toContain('JBSWY3DPEHPK3PXP')
    expect(server.of('POST /auth/user/confirm-password')[0]?.body).toEqual({ password: 'correct horse battery' })
    expect(server.of('POST /auth/user/two-factor-authentication')).toHaveLength(2)

    fill('input[name="totp_code"]', '654321')
    await submit('input[name="totp_code"]')

    await expect.poll(() => document.body.textContent).toContain('alpha-one')
    expect(document.body.textContent).toContain('bravo-two')
    expect(server.of('POST /auth/user/confirmed-two-factor-authentication')[0]?.body).toEqual({ code: '654321' })
    await expect.poll(() => useSession().user?.two_factor_enabled).toBe(true)

    await click('[data-test="codes-done"]')
    await expect.poll(() => document.body.textContent).not.toContain('alpha-one')
    expect(server.of('GET /auth/user/two-factor-recovery-codes')).toHaveLength(1)
    expect(document.querySelector('[data-test="2fa-disable"]')).not.toBeNull()
  })
})
