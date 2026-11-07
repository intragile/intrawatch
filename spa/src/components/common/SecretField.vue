<script setup lang="ts">
import { mdiCheckCircleOutline, mdiClose } from '@mdi/js'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

const props = withDefaults(defineProps<{
  label: string
  /** Input name; the buttons carry `secret-<name>-replace` style test hooks. */
  name: string
  /** Whether the server already stores a value for this secret. */
  isSet: boolean
  error?: string
  hint?: string
  /** Edit as multi-line text, for certificates and key lists. */
  multiline?: boolean
  /** Offer to remove a stored value without replacing it. */
  clearable?: boolean
}>(), {
  error: undefined,
  hint: undefined,
  multiline: false,
  clearable: true,
})

/**
 * `undefined` leaves the stored value untouched, a string replaces it and `null` clears it.
 * The value only lives in this model; it is never persisted client-side.
 */
const model = defineModel<string | null | undefined>({ default: undefined })
const { t } = useI18n()

const replacing = ref(false)

const showInput = computed(() => !props.isSet || replacing.value)
const pendingClear = computed(() => model.value === null)

function onInput(value: string | null): void {
  model.value = value === null || value === ''
    ? undefined
    : value
}

function cancel(): void {
  replacing.value = false
  model.value = undefined
}

function clear(): void {
  replacing.value = false
  model.value = null
}
</script>

<template>
  <div class="secret-field mb-4">
    <div v-if="!showInput" class="d-flex align-center flex-wrap ga-2">
      <span class="text-body-2 font-weight-medium">{{ label }}</span>
      <v-chip
        v-if="pendingClear"
        size="small"
        color="warning"
        variant="tonal"
        :data-test="`secret-${name}-pending-clear`"
      >
        {{ t('secret.willClear') }}
      </v-chip>
      <v-chip
        v-else
        size="small"
        color="success"
        variant="tonal"
        :prepend-icon="mdiCheckCircleOutline"
        :data-test="`secret-${name}-set`"
      >
        {{ t('secret.set') }}
      </v-chip>
      <v-btn
        v-if="pendingClear"
        size="small"
        variant="text"
        :data-test="`secret-${name}-undo`"
        @click="model = undefined"
      >
        {{ t('secret.undo') }}
      </v-btn>
      <template v-else>
        <v-btn
          size="small"
          variant="tonal"
          :aria-label="`${t('secret.replace')}: ${label}`"
          :data-test="`secret-${name}-replace`"
          @click="replacing = true"
        >
          {{ t('secret.replace') }}
        </v-btn>
        <v-btn
          v-if="clearable"
          size="small"
          variant="text"
          color="error"
          :aria-label="`${t('secret.clear')}: ${label}`"
          :data-test="`secret-${name}-clear`"
          @click="clear"
        >
          {{ t('secret.clear') }}
        </v-btn>
      </template>
      <p v-if="error" class="text-caption text-error w-100 ma-0" role="alert">
        {{ error }}
      </p>
    </div>
    <div v-else class="d-flex align-start ga-2">
      <v-textarea
        v-if="multiline"
        :model-value="typeof model === 'string' ? model : ''"
        :name="`secrets_${name}`"
        :label="label"
        rows="4"
        :hint="hint"
        :persistent-hint="hint !== undefined"
        :error-messages="error"
        autocomplete="off"
        spellcheck="false"
        class="flex-grow-1"
        @update:model-value="onInput"
      />
      <v-text-field
        v-else
        :model-value="typeof model === 'string' ? model : ''"
        :name="`secrets_${name}`"
        :label="label"
        type="password"
        :hint="hint"
        :persistent-hint="hint !== undefined"
        :error-messages="error"
        autocomplete="new-password"
        spellcheck="false"
        class="flex-grow-1"
        @update:model-value="onInput"
      />
      <v-btn
        v-if="isSet"
        icon
        variant="text"
        :aria-label="t('secret.keep')"
        :data-test="`secret-${name}-cancel`"
        @click="cancel"
      >
        <v-icon :icon="mdiClose" />
      </v-btn>
    </div>
  </div>
</template>
