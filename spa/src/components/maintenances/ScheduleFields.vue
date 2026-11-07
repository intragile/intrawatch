<script setup lang="ts">
import type { MaintenanceStrategy } from '../../api/types'
import type { MaintenanceForm } from './model'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { errorFor } from '../monitors/forms/model'
import { STRATEGIES, timezoneOptions, toInt } from './model'

const props = defineProps<{
  /** First validation message per field; nested keys such as `weekdays.0` are matched by their root. */
  errors: Record<string, string>
}>()

const model = defineModel<MaintenanceForm>({ required: true })
const { t } = useI18n()

// Writes into the parent's form object itself: replacing it would lose a change made before the parent
// has re-rendered with the previous one.
function set<K extends keyof MaintenanceForm>(key: K, value: MaintenanceForm[K]): void {
  model.value[key] = value
}

const strategy = computed(() => model.value.strategy)
const strategyItems = computed(() => STRATEGIES.map(value => ({
  value,
  title: t(`maintenances.strategy.${value}`),
})))
const zones = computed(() => timezoneOptions(model.value.timezone))
const weekdayItems = [1, 2, 3, 4, 5, 6, 7]
const monthDayItems = computed(() => [
  ...Array.from({ length: 31 }, (_, i) => ({
    value: i + 1,
    title: String(i + 1),
  })),
  {
    value: -1,
    title: t('maintenances.lastDay'),
  },
])

const err = (key: string) => errorFor(props.errors, key)
const recurring = computed(() => strategy.value !== 'once')
const usesStartTime = computed(() => ['interval', 'weekly', 'monthly'].includes(strategy.value))

function onStrategy(value: MaintenanceStrategy): void {
  set('strategy', value)
}
</script>

<template>
  <v-select
    :model-value="model.strategy"
    data-test="maintenance-strategy"
    name="maintenance_strategy"
    :items="strategyItems"
    :label="t('maintenances.fields.strategy')"
    :error-messages="err('strategy')"
    @update:model-value="onStrategy"
  />
  <v-autocomplete
    :model-value="model.timezone"
    data-test="maintenance-timezone"
    name="maintenance_timezone"
    :items="zones"
    :label="t('maintenances.fields.timezone')"
    :hint="t('maintenances.hints.timezone')"
    persistent-hint
    :error-messages="err('timezone')"
    @update:model-value="set('timezone', $event ?? '')"
  />

  <template v-if="strategy === 'once'">
    <v-text-field
      :model-value="model.starts_at"
      type="datetime-local"
      name="maintenance_starts_at"
      :label="`${t('maintenances.fields.startsAt')} (${model.timezone}) *`"
      :error-messages="err('starts_at')"
      class="mt-4"
      @update:model-value="set('starts_at', $event ?? '')"
    />
    <v-text-field
      :model-value="model.ends_at"
      type="datetime-local"
      name="maintenance_ends_at"
      :label="`${t('maintenances.fields.endsAt')} (${model.timezone})`"
      :hint="t('maintenances.hints.endsAt')"
      persistent-hint
      :error-messages="err('ends_at')"
      @update:model-value="set('ends_at', $event ?? '')"
    />
  </template>

  <template v-else>
    <v-text-field
      v-if="usesStartTime"
      :model-value="model.start_time"
      type="time"
      name="maintenance_start_time"
      :label="`${t('maintenances.fields.startTime')} (${model.timezone}) *`"
      :error-messages="err('start_time')"
      class="mt-4"
      @update:model-value="set('start_time', $event ?? '')"
    />
    <v-text-field
      v-if="strategy === 'interval'"
      :model-value="model.every_days"
      type="number"
      min="1"
      max="3650"
      name="maintenance_every_days"
      :label="`${t('maintenances.fields.everyDays')} *`"
      :error-messages="err('every_days')"
      @update:model-value="set('every_days', toInt($event))"
    />
    <div v-if="strategy === 'weekly'" class="mb-4">
      <v-chip-group
        :model-value="model.weekdays"
        multiple
        column
        data-test="maintenance-weekdays"
        :aria-label="t('maintenances.fields.weekdays')"
        @update:model-value="set('weekdays', $event)"
      >
        <v-chip
          v-for="day in weekdayItems"
          :key="day"
          :value="day"
          filter
          variant="outlined"
        >
          {{ t(`maintenances.weekday.${day}`) }}
        </v-chip>
      </v-chip-group>
      <div v-if="err('weekdays')" class="text-error text-caption">
        {{ err('weekdays') }}
      </div>
    </div>
    <v-select
      v-if="strategy === 'monthly'"
      :model-value="model.month_days"
      data-test="maintenance-month-days"
      name="maintenance_month_days"
      :items="monthDayItems"
      :label="`${t('maintenances.fields.monthDays')} *`"
      :hint="t('maintenances.hints.monthDays')"
      persistent-hint
      multiple
      chips
      closable-chips
      :error-messages="err('month_days')"
      @update:model-value="set('month_days', $event)"
    />
    <v-text-field
      v-if="strategy === 'cron'"
      :model-value="model.cron"
      name="maintenance_cron"
      :label="`${t('maintenances.fields.cron')} (${model.timezone}) *`"
      :hint="t('maintenances.hints.cron')"
      persistent-hint
      maxlength="100"
      autocomplete="off"
      spellcheck="false"
      :error-messages="err('cron')"
      class="mt-4"
      @update:model-value="set('cron', $event ?? '')"
    />
    <v-text-field
      v-if="recurring"
      :model-value="model.duration_minutes"
      type="number"
      min="1"
      max="10080"
      name="maintenance_duration"
      :label="`${t('maintenances.fields.duration')} *`"
      :error-messages="err('duration_minutes')"
      @update:model-value="set('duration_minutes', toInt($event))"
    />
    <v-text-field
      :model-value="model.valid_from"
      type="date"
      name="maintenance_valid_from"
      :label="t('maintenances.fields.validFrom')"
      :hint="t('maintenances.hints.validity')"
      persistent-hint
      :error-messages="err('valid_from')"
      @update:model-value="set('valid_from', $event ?? '')"
    />
    <v-text-field
      :model-value="model.valid_until"
      type="date"
      name="maintenance_valid_until"
      :label="t('maintenances.fields.validUntil')"
      :error-messages="err('valid_until')"
      @update:model-value="set('valid_until', $event ?? '')"
    />
  </template>
</template>
