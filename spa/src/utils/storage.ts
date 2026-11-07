// Preferences live in localStorage as a convenience only: private windows and blocked
// storage throw, and the application must keep working without them.

export function readPreference(key: string): string | null {
  try {
    return window.localStorage.getItem(key)
  }
  catch {
    return null
  }
}

export function writePreference(key: string, value: string): void {
  try {
    window.localStorage.setItem(key, value)
  }
  catch {}
}
