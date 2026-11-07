import type { RouteRecordRaw, RouterHistory } from 'vue-router'
import { createRouter, createWebHistory } from 'vue-router'
import AccountView from '../views/account/AccountView.vue'
import ForgotPasswordView from '../views/auth/ForgotPasswordView.vue'
import LoginView from '../views/auth/LoginView.vue'
import ResetPasswordView from '../views/auth/ResetPasswordView.vue'
import SetupView from '../views/auth/SetupView.vue'
import TwoFactorView from '../views/auth/TwoFactorView.vue'
import HomeView from '../views/HomeView.vue'
import PlaceholderView from '../views/PlaceholderView.vue'

declare module 'vue-router' {
  interface RouteMeta {
    /** `auth` renders the centred card layout; the default is the application shell. */
    layout?: 'auth'
    /** Reachable without a session. */
    guest?: boolean
  }
}

const auth = { layout: 'auth', guest: true } as const

// Workspace screens; each placeholder is replaced by its view as the screen is built.
const workspaceRoutes: RouteRecordRaw[] = [
  { path: 'monitors', name: 'ws.monitors', component: PlaceholderView },
  { path: 'monitors/new', name: 'ws.monitor.new', component: PlaceholderView },
  { path: 'monitors/:monitor(\\d+)', name: 'ws.monitor', component: PlaceholderView },
  { path: 'monitors/:monitor(\\d+)/edit', name: 'ws.monitor.edit', component: PlaceholderView },
  { path: 'channels', name: 'ws.channels', component: PlaceholderView },
  { path: 'channels/new', name: 'ws.channel.new', component: PlaceholderView },
  { path: 'channels/:channel(\\d+)', name: 'ws.channel', component: PlaceholderView },
  { path: 'alerts', name: 'ws.alerts', component: PlaceholderView },
  { path: 'incidents', name: 'ws.incidents', component: PlaceholderView },
  { path: 'incidents/:incident(\\d+)', name: 'ws.incident', component: PlaceholderView },
  { path: 'maintenances', name: 'ws.maintenances', component: PlaceholderView },
  { path: 'maintenances/:maintenance(new|\\d+)', name: 'ws.maintenance', component: PlaceholderView },
  { path: 'status-pages', name: 'ws.status-pages', component: PlaceholderView },
  { path: 'status-pages/:statusPage(new|\\d+)', name: 'ws.status-page', component: PlaceholderView },
  { path: 'settings', name: 'ws.settings', component: PlaceholderView },
  { path: 'members', name: 'ws.members', component: PlaceholderView },
  { path: 'tokens', name: 'ws.tokens', component: PlaceholderView },
  { path: 'audit', name: 'ws.audit', component: PlaceholderView },
]

export const routes: RouteRecordRaw[] = [
  {
    path: '/setup',
    name: 'setup',
    component: SetupView,
    meta: { layout: 'auth' },
  },
  {
    path: '/login',
    name: 'login',
    component: LoginView,
    meta: auth,
  },
  {
    path: '/two-factor',
    name: 'two-factor',
    component: TwoFactorView,
    meta: auth,
  },
  {
    path: '/forgot-password',
    name: 'forgot-password',
    component: ForgotPasswordView,
    meta: auth,
  },
  {
    path: '/reset-password/:token',
    name: 'reset-password',
    component: ResetPasswordView,
    meta: auth,
  },
  { path: '/', name: 'home', component: HomeView },
  { path: '/account', name: 'account', component: AccountView },
  {
    path: '/w/:workspace',
    children: [
      {
        path: '',
        redirect: to => ({
          name: 'ws.monitors',
          params: { workspace: to.params.workspace },
        }),
      },
      ...workspaceRoutes,
    ],
  },
  {
    path: '/:pathMatch(.*)*',
    redirect: { name: 'home' },
  },
]

/** Workspace list screens a workspace switch can stay on (they need no record id). */
export const WORKSPACE_LIST_ROUTES = new Set([
  'ws.monitors',
  'ws.channels',
  'ws.alerts',
  'ws.incidents',
  'ws.maintenances',
  'ws.status-pages',
  'ws.settings',
  'ws.members',
  'ws.tokens',
  'ws.audit',
])

export function createAppRouter(history: RouterHistory = createWebHistory(import.meta.env.BASE_URL)) {
  return createRouter({ history, routes })
}
