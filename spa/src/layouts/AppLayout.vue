<script setup lang="ts">
import type { Ability } from '../api/types'
import {
  mdiAccountCircle,
  mdiAccountGroup,
  mdiAlertOutline,
  mdiBellOutline,
  mdiCheck,
  mdiCogOutline,
  mdiDatabaseImportOutline,
  mdiFileDocumentOutline,
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
import { useCan } from '../composables/useCan'
import { useNotify } from '../composables/useNotify'
import { usePreferences } from '../composables/usePreferences'
import { WORKSPACE_LIST_ROUTES } from '../router'
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
const route = useRoute()
const router = useRouter()
const can = useCan()
const { notify } = useNotify()
const { isDark, toggleTheme, locale, locales, setLocale } = usePreferences()

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
  <v-app-bar flat border density="comfortable">
    <v-app-bar-nav-icon v-if="workspace" :aria-label="t('nav.toggleMenu')" @click="drawer = !drawer" />
    <v-app-bar-title>
      <router-link :to="{ name: 'home' }" class="app-title">
        {{ t('app.name') }}
      </router-link>
    </v-app-bar-title>

    <v-select
      v-if="session.workspaces.length > 0"
      :model-value="workspace?.slug ?? null"
      :items="session.workspaces"
      item-title="name"
      item-value="slug"
      :label="t('nav.workspace')"
      density="compact"
      variant="outlined"
      hide-details
      class="workspace-switcher mx-2"
      @update:model-value="switchWorkspace"
    />
    <v-btn
      v-if="session.workspaces.length > 0 && session.canCreateWorkspace"
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

    <div class="mx-2 d-flex align-center">
      <slot name="status" />
    </div>

    <v-menu location="bottom end">
      <template #activator="{ props: activator }">
        <v-btn v-bind="activator" icon :aria-label="t('nav.accountMenu')">
          <v-icon :icon="mdiAccountCircle" />
        </v-btn>
      </template>
      <v-list density="compact" min-width="240">
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
  </v-app-bar>

  <v-navigation-drawer v-if="workspace" v-model="drawer">
    <v-list nav density="comfortable" :aria-label="t('nav.mainMenu')">
      <v-list-item
        v-for="item in visibleItems"
        :key="item.route"
        :to="{ name: item.route, params: { workspace: workspace.slug } }"
        :prepend-icon="item.icon"
        :title="item.label"
      />
      <v-divider v-if="visibleAdminItems.length > 0" class="my-2" />
      <v-list-item
        v-for="item in visibleAdminItems"
        :key="item.route"
        :to="{ name: item.route, params: { workspace: workspace.slug } }"
        :prepend-icon="item.icon"
        :title="item.label"
      />
    </v-list>
  </v-navigation-drawer>

  <CreateWorkspaceDialog v-model="createDialog" />

  <v-main>
    <v-container fluid>
      <slot />
    </v-container>
  </v-main>
</template>

<style scoped>
.app-title {
  color: inherit;
  text-decoration: none;
}

.workspace-switcher {
  max-width: 240px;
}
</style>
