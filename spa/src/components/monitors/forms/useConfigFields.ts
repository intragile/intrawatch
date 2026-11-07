import type { ModelRef } from 'vue'
import type { Config } from './model'
import { errorFor } from './model'

/** Typed accessors over a config object that is edited by replacing it, never by mutating a prop. */
export function useConfigFields(config: ModelRef<Config>, errors: () => Record<string, string>) {
  function set(key: string, value: unknown): void {
    config.value = { ...config.value, [key]: value }
  }

  function str(key: string): string {
    const v = config.value[key]
    return typeof v === 'string'
      ? v
      : ''
  }

  function num(key: string): number | null {
    const v = config.value[key]
    return typeof v === 'number'
      ? v
      : null
  }

  function bool(key: string): boolean {
    return config.value[key] === true
  }

  /** The object at `key`, or an empty one. */
  function obj(key: string): Record<string, unknown> {
    const v = config.value[key]
    return typeof v === 'object' && v !== null && !Array.isArray(v)
      ? v as Record<string, unknown>
      : {}
  }

  /** Sets a member of the object at `key`. */
  function setIn(key: string, member: string, value: unknown): void {
    set(key, {
      ...obj(key),
      [member]: value,
    })
  }

  /** Text input value to config value: blank becomes null for nullable fields. */
  function nullable(value: string | null): string | null {
    const trimmed = (value ?? '').trim()
    return trimmed === ''
      ? null
      : trimmed
  }

  function err(key: string): string | undefined {
    return errorFor(errors(), key)
  }

  return {
    set,
    str,
    num,
    bool,
    obj,
    setIn,
    nullable,
    err,
  }
}
