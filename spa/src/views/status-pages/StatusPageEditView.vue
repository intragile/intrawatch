<script setup lang="ts">
import type { ApiError } from '../../api'
import type { StatusPage } from '../../api/types'
import type { LayoutSection, PageForm } from '../../components/status-pages/model'
import { mdiOpenInNew, mdiTrashCanOutline } from '@mdi/js'
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { api, fieldErrors, unwrap } from '../../api'
import ConfirmDialog from '../../components/common/ConfirmDialog.vue'
import ProblemAlert from '../../components/common/ProblemAlert.vue'
import { clone, deepEqual } from '../../components/monitors/forms/model'
import LayoutEditor from '../../components/status-pages/LayoutEditor.vue'
import {
  createBody,
  formFromPage,
  layoutBody,
  layoutFromPage,
  newPageForm,
  PAGE_FIELDS,
  patchBody,
} from '../../components/status-pages/model'
import PageSettingsForm from '../../components/status-pages/PageSettingsForm.vue'
import { useApiFailure } from '../../composables/useApiFailure'
import { useCan } from '../../composables/useCan'
import { useLeaveGuard } from '../../composables/useLeaveGuard'
import { useNotify } from '../../composables/useNotify'
import { useRefetchOnReturn } from '../../composables/useRefetchOnReturn'
import { useSession } from '../../stores/session'
import { asApiError } from '../../utils/errors'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const session = useSession()
const can = useCan()
const failure = useApiFailure()
const { notify } = useNotify()

const slug = computed(() => session.current?.slug ?? String(route.params.workspace))
const pageId = computed(() => route.params.statusPage === 'new'
  ? null
  : Number(route.params.statusPage))
const isEdit = computed(() => pageId.value !== null)
const canManage = computed(() => can('manageStatusPages'))
const canDomain = computed(() => can('manageCustomDomains'))

const loaded = ref<StatusPage | null>(null)
const form = ref<PageForm | null>(null)
const initial = ref<PageForm | null>(null)
const sections = ref<LayoutSection[]>([])
const initialSections = ref<LayoutSection[]>([])
const loading = ref(true)
const loadError = ref<ApiError | null>(null)
const saving = ref(false)
const error = ref<ApiError | null>(null)
const layoutSaving = ref(false)
const layoutError = ref<ApiError | null>(null)

function applySettings(page: StatusPage): void {
  loaded.value = page
  form.value = formFromPage(page)
  initial.value = clone(form.value)
}

function applyLayout(page: StatusPage): void {
  sections.value = layoutFromPage(page)
  initialSections.value = clone(sections.value)
}

async function fetchPage(id: number): Promise<StatusPage> {
  return await unwrap<StatusPage>(api.GET('/workspaces/{workspace}/status-pages/{statusPage}', {
    params: {
      path: { workspace: slug.value, statusPage: id },
    },
  }))
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null
  try {
    if (pageId.value === null) {
      loaded.value = null
      form.value = newPageForm()
      initial.value = clone(form.value)
      sections.value = []
      initialSections.value = []
    }
    else {
      const page = await fetchPage(pageId.value)
      applySettings(page)
      applyLayout(page)
    }
  }
  catch (e) {
    loadError.value = asApiError(e)
  }
  finally {
    loading.value = false
  }
}

// Anyone who can view status pages can read one; only managers may start a new one.
if (!isEdit.value && !canManage.value) {
  void router.replace({
    name: 'ws.status-pages',
    params: { workspace: slug.value },
  })
}
else {
  onMounted(load)
  watch(pageId, (next, previous) => {
    if (next !== previous) {
      form.value = null
      void load()
    }
  })
}

const settingsDirty = computed(() => form.value !== null
  && initial.value !== null
  && !deepEqual(form.value, initial.value))
// Key identities differ between loads, so compare what would be sent.
const layoutDirty = computed(() => !deepEqual(layoutBody(sections.value), layoutBody(initialSections.value)))
const dirty = computed(() => settingsDirty.value || layoutDirty.value)
const leave = useLeaveGuard(dirty)
const { open: leaveOpen, confirm: confirmLeave } = leave

/** Picks up changes made elsewhere without overwriting what is being edited here. */
async function refresh(): Promise<void> {
  const id = pageId.value
  if (id === null || loading.value || saving.value || layoutSaving.value || dirty.value)
    return
  try {
    const page = await fetchPage(id)
    applySettings(page)
    applyLayout(page)
  }
  catch {
    // The next focus tries again; the loaded copy stays on screen.
  }
}
useRefetchOnReturn(() => void refresh())

const fields = computed(() => fieldErrors(error.value))
const knownFields = computed(() => Object.keys(error.value?.errors ?? {}).filter(key =>
  PAGE_FIELDS.some(root => key === root || key.startsWith(`${root}.`))
  && (key !== 'custom_domain' || canDomain.value)))

const LAYOUT_FIELD = /^sections\.\d+\.(?:name|description|items\.\d+\.(?:display_name|description|monitor_id))$/
const layoutFields = computed(() => fieldErrors(layoutError.value))
const layoutKnown = computed(() => Object.keys(layoutError.value?.errors ?? {}).filter(key => LAYOUT_FIELD.test(key)))

async function save(): Promise<void> {
  const current = form.value
  const baseline = initial.value
  if (!current || !baseline || saving.value || !canManage.value)
    return
  error.value = null
  const id = pageId.value
  const update = id === null
    ? null
    : patchBody(baseline, current, canDomain.value)
  if (update !== null && Object.keys(update).length === 0) {
    notify(t('statusPages.noChanges'), 'info')
    return
  }
  saving.value = true
  try {
    if (update !== null && id !== null) {
      applySettings(await unwrap<StatusPage>(api.PATCH('/workspaces/{workspace}/status-pages/{statusPage}', {
        params: {
          path: { workspace: slug.value, statusPage: id },
        },
        body: update,
      })))
      notify(t('statusPages.updated'))
    }
    else {
      const created = await unwrap<StatusPage>(api.POST('/workspaces/{workspace}/status-pages', {
        params: {
          path: { workspace: slug.value },
        },
        body: createBody(current, canDomain.value),
      }))
      leave.allow()
      notify(t('statusPages.created'))
      await router.replace({
        name: 'ws.status-page',
        params: {
          workspace: slug.value,
          statusPage: String(created.id),
        },
      })
    }
  }
  catch (e) {
    leave.disallow()
    error.value = failure(e)
  }
  finally {
    saving.value = false
  }
}

async function saveLayout(): Promise<void> {
  const id = pageId.value
  if (id === null || layoutSaving.value || !canManage.value)
    return
  layoutError.value = null
  layoutSaving.value = true
  try {
    applyLayout(await unwrap<StatusPage>(api.PUT('/workspaces/{workspace}/status-pages/{statusPage}/layout', {
      params: {
        path: { workspace: slug.value, statusPage: id },
      },
      body: layoutBody(sections.value),
    })))
    notify(t('statusPages.layout.saved'))
  }
  catch (e) {
    layoutError.value = failure(e)
  }
  finally {
    layoutSaving.value = false
  }
}

function cancel(): void {
  void router.push({
    name: 'ws.status-pages',
    params: { workspace: slug.value },
  })
}

const removing = ref(false)
const removeError = ref<ApiError | null>(null)
const removeBusy = ref(false)

async function remove(): Promise<void> {
  const id = pageId.value
  if (id === null || removeBusy.value)
    return
  removeBusy.value = true
  removeError.value = null
  try {
    await unwrap<void>(api.DELETE('/workspaces/{workspace}/status-pages/{statusPage}', {
      params: {
        path: { workspace: slug.value, statusPage: id },
      },
    }))
    leave.allow()
    notify(t('statusPages.delete.deleted'))
    await router.push({
      name: 'ws.status-pages',
      params: { workspace: slug.value },
    })
  }
  catch (e) {
    removeError.value = failure(e)
  }
  finally {
    removeBusy.value = false
  }
}
</script>

<template>
  <div class="d-flex align-center flex-wrap ga-2 mb-4">
    <h1 class="text-h5 flex-grow-1">
      {{ isEdit ? t('statusPages.editTitle') : t('statusPages.newTitle') }}
    </h1>
    <template v-if="loaded">
      <v-btn
        data-test="public-link"
        variant="tonal"
        :prepend-icon="mdiOpenInNew"
        :href="`/status/${loaded.slug}`"
        target="_blank"
        rel="noopener"
      >
        {{ t('statusPages.openPublic') }}
      </v-btn>
      <v-btn
        v-if="loaded.custom_domain"
        data-test="custom-domain-link"
        variant="tonal"
        :prepend-icon="mdiOpenInNew"
        :href="`https://${loaded.custom_domain}/`"
        target="_blank"
        rel="noopener"
      >
        {{ t('statusPages.openDomain', { domain: loaded.custom_domain }) }}
      </v-btn>
    </template>
  </div>

  <v-progress-linear v-if="loading" indeterminate :aria-label="t('statusPages.loading')" />
  <div v-else-if="loadError">
    <ProblemAlert :error="loadError" />
    <v-btn variant="tonal" data-test="retry" @click="load">
      {{ t('statusPages.retry') }}
    </v-btn>
  </div>

  <template v-else-if="form">
    <v-card max-width="860" class="mb-4">
      <v-form :disabled="!canManage" @submit.prevent="save">
        <v-card-title>{{ t('statusPages.settings') }}</v-card-title>
        <v-card-text>
          <v-alert v-if="!canManage" type="info" variant="tonal" class="mb-4">
            {{ t('statusPages.readOnly') }}
          </v-alert>
          <ProblemAlert :error="error" :known-fields="knownFields" />
          <PageSettingsForm
            v-model="form"
            :disabled="!canManage"
            :can-custom-domain="canDomain"
            :errors="fields"
          />
          <p class="text-caption text-medium-emphasis mt-4">
            {{ t('statusPages.staleNote') }}
          </p>
        </v-card-text>
        <v-card-actions v-if="canManage">
          <v-btn
            v-if="isEdit"
            data-test="status-page-delete"
            color="error"
            variant="text"
            :prepend-icon="mdiTrashCanOutline"
            @click="removing = true"
          >
            {{ t('common.delete') }}
          </v-btn>
          <v-spacer />
          <v-btn variant="text" data-test="cancel" @click="cancel">
            {{ t('common.cancel') }}
          </v-btn>
          <v-btn
            type="submit"
            color="primary"
            variant="flat"
            data-test="status-page-save"
            :loading="saving"
          >
            {{ isEdit ? t('common.save') : t('common.create') }}
          </v-btn>
        </v-card-actions>
      </v-form>
    </v-card>

    <v-card v-if="isEdit" max-width="860" class="mb-4">
      <v-card-title>{{ t('statusPages.layout.title') }}</v-card-title>
      <v-card-text>
        <p class="text-body-2 text-medium-emphasis mb-4">
          {{ t('statusPages.layout.help') }}
        </p>
        <ProblemAlert :error="layoutError" :known-fields="layoutKnown" />
        <LayoutEditor
          v-model="sections"
          :slug="slug"
          :disabled="!canManage"
          :can-list-monitors="can('viewMonitors')"
          :errors="layoutFields"
        />
        <p class="text-caption text-medium-emphasis mt-4">
          {{ t('statusPages.staleNote') }}
        </p>
      </v-card-text>
      <v-card-actions v-if="canManage">
        <v-spacer />
        <v-btn
          color="primary"
          variant="flat"
          data-test="layout-save"
          :loading="layoutSaving"
          @click="saveLayout"
        >
          {{ t('statusPages.layout.save') }}
        </v-btn>
      </v-card-actions>
    </v-card>
    <p v-else class="text-body-2 text-medium-emphasis">
      {{ t('statusPages.layout.afterCreate') }}
    </p>
  </template>

  <ConfirmDialog
    v-model="removing"
    :title="t('statusPages.delete.title')"
    :confirm-label="t('common.delete')"
    :loading="removeBusy"
    destructive
    @confirm="remove"
  >
    <ProblemAlert :error="removeError" />
    {{ t('statusPages.delete.text', { title: loaded?.title ?? '' }) }}
  </ConfirmDialog>

  <ConfirmDialog
    v-model="leaveOpen"
    :title="t('statusPages.leave.title')"
    :text="t('statusPages.leave.text')"
    :confirm-label="t('statusPages.leave.confirm')"
    destructive
    @confirm="confirmLeave"
  />
</template>
