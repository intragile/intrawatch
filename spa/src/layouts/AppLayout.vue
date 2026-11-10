<script setup lang="ts">
import type { Ability } from '../api/types'
import {
  mdiAccountGroup,
  mdiAlertOutline,
  mdiBellOutline,
  mdiCheck,
  mdiCogOutline,
  mdiDatabaseImportOutline,
  mdiFileDocumentOutline,
  mdiHelpCircleOutline,
  mdiHistory,
  mdiKeyVariant,
  mdiLogout,
  mdiMonitorDashboard,
  mdiPlus,
  mdiShieldAccountOutline,
  mdiTrafficCone,
  mdiWeatherNight,
  mdiWeatherSunny,
  mdiWebCheck,
  mdiWrenchClock,
} from '@mdi/js'
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useDisplay } from 'vuetify'
import AppLogo from '../components/common/AppLogo.vue'
import { useCan } from '../composables/useCan'
import { useNotify } from '../composables/useNotify'
import { usePreferences } from '../composables/usePreferences'
import { WORKSPACE_LIST_ROUTES } from '../router'
import { useChangelogNews } from '../stores/changelog'
import { useSession } from '../stores/session'
import CreateWorkspaceDialog from '../views/workspace/CreateWorkspaceDialog.vue'

interface NavItem {
  route: string
  label: string
  icon: string
  ability: Ability
}

const { t } = useI18n()
const session = useSession()
const news = useChangelogNews()
const route = useRoute()
const router = useRouter()
const can = useCan()
const { notify } = useNotify()
const { isDark, toggleTheme, locale, locales, setLocale } = usePreferences()
// Below md the rail becomes a temporary drawer behind a slim top bar.
const { smAndDown: mobile } = useDisplay()

const initials = computed(() => {
  const name = session.user?.name?.trim() ?? ''
  const parts = name.split(/\s+/).filter(Boolean)
  return parts.slice(0, 2).map(p => p[0]?.toUpperCase() ?? '').join('') || '?'
})

// Null lets Vuetify open the drawer on wide screens and keep it closed on narrow ones.
const drawer = ref<boolean | null>(null)
const createDialog = ref(false)
const workspace = computed(() => session.current)

const items = computed<NavItem[]>(() => [
  {
    route: 'ws.monitors',
    label: t('nav.monitors'),
    icon: mdiMonitorDashboard,
    ability: 'viewMonitors',
  },
  {
    route: 'ws.channels',
    label: t('nav.channels'),
    icon: mdiBellOutline,
    ability: 'viewNotifications',
  },
  {
    route: 'ws.alerts',
    label: t('nav.alerts'),
    icon: mdiAlertOutline,
    ability: 'viewNotifications',
  },
  {
    route: 'ws.incidents',
    label: t('nav.incidents'),
    icon: mdiTrafficCone,
    ability: 'viewIncidents',
  },
  {
    route: 'ws.maintenances',
    label: t('nav.maintenances'),
    icon: mdiWrenchClock,
    ability: 'viewIncidents',
  },
  {
    route: 'ws.status-pages',
    label: t('nav.statusPages'),
    icon: mdiWebCheck,
    ability: 'viewStatusPages',
  },
])

const adminItems = computed<NavItem[]>(() => [
  {
    route: 'ws.settings',
    label: t('nav.settings'),
    icon: mdiCogOutline,
    ability: 'view',
  },
  {
    route: 'ws.members',
    label: t('nav.members'),
    icon: mdiAccountGroup,
    ability: 'viewMembers',
  },
  {
    route: 'ws.tokens',
    label: t('nav.tokens'),
    icon: mdiKeyVariant,
    ability: 'view',
  },
  {
    route: 'ws.audit',
    label: t('nav.audit'),
    icon: mdiFileDocumentOutline,
    ability: 'viewAudit',
  },
  {
    route: 'ws.import',
    label: t('nav.import'),
    icon: mdiDatabaseImportOutline,
    ability: 'update',
  },
])

const visibleItems = computed(() => items.value.filter(i => can(i.ability)))
const visibleAdminItems = computed(() => adminItems.value.filter(i => can(i.ability)))

function switchWorkspace(slug: string | null): void {
  if (!slug || slug === workspace.value?.slug)
    return
  const name = typeof route.name === 'string' && WORKSPACE_LIST_ROUTES.has(route.name)
    ? route.name
    : 'ws.monitors'
  void router.push({
    name,
    params: { workspace: slug },
  })
}

let signingOut = false

async function signOut(): Promise<void> {
  signingOut = true
  try {
    await session.logout()
    await router.push({ name: 'login' })
  }
  catch {
    notify(t('nav.signOutFailed'), 'error')
  }
  finally {
    signingOut = false
  }
}

// The "new" mark is a nicety: asked once the session is known, never awaited, failures ignored.
watch(() => session.user, (user) => {
  if (user)
    void news.check()
}, { immediate: true })

let stopFocusRefresh: (() => void) | null = null
onMounted(() => {
  stopFocusRefresh = session.watchFocus()
})
onUnmounted(() => stopFocusRefresh?.())

// A refresh that drops the workspace being viewed (membership removed or workspace deleted) leaves it.
watch(() => session.me, (current) => {
  const slug = route.params.workspace
  if (current !== null && typeof slug === 'string' && !session.workspace(slug))
    void router.push({ name: 'home' })
})

// A background refresh that finds the session gone (signed out in another tab) leaves the shell.
watch(() => session.me, (current) => {
  if (current === null && session.loaded && !signingOut && !route.meta.guest) {
    void router.push({
      name: 'login',
      query: { redirect: route.fullPath },
    })
  }
})
</script>

<template>
  <v-app-bar v-if="mobile" flat border density="compact" class="top-bar">
    <v-app-bar-nav-icon :aria-label="t('nav.toggleMenu')" @click="drawer = !drawer" />
    <router-link :to="{ name: 'home' }" class="logo-link" :aria-label="t('app.name')">
      <AppLogo :size="24" />
    </router-link>
    <v-spacer />
    <div class="mr-3 d-flex align-center">
      <slot name="status" />
    </div>
  </v-app-bar>

  <v-navigation-drawer
    v-model="drawer"
    mobile-breakpoint="md"
    width="256"
    class="rail"
    :aria-label="t('nav.mainMenu')"
  >
    <div class="rail-head">
      <router-link :to="{ name: 'home' }" class="logo-link" :aria-label="t('app.name')">
        <AppLogo />
      </router-link>
    </div>

    <div v-if="session.workspaces.length > 0" class="workspace-row">
      <v-select
        :model-value="workspace?.slug ?? null"
        :items="session.workspaces"
        item-title="name"
        item-value="slug"
        :label="t('nav.workspace')"
        density="compact"
        hide-details
        class="workspace-switcher"
        @update:model-value="switchWorkspace"
      />
      <v-btn
        v-if="session.canCreateWorkspace"
        data-test="create-workspace"
        icon
        size="small"
        variant="text"
        :aria-label="t('nav.createWorkspace')"
        :title="t('nav.createWorkspace')"
        @click="createDialog = true"
      >
        <v-icon :icon="mdiPlus" />
      </v-btn>
    </div>

    <v-list v-if="workspace" nav density="compact" class="rail-list">
      <v-list-item
        v-for="item in visibleItems"
        :key="item.route"
        :to="{ name: item.route, params: { workspace: workspace.slug } }"
        :prepend-icon="item.icon"
        :title="item.label"
      />
      <template v-if="visibleAdminItems.length > 0">
        <v-list-subheader class="rail-subheader">
          {{ t('nav.manage') }}
        </v-list-subheader>
        <v-list-item
          v-for="item in visibleAdminItems"
          :key="item.route"
          :to="{ name: item.route, params: { workspace: workspace.slug } }"
          :prepend-icon="item.icon"
          :title="item.label"
        />
      </template>
    </v-list>

    <v-list nav density="compact" class="rail-list rail-help" :aria-label="t('nav.helpSection')">
      <v-list-subheader class="rail-subheader">
        {{ t('nav.helpSection') }}
      </v-list-subheader>
      <v-list-item
        data-test="nav-help"
        :to="{ name: 'help' }"
        :active="route.name === 'help' || route.name === 'help.chapter'"
        :prepend-icon="mdiHelpCircleOutline"
        :title="t('nav.help')"
      />
      <v-list-item
        data-test="nav-changelog"
        :to="{ name: 'changelog' }"
        :prepend-icon="mdiHistory"
        :title="t('nav.changelog')"
      >
        <template v-if="news.hasNews" #append>
          <span class="new-pill" data-test="changelog-new">
            <span aria-hidden="true">{{ t('nav.new') }}</span>
            <span class="d-sr-only">{{ t('nav.newHint') }}</span>
          </span>
        </template>
      </v-list-item>
    </v-list>

    <template #append>
      <div class="rail-foot">
        <div v-if="!mobile" class="rail-status">
          <slot name="status" />
        </div>
        <v-menu location="top start">
          <template #activator="{ props: activator }">
            <button v-bind="activator" type="button" class="account" :aria-label="t('nav.accountMenu')">
              <span class="avatar" aria-hidden="true">{{ initials }}</span>
              <span class="account-text">
                <span class="account-name">{{ session.user?.name }}</span>
                <span v-if="workspace" class="account-role">{{ t(`roles.${workspace.role}`) }}</span>
              </span>
            </button>
          </template>
          <v-list density="compact" min-width="260">
            <v-list-item
              v-if="session.user"
              :title="session.user.name"
              :subtitle="session.user.email"
            />
            <v-divider />
            <v-list-item
              :to="{ name: 'account' }"
              :prepend-icon="mdiShieldAccountOutline"
              :title="t('nav.account')"
            />
            <v-list-item
              :prepend-icon="isDark ? mdiWeatherSunny : mdiWeatherNight"
              :title="isDark ? t('theme.toLight') : t('theme.toDark')"
              @click="toggleTheme"
            />
            <v-list-subheader>{{ t('locale.label') }}</v-list-subheader>
            <v-list-item
              v-for="code in locales"
              :key="code"
              :title="t(`locale.${code}`)"
              :lang="code"
              :active="code === locale"
              @click="setLocale(code)"
            >
              <template #append>
                <v-icon v-if="code === locale" :icon="mdiCheck" size="small" />
              </template>
            </v-list-item>
            <v-divider />
            <v-list-item :prepend-icon="mdiLogout" :title="t('nav.signOut')" @click="signOut" />
          </v-list>
        </v-menu>
      </div>
    </template>
  </v-navigation-drawer>

  <CreateWorkspaceDialog v-model="createDialog" />

  <v-main>
    <v-container fluid class="page">
      <slot />
    </v-container>
  </v-main>
</template>

<style scoped>
.logo-link {
  display: inline-flex;
  color: inherit;
  text-decoration: none;
  border-radius: 6px;
}

.rail {
  background: rgb(var(--v-theme-background)) !important;
}

.rail-head {
  padding: 18px 20px 14px;
}

.workspace-row {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 0 12px 8px 16px;
}

.workspace-switcher {
  flex: 1;
  min-width: 0;
}

.rail-list {
  padding: 4px 8px;
  background: transparent;
}

.rail-list :deep(.v-list-item) {
  min-height: 36px;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}

.rail-list :deep(.v-list-item-title) {
  font-size: 0.9375rem;
  font-weight: 500;
}

.rail-list :deep(.v-list-item__spacer) {
  width: 12px !important;
}

.rail-list :deep(.v-list-item--active) {
  color: rgb(var(--v-theme-on-surface));
  background: rgb(var(--v-theme-surface));
  box-shadow: inset 3px 0 0 rgb(var(--v-theme-primary));
}

.rail-list :deep(.v-list-item--active > .v-list-item__overlay) {
  opacity: 0;
}

.new-pill {
  padding: 1px 8px;
  border-radius: 999px;
  background: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-on-primary));
  font-size: 0.75rem;
  font-weight: 600;
  line-height: 1.4;
}

.rail-subheader {
  margin-top: 12px;
  min-height: 32px;
  font-size: 0.8125rem;
  font-weight: 500;
  padding-inline-start: 10px !important;
}

.rail-foot {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 12px;
  border-top: 1px solid var(--iw-line);
}

.rail-status {
  padding: 0 8px;
}

.account {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 6px 8px;
  border: 0;
  border-radius: 6px;
  background: none;
  color: inherit;
  font: inherit;
  text-align: start;
  cursor: pointer;
}

.account:hover {
  background: rgba(var(--v-theme-on-surface), 0.06);
}

.avatar {
  flex: none;
  display: grid;
  place-items: center;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: var(--iw-selected);
  color: rgb(var(--v-theme-primary));
  font-weight: 600;
  font-size: 0.8125rem;
}

.account-text {
  display: flex;
  flex-direction: column;
  min-width: 0;
  line-height: 1.25;
}

.account-name {
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.account-role {
  font-size: 0.8125rem;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}

.page {
  max-width: 1600px;
  padding: 24px 28px 48px;
}

@media (max-width: 599.98px) {
  .page {
    padding: 16px 16px 40px;
  }
}
</style>
