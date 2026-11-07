import type { RouteRecordRaw, RouterHistory } from 'vue-router'
import { createRouter, createWebHistory } from 'vue-router'
import LoginView from '../views/auth/LoginView.vue'

declare module 'vue-router' {
  interface RouteMeta {
    /** `auth` renders the centred card layout; the default is the application shell. */
    layout?: 'auth'
    /** Reachable without a session. */
    guest?: boolean
  }
}

// Every view except the login screen is split into its own chunk.
const auth = { layout: 'auth', guest: true } as const

// Workspace screens; each placeholder is replaced by its view as the screen is built.
const workspaceRoutes: RouteRecordRaw[] = [
  {
    path: 'monitors',
    name: 'ws.monitors',
    component: () => import('../views/monitors/MonitorListView.vue'),
  },
  {
    path: 'monitors/new',
    name: 'ws.monitor.new',
    component: () => import('../views/PlaceholderView.vue'),
  },
  {
    path: 'monitors/:monitor(\\d+)',
    name: 'ws.monitor',
    component: () => import('../views/PlaceholderView.vue'),
  },
  {
    path: 'monitors/:monitor(\\d+)/edit',
    name: 'ws.monitor.edit',
    component: () => import('../views/PlaceholderView.vue'),
  },
  {
    path: 'channels',
    name: 'ws.channels',
    component: () => import('../views/PlaceholderView.vue'),
  },
  {
    path: 'channels/new',
    name: 'ws.channel.new',
    component: () => import('../views/PlaceholderView.vue'),
  },
  {
    path: 'channels/:channel(\\d+)',
    name: 'ws.channel',
    component: () => import('../views/PlaceholderView.vue'),
  },
  {
    path: 'alerts',
    name: 'ws.alerts',
    component: () => import('../views/PlaceholderView.vue'),
  },
  {
    path: 'incidents',
    name: 'ws.incidents',
    component: () => import('../views/PlaceholderView.vue'),
  },
  {
    path: 'incidents/:incident(\\d+)',
    name: 'ws.incident',
    component: () => import('../views/PlaceholderView.vue'),
  },
  {
    path: 'maintenances',
    name: 'ws.maintenances',
    component: () => import('../views/PlaceholderView.vue'),
  },
  {
    path: 'maintenances/:maintenance(new|\\d+)',
    name: 'ws.maintenance',
    component: () => import('../views/PlaceholderView.vue'),
  },
  {
    path: 'status-pages',
    name: 'ws.status-pages',
    component: () => import('../views/PlaceholderView.vue'),
  },
  {
    path: 'status-pages/:statusPage(new|\\d+)',
    name: 'ws.status-page',
    component: () => import('../views/PlaceholderView.vue'),
  },
  {
    path: 'settings',
    name: 'ws.settings',
    component: () => import('../views/workspace/WorkspaceSettingsView.vue'),
  },
  {
    path: 'members',
    name: 'ws.members',
    component: () => import('../views/workspace/MembersView.vue'),
  },
  {
    path: 'tokens',
    name: 'ws.tokens',
    component: () => import('../views/workspace/TokensView.vue'),
  },
  {
    path: 'audit',
    name: 'ws.audit',
    component: () => import('../views/workspace/AuditView.vue'),
  },
]

export const routes: RouteRecordRaw[] = [
  {
    path: '/setup',
    name: 'setup',
    component: () => import('../views/auth/SetupView.vue'),
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
    component: () => import('../views/auth/TwoFactorView.vue'),
    meta: auth,
  },
  {
    path: '/forgot-password',
    name: 'forgot-password',
    component: () => import('../views/auth/ForgotPasswordView.vue'),
    meta: auth,
  },
  {
    path: '/reset-password/:token',
    name: 'reset-password',
    component: () => import('../views/auth/ResetPasswordView.vue'),
    meta: auth,
  },
  {
    path: '/',
    name: 'home',
    component: () => import('../views/HomeView.vue'),
  },
  {
    path: '/account',
    name: 'account',
    component: () => import('../views/account/AccountView.vue'),
  },
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
