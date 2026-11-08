import type { Me, WorkspaceSummary } from '../api/types'
import type { TwoFactorAnswer } from '../auth/fortify'
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api, ApiError, resetCsrf, unwrap } from '../api'
import * as fortify from '../auth/fortify'
import { readPreference, writePreference } from '../utils/storage'

/** The `/me` variant of a cookie session (the token variant has no e-mail). */
export type SessionUser = Extract<Me, { email: string }>

/** Minimum gap between `/me` refreshes triggered by window focus. */
export const FOCUS_REFRESH_MS = 30_000

const LAST_WORKSPACE_KEY = 'intrawatch.workspace'

export const useSession = defineStore('session', () => {
  const me = ref<Me | null>(null)
  /** True once `/me` has been asked at least once, whatever the answer. */
  const loaded = ref(false)
  const setupRequired = ref<boolean | null>(null)
  /** Set between a login that answered `two_factor` and the challenge. */
  const twoFactorPending = ref(false)
  /** The `:workspace` route parameter, kept in sync by the router. */
  const currentSlug = ref<string | null>(null)

  let lastLoadedAt = 0
  let inFlight: Promise<void> | null = null

  const user = computed<SessionUser | null>(() => (me.value && 'email' in me.value
    ? me.value
    : null))
  const workspaces = computed<WorkspaceSummary[]>(() => me.value?.workspaces ?? [])
  /** Whether the API lets this user create a workspace (the installation's WORKSPACE_CREATORS setting). */
  const canCreateWorkspace = computed(() => user.value?.can_create_workspace === true)

  function workspace(slug: string): WorkspaceSummary | undefined {
    return workspaces.value.find(w => w.slug === slug)
  }

  const current = computed<WorkspaceSummary | null>(() =>
    currentSlug.value === null
      ? null
      : workspace(currentSlug.value) ?? null)

  /** Fetches `/me`; a 401 means signed out. Concurrent calls share one request. */
  function load(): Promise<void> {
    if (inFlight)
      return inFlight
    const run = (async () => {
      try {
        me.value = await unwrap(api.GET('/me'))
      }
      catch (e) {
        if (e instanceof ApiError && e.status === 401)
          me.value = null
        else
          throw e
      }
      finally {
        lastLoadedAt = Date.now()
        loaded.value = true
        inFlight = null
      }
    })()
    inFlight = run
    return run
  }

  async function checkSetup(): Promise<boolean> {
    if (setupRequired.value === null) {
      const { data } = await api.GET('/setup')
      setupRequired.value = data?.required ?? false
    }
    return setupRequired.value
  }

  async function login(email: string, password: string, remember: boolean): Promise<'ok' | 'two-factor'> {
    const outcome = await fortify.login(email, password, remember)
    if (outcome === 'two-factor') {
      twoFactorPending.value = true
      return outcome
    }
    twoFactorPending.value = false
    await load()
    return outcome
  }

  async function twoFactor(answer: TwoFactorAnswer): Promise<void> {
    await fortify.answerTwoFactorChallenge(answer)
    twoFactorPending.value = false
    await load()
  }

  /** Abandons a pending re-login or sign-in challenge. */
  function cancelTwoFactor(): void {
    twoFactorPending.value = false
  }

  async function logout(): Promise<void> {
    try {
      await fortify.logout()
    }
    catch (e) {
      // An already expired session is signed out as far as the user is concerned.
      if (!(e instanceof ApiError) || (e.status !== 401 && e.status !== 419))
        throw e
    }
    finally {
      me.value = null
      twoFactorPending.value = false
      resetCsrf()
    }
  }

  /** Reloads `/me` when signed in and the last load is older than the focus interval. */
  async function refreshIfStale(): Promise<void> {
    if (me.value === null || Date.now() - lastLoadedAt < FOCUS_REFRESH_MS)
      return
    try {
      await load()
    }
    catch {
      // A failed background refresh keeps the last known state.
    }
  }

  /** Refreshes `/me` on window focus (role changes made elsewhere); returns the remover. */
  function watchFocus(): () => void {
    const onFocus = () => {
      void refreshIfStale()
    }
    window.addEventListener('focus', onFocus)
    return () => window.removeEventListener('focus', onFocus)
  }

  function rememberWorkspace(slug: string): void {
    writePreference(LAST_WORKSPACE_KEY, slug)
  }

  /** The last used workspace when it still exists, otherwise the first one. */
  function homeWorkspace(): string | null {
    const last = readPreference(LAST_WORKSPACE_KEY)
    if (last !== null && workspace(last))
      return last
    return workspaces.value[0]?.slug ?? null
  }

  return {
    me,
    loaded,
    setupRequired,
    twoFactorPending,
    currentSlug,
    user,
    workspaces,
    canCreateWorkspace,
    current,
    workspace,
    load,
    checkSetup,
    login,
    twoFactor,
    cancelTwoFactor,
    logout,
    refreshIfStale,
    watchFocus,
    rememberWorkspace,
    homeWorkspace,
  }
})
