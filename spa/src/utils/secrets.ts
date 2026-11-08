/** Secrets the user touched: a string replaces the stored value, `null` clears it. Untouched keys are absent. */
export type SecretDraft = Record<string, string | null | undefined>

type ConfigLike = Record<string, unknown>

/** The value at a dotted path, like the server's `data_get`; undefined where the path runs out. */
export function atPath(config: ConfigLike, path: string): unknown {
  let value: unknown = config
  for (const part of path.split('.')) {
    value = typeof value === 'object' && value !== null
      ? (value as Record<string, unknown>)[part]
      : undefined
  }
  return value
}

/** A config value in the form the server compares: absent and null match, scalars compare as text. */
export function comparable(value: unknown): unknown {
  if (value === null || value === undefined)
    return null
  return typeof value === 'object'
    ? JSON.stringify(value)
    : String(value)
}

/**
 * Whether a destination value differs between the two configs. `keys` are the type's `destination_keys`
 * from the type catalogue, so the comparison follows the server's own list.
 */
export function destinationChanged(keys: readonly string[], before: ConfigLike, after: ConfigLike): boolean {
  return keys.some(path => comparable(atPath(before, path)) !== comparable(atPath(after, path)))
}

/**
 * Stored secrets to enter again: the server refuses a destination change that would carry them along
 * unconfirmed, so each one must be retyped or cleared. `before` is null while creating.
 */
export function requiredSecrets(keys: readonly string[], before: ConfigLike | null, after: ConfigLike | null, stored: string[]): string[] {
  return before !== null && after !== null && destinationChanged(keys, before, after)
    ? stored
    : []
}

/**
 * After a destination change every stored secret must be sent again: one on screen has to be retyped or
 * cleared by the user (it is reported in `missing`), one without a control (its feature is off) is removed.
 * The given draft is left untouched.
 */
export function withReenteredSecrets(draft: SecretDraft, required: readonly string[], onScreen: readonly string[]): { draft: SecretDraft, missing: string[] } {
  if (required.length === 0)
    return { draft, missing: [] }
  const shown = new Set(onScreen)
  const out: SecretDraft = { ...draft }
  const missing: string[] = []
  for (const key of required) {
    const value = out[key]
    if (value !== undefined && value !== '')
      continue
    if (shown.has(key))
      missing.push(key)
    else
      out[key] = null
  }
  return { draft: out, missing }
}
