import type { Ref } from 'vue'
import { useI18n } from 'vue-i18n'

/** Mirrors the server's `Password::min(10)` default so mistakes show before submitting. */
export const PASSWORD_MIN = 10

type Rule = (value: string) => true | string

/** Client-side hints only; the API validates again and its 422 is shown on the field. */
export function usePasswordRules(password: Readonly<Ref<string>>): Record<'required' | 'email' | 'min' | 'matches', Rule> {
  const { t } = useI18n()
  return {
    required: value => value.trim() !== '' || t('validation.required'),
    email: value => /^[^\s@]+@[^\s@]+$/.test(value.trim()) || t('validation.email'),
    min: value => value.length >= PASSWORD_MIN || t('validation.passwordMin', { min: PASSWORD_MIN }),
    matches: value => value === password.value || t('validation.passwordMatch'),
  }
}
