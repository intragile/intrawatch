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

// Workspace screens, each in its own chunk.
const workspaceRoutes: RouteRecordRaw[] = [
  {
    path: 'monitors/new',
    name: 'ws.monitor.new',
    component: () => import('../views/monitors/MonitorEditView.vue'),
  },
  // Master-detail: the list stays mounted while the selected monitor changes beside it.
  {
    path: 'monitors',
    component: () => import('../views/monitors/MonitorListView.vue'),
    children: [
      {
        path: '',
        name: 'ws.monitors',
        component: () => import('../views/monitors/MonitorOverview.vue'),
      },
      {
        path: ':monitor(\\d+)',
        name: 'ws.monitor',
        component: () => import('../views/monitors/MonitorDetailView.vue'),
      },
    ],
  },
  {
    path: 'monitors/:monitor(\\d+)/edit',
    name: 'ws.monitor.edit',
    component: () => import('../views/monitors/MonitorEditView.vue'),
  },
  {
    path: 'channels',
    name: 'ws.channels',
    component: () => import('../views/notifications/ChannelListView.vue'),
  },
  {
    path: 'channels/new',
    name: 'ws.channel.new',
    component: () => import('../views/notifications/ChannelEditView.vue'),
  },
  {
    path: 'channels/:channel(\\d+)',
    name: 'ws.channel',
    component: () => import('../views/notifications/ChannelEditView.vue'),
  },
  {
    path: 'alerts',
    name: 'ws.alerts',
    component: () => import('../views/notifications/AlertHistoryView.vue'),
  },
  {
    path: 'incidents',
    name: 'ws.incidents',
    component: () => import('../views/incidents/IncidentListView.vue'),
  },
  {
    path: 'incidents/:incident(\\d+)',
    name: 'ws.incident',
    component: () => import('../views/incidents/IncidentView.vue'),
  },
  {
    path: 'maintenances',
    name: 'ws.maintenances',
    component: () => import('../views/maintenances/MaintenanceListView.vue'),
  },
  {
    path: 'maintenances/:maintenance(new|\\d+)',
    name: 'ws.maintenance',
    component: () => import('../views/maintenances/MaintenanceEditView.vue'),
  },
  {
    path: 'status-pages',
    name: 'ws.status-pages',
    component: () => import('../views/status-pages/StatusPageListView.vue'),
  },
  {
    path: 'status-pages/:statusPage(new|\\d+)',
    name: 'ws.status-page',
    component: () => import('../views/status-pages/StatusPageEditView.vue'),
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
    path: 'tags',
    name: 'ws.tags',
    component: () => import('../views/workspace/TagsView.vue'),
  },
  {
    path: 'audit',
    name: 'ws.audit',
    component: () => import('../views/workspace/AuditView.vue'),
  },
  {
    path: 'import',
    name: 'ws.import',
    component: () => import('../views/import/ImportView.vue'),
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
    // Not a guest route: a signed-out visitor signs in first and comes back here. The token arrives
    // in the fragment; the guard stores it and drops the hash (see invitationToken.ts).
    path: '/invitations',
    name: 'invitation',
    component: () => import('../views/InvitationAcceptView.vue'),
    meta: { layout: 'auth' },
  },
  {
    // Links mailed before the token moved to the fragment. The guard takes the token out of the
    // path and replaces the address with `/invitations`; this route never renders.
    path: '/invitations/:token([A-Za-z0-9]{64})',
    name: 'invitation-legacy',
    component: () => import('../views/InvitationAcceptView.vue'),
    meta: { layout: 'auth' },
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
    path: '/changelog',
    name: 'changelog',
    component: () => import('../views/changelog/ChangelogView.vue'),
  },
  {
    path: '/help',
    name: 'help',
    component: () => import('../views/help/HelpIndexView.vue'),
  },
  {
    // The slug keeps its slash (`section/chapter`); links build the path, see chapterLocation().
    path: '/help/:slug(.*)',
    name: 'help.chapter',
    component: () => import('../views/help/HelpChapterView.vue'),
    props: true,
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

/**
 * Screens outside any workspace that still show the shell's workspace navigation: they keep the last used
 * workspace as the current one, so the rail does not empty out on the way to help or the changelog.
 */
export const WORKSPACE_CONTEXT_ROUTES = new Set([
  'account',
  'changelog',
  'help',
  'help.chapter',
])

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
  'ws.tags',
  'ws.audit',
  'ws.import',
])

export function createAppRouter(history: RouterHistory = createWebHistory(import.meta.env.BASE_URL)) {
  return createRouter({ history, routes })
}
