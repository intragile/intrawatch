<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { BULK_MAX_IDS } from './filters'

export type BulkAction = 'pause' | 'resume' | 'delete' | 'tag' | 'untag' | 'notify' | 'unnotify'

const props = defineProps<{
  count: number
  busy: boolean
  tags: Array<{ id: number, name: string }>
  /** Null when the caller may not see notification channels. */
  channels: Array<{ id: number, name: string }> | null
}>()
const emit = defineEmits<{
  run: [action: BulkAction, target: { tagId?: number, channelId?: number }]
  clear: []
}>()

const { t } = useI18n()
const tagId = ref<number | null>(null)
const channelId = ref<number | null>(null)

const overLimit = computed(() => props.count > BULK_MAX_IDS)
const disabled = computed(() => props.busy || overLimit.value)

function run(action: BulkAction): void {
  if (disabled.value)
    return
  emit('run', action, {
    tagId: tagId.value ?? undefined,
    channelId: channelId.value ?? undefined,
  })
}
</script>

<template>
  <v-card variant="tonal" class="mb-4 pa-3" role="region" :aria-label="t('monitors.bulk.label')" data-test="bulk-bar">
    <div class="d-flex flex-wrap align-center ga-3">
      <strong aria-live="polite">{{ t('monitors.bulk.selected', { count }) }}</strong>
      <v-btn data-test="bulk-pause" variant="outlined" size="small" :disabled="disabled" @click="run('pause')">
        {{ t('monitors.bulk.pause') }}
      </v-btn>
      <v-btn data-test="bulk-resume" variant="outlined" size="small" :disabled="disabled" @click="run('resume')">
        {{ t('monitors.bulk.resume') }}
      </v-btn>
      <v-btn data-test="bulk-delete" variant="outlined" color="error" size="small" :disabled="disabled" @click="run('delete')">
        {{ t('monitors.bulk.delete') }}
      </v-btn>
      <v-btn data-test="bulk-clear" variant="text" size="small" @click="emit('clear')">
        {{ t('monitors.bulk.clear') }}
      </v-btn>
    </div>
    <div class="d-flex flex-wrap align-center ga-3 mt-3">
      <v-select
        v-if="tags.length > 0"
        v-model="tagId"
        data-test="bulk-tag-select"
        :items="tags.map(tag => ({ value: tag.id, title: tag.name }))"
        :label="t('monitors.bulk.tagLabel')"
        density="compact"
        variant="outlined"
        hide-details
        class="bulk-select"
      />
      <template v-if="tags.length > 0">
        <v-btn data-test="bulk-tag" variant="outlined" size="small" :disabled="disabled || tagId === null" @click="run('tag')">
          {{ t('monitors.bulk.addTag') }}
        </v-btn>
        <v-btn data-test="bulk-untag" variant="outlined" size="small" :disabled="disabled || tagId === null" @click="run('untag')">
          {{ t('monitors.bulk.removeTag') }}
        </v-btn>
      </template>
      <template v-if="channels !== null && channels.length > 0">
        <v-select
          v-model="channelId"
          data-test="bulk-channel-select"
          :items="channels.map(c => ({ value: c.id, title: c.name }))"
          :label="t('monitors.bulk.channelLabel')"
          density="compact"
          variant="outlined"
          hide-details
          class="bulk-select"
        />
        <v-btn data-test="bulk-notify" variant="outlined" size="small" :disabled="disabled || channelId === null" @click="run('notify')">
          {{ t('monitors.bulk.notify') }}
        </v-btn>
        <v-btn data-test="bulk-unnotify" variant="outlined" size="small" :disabled="disabled || channelId === null" @click="run('unnotify')">
          {{ t('monitors.bulk.unnotify') }}
        </v-btn>
      </template>
    </div>
    <p v-if="overLimit" class="text-error mt-3" role="alert" data-test="bulk-limit">
      {{ t('monitors.bulk.limit', { max: BULK_MAX_IDS, extra: count - BULK_MAX_IDS }) }}
    </p>
  </v-card>
</template>

<style scoped>
.bulk-select {
  min-width: 12rem;
  max-width: 16rem;
}
</style>
