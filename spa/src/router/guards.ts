import type { RouteLocationNormalized, RouteLocationRaw, Router } from 'vue-router'
import { useSession } from '../stores/session'

/** A same-application path from a `redirect` query, or the home route. */
export function safeRedirect(value: unknown): RouteLocationRaw {
  // A backslash is treated as a slash by browsers, so `/\evil` would act like `//evil`.
  return typeof value === 'string' && value.startsWith('/') && !value.startsWith('//') && !value.includes('\\')
    ? value
    : { name: 'home' }
}

export async function authGuard(to: RouteLocationNormalized): Promise<true | RouteLocationRaw> {
  const session = useSession()

  let setupRequired = false
  try {
    setupRequired = await session.checkSetup()
  }
  catch {
    // Unknown setup state: continue as an installed instance; the API still decides.
  }
  if (setupRequired) {
    return to.name === 'setup'
      ? true
      : { name: 'setup' }
  }
  if (to.name === 'setup')
    return { name: 'home' }

  if (!session.loaded) {
    try {
      await session.load()
    }
    catch {
      // Treated as signed out; the login screen reports a server that stays unreachable.
    }
  }
  const signedIn = session.me !== null

  if (to.meta.guest) {
    if (signedIn && (to.name === 'login' || to.name === 'two-factor'))
      return safeRedirect(to.query.redirect)
    if (to.name === 'two-factor' && !session.twoFactorPending)
      return { name: 'login', query: to.query }
    return true
  }

  if (!signedIn) {
    return {
      name: 'login',
      query: to.fullPath === '/'
        ? {}
        : { redirect: to.fullPath },
    }
  }

  const slug = to.params.workspace
  if (typeof slug === 'string' && !session.workspace(slug))
    return { name: 'home' }

  if (to.name === 'home') {
    const target = session.homeWorkspace()
    if (target !== null) {
      return {
        name: 'ws.monitors',
        params: { workspace: target },
      }
    }
  }
  return true
}

export function installGuards(router: Router): void {
  router.beforeEach(to => authGuard(to))
  router.afterEach((to) => {
    const session = useSession()
    const slug = typeof to.params.workspace === 'string'
      ? to.params.workspace
      : null
    session.currentSlug = slug
    if (slug !== null)
      session.rememberWorkspace(slug)
  })
}
