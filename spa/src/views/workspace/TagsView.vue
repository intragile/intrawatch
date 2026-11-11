<script setup lang="ts">
import type { ApiError } from '../../api'
import type { Tag } from '../../api/types'
import { mdiDeleteOutline, mdiPencilOutline, mdiTagMultipleOutline } from '@mdi/js'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { api, fieldErrors, unwrap } from '../../api'
import ConfirmDialog from '../../components/common/ConfirmDialog.vue'
import EmptyState from '../../components/common/EmptyState.vue'
import ProblemAlert from '../../components/common/ProblemAlert.vue'
import HelpLink from '../../components/help/HelpLink.vue'
import { screenHelp } from '../../components/help/manual'
import TagChip from '../../components/tags/TagChip.vue'
import TagColorField from '../../components/tags/TagColorField.vue'
import { useApiFailure } from '../../composables/useApiFailure'
import { useCan } from '../../composables/useCan'
import { useNotify } from '../../composables/useNotify'
import { bodyOf, loadAll } from '../../utils/cursorPage'
import { DEFAULT_TAG_COLOR, isHexColor } from '../../utils/tagColor'

/** Page size of the tag list requests; `loadAll` follows the cursor. */
const PAGE = 200
const NAME_MAX = 60

const { t, locale } = useI18n()
const route = useRoute()
const can = useCan()
const failure = useApiFailure()
const { notify } = useNotify()

const slug = computed(() => String(route.params.workspace))
const canManage = computed(() => can('manageMonitors'))

const tags = ref<Tag[]>([])
const loading = ref(true)
const loadError = ref<ApiError | null>(null)
const sorted = computed(() => [...tags.value].sort((a, b) => a.name.localeCompare(b.name, locale.value)))

let loadSeq = 0
async function load(): Promise<void> {
  const seq = ++loadSeq
  loading.value = true
  loadError.value = null
  try {
    const rows = await loadAll<Tag>(cursor => bodyOf(api.GET('/workspaces/{workspace}/tags', {
      params: {
        path: { workspace: slug.value },
        query: { limit: PAGE, cursor },
      },
    })))
    if (seq === loadSeq)
      tags.value = rows
  }
  catch (e) {
    if (seq === loadSeq) {
      tags.value = []
      loadError.value = failure(e)
    }
  }
  finally {
    if (seq === loadSeq)
      loading.value = false
  }
}

watch(slug, () => {
  void load()
})
void load()

// Create

const newName = ref('')
const newColor = ref<string>(DEFAULT_TAG_COLOR)
const creating = ref(false)
const createError = ref<ApiError | null>(null)
const createFields = computed(() => fieldErrors(createError.value))
const canCreate = computed(() => newName.value.trim() !== '' && isHexColor(newColor.value.trim()))

async function create(): Promise<void> {
  if (creating.value || !canCreate.value)
    return
  creating.value = true
  createError.value = null
  try {
    const tag = await unwrap<Tag>(api.POST('/workspaces/{workspace}/tags', {
      params: {
        path: { workspace: slug.value },
      },
      body: {
        name: newName.value.trim(),
        color: newColor.value.trim().toLowerCase(),
      },
    }))
    tags.value = [...tags.value, tag]
    newName.value = ''
    newColor.value = DEFAULT_TAG_COLOR
    notify(t('tags.created', { name: tag.name }))
  }
  catch (e) {
    createError.value = failure(e)
  }
  finally {
    creating.value = false
  }
}

// Edit

const editing = ref<Tag | null>(null)
const editName = ref('')
const editColor = ref('')
const saving = ref(false)
const editError = ref<ApiError | null>(null)
const editFields = computed(() => fieldErrors(editError.value))
const editOpen = computed({
  get: () => editing.value !== null,
  set: (open) => {
    if (!open && !saving.value) {
      editing.value = null
      editError.value = null
    }
  },
})
const canSave = computed(() => editName.value.trim() !== '' && isHexColor(editColor.value.trim()))

function startEdit(tag: Tag): void {
  editing.value = tag
  editName.value = tag.name
  editColor.value = tag.color
  editError.value = null
}

async function save(): Promise<void> {
  const tag = editing.value
  if (tag === null || saving.value || !canSave.value)
    return
  const name = editName.value.trim()
  const color = editColor.value.trim().toLowerCase()
  const body: { name?: string, color?: string } = {}
  if (name !== tag.name)
    body.name = name
  if (color !== tag.color.toLowerCase())
    body.color = color
  if (Object.keys(body).length === 0) {
    editing.value = null
    return
  }
  saving.value = true
  editError.value = null
  try {
    const result = await unwrap(api.PATCH('/workspaces/{workspace}/tags/{tag}', {
      params: {
        path: { workspace: slug.value, tag: tag.id },
      },
      body,
    }))
    // The generated schema types this response's id as a string; the row keeps its numeric id.
    const updated: Tag = {
      id: tag.id,
      name: result.name,
      color: result.color,
      monitors_count: result.monitors_count,
    }
    tags.value = tags.value.map(row => row.id === tag.id
      ? updated
      : row)
    saving.value = false
    editing.value = null
    notify(t('tags.saved'))
  }
  catch (e) {
    editError.value = failure(e)
  }
  finally {
    saving.value = false
  }
}

// Delete

const deleting = ref<Tag | null>(null)
const deleteBusy = ref(false)
const deleteError = ref<ApiError | null>(null)
const confirmDelete = computed({
  get: () => deleting.value !== null,
  set: (open) => {
    if (!open) {
      deleting.value = null
      deleteError.value = null
    }
  },
})

/** Names how many monitors lose the tag; an unused tag keeps the general wording. */
const deleteText = computed(() => {
  const tag = deleting.value
  if (tag === null)
    return ''
  return tag.monitors_count > 0
    ? t('tags.delete.confirmUsed', { name: tag.name, n: tag.monitors_count }, tag.monitors_count)
    : t('tags.delete.confirm', { name: tag.name })
})

async function remove(): Promise<void> {
  const tag = deleting.value
  if (tag === null || deleteBusy.value)
    return
  deleteBusy.value = true
  deleteError.value = null
  try {
    await unwrap<void>(api.DELETE('/workspaces/{workspace}/tags/{tag}', {
      params: {
        path: { workspace: slug.value, tag: tag.id },
      },
    }))
    tags.value = tags.value.filter(row => row.id !== tag.id)
    deleting.value = null
    notify(t('tags.deleted', { name: tag.name }))
  }
  catch (e) {
    deleteError.value = failure(e)
  }
  finally {
    deleteBusy.value = false
  }
}
</script>

<template>
  <div class="d-flex align-center ga-2 mb-2">
    <h1 class="text-h5">
      {{ t('tags.title') }}
    </h1>
    <HelpLink v-bind="screenHelp('ws.tags')" />
  </div>
  <p class="mb-4">
    {{ t('tags.intro') }}
  </p>

  <v-card v-if="canManage" class="mb-4" max-width="960">
    <v-card-title tag="h2" class="text-h6">
      {{ t('tags.create.title') }}
    </v-card-title>
    <v-form @submit.prevent="create">
      <v-card-text>
        <ProblemAlert :error="createError" :known-fields="['name', 'color']" />
        <v-text-field
          v-model="newName"
          name="tag_name"
          :label="t('tags.name')"
          :error-messages="createFields.name"
          :maxlength="NAME_MAX"
          autocomplete="off"
          class="mb-2"
        />
        <TagColorField v-model="newColor" name="tag_color" :error="createFields.color" />
        <div class="d-flex align-center ga-2 mt-2">
          <span class="text-medium-emphasis">{{ t('tags.preview') }}</span>
          <TagChip :name="newName.trim() || t('tags.previewName')" :color="newColor.trim()" />
        </div>
      </v-card-text>
      <v-card-actions>
        <v-spacer />
        <v-btn type="submit" color="primary" variant="flat" :loading="creating" :disabled="!canCreate">
          {{ t('tags.create.submit') }}
        </v-btn>
      </v-card-actions>
    </v-form>
  </v-card>
  <p v-else class="text-medium-emphasis mb-4" data-test="tags-read-only">
    {{ t('tags.readOnly') }}
  </p>

  <ProblemAlert :error="loadError" />
  <v-btn v-if="loadError" variant="tonal" class="mb-4" @click="load">
    {{ t('common.retry') }}
  </v-btn>

  <v-progress-linear v-if="loading" indeterminate class="mb-4" />

  <v-table v-if="sorted.length > 0" class="tags-table">
    <caption class="d-sr-only">
      {{ t('tags.list') }}
    </caption>
    <thead>
      <tr>
        <th scope="col">
          {{ t('tags.tag') }}
        </th>
        <th scope="col">
          {{ t('tags.colorColumn') }}
        </th>
        <th scope="col" class="text-end">
          {{ t('tags.monitorsColumn') }}
        </th>
        <th v-if="canManage" scope="col" class="text-end">
          {{ t('tags.actions') }}
        </th>
      </tr>
    </thead>
    <tbody>
      <tr v-for="tag in sorted" :key="tag.id" :data-test="`tag-row-${tag.id}`">
        <td>
          <TagChip :name="tag.name" :color="tag.color" />
        </td>
        <td>
          <code>{{ tag.color }}</code>
        </td>
        <td class="text-end" data-test="monitors-count">
          {{ tag.monitors_count }}
        </td>
        <td v-if="canManage" class="text-end text-no-wrap">
          <v-btn
            :data-test="`edit-${tag.id}`"
            :icon="mdiPencilOutline"
            :aria-label="`${t('common.edit')}: ${tag.name}`"
            variant="text"
            size="small"
            @click="startEdit(tag)"
          />
          <v-btn
            :data-test="`delete-${tag.id}`"
            :icon="mdiDeleteOutline"
            :aria-label="`${t('common.delete')}: ${tag.name}`"
            variant="text"
            color="error"
            size="small"
            @click="deleting = tag"
          />
        </td>
      </tr>
    </tbody>
  </v-table>

  <EmptyState
    v-else-if="!loading && !loadError"
    :icon="mdiTagMultipleOutline"
    :title="t('tags.empty.title')"
    :text="canManage ? t('tags.empty.textManage') : t('tags.empty.text')"
  />

  <v-dialog v-model="editOpen" max-width="520">
    <v-card :title="t('tags.edit.title')">
      <v-form @submit.prevent="save">
        <v-card-text>
          <ProblemAlert :error="editError" :known-fields="['name', 'color']" />
          <v-text-field
            v-model="editName"
            name="edit_tag_name"
            :label="t('tags.name')"
            :error-messages="editFields.name"
            :maxlength="NAME_MAX"
            autocomplete="off"
            class="mb-2"
          />
          <TagColorField v-model="editColor" name="edit_tag_color" :error="editFields.color" />
          <div class="d-flex align-center ga-2 mt-2">
            <span class="text-medium-emphasis">{{ t('tags.preview') }}</span>
            <TagChip :name="editName.trim() || t('tags.previewName')" :color="editColor.trim()" />
          </div>
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" :disabled="saving" @click="editOpen = false">
            {{ t('common.cancel') }}
          </v-btn>
          <v-btn data-test="edit-save" type="submit" color="primary" variant="flat" :loading="saving" :disabled="!canSave">
            {{ t('common.save') }}
          </v-btn>
        </v-card-actions>
      </v-form>
    </v-card>
  </v-dialog>

  <ConfirmDialog
    v-model="confirmDelete"
    :title="t('tags.delete.title')"
    :confirm-label="t('common.delete')"
    :loading="deleteBusy"
    destructive
    @confirm="remove"
  >
    <ProblemAlert :error="deleteError" />
    {{ deleteText }}
  </ConfirmDialog>
</template>

<style scoped>
.tags-table {
  max-width: 960px;
}
</style>
