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

// Per-tab state that survives an in-app redirect (such as the login) but not the tab. A blocked
// storage throws; callers keep their own fallback. `writeSession` reports whether it stored.

export function readSession(key: string): string | null {
  try {
    return window.sessionStorage.getItem(key)
  }
  catch {
    return null
  }
}

export function writeSession(key: string, value: string): boolean {
  try {
    window.sessionStorage.setItem(key, value)
    return true
  }
  catch {
    return false
  }
}

export function removeSession(key: string): void {
  try {
    window.sessionStorage.removeItem(key)
  }
  catch {}
}
