<script setup lang="ts">
import type { PageForm } from './model'
import { useI18n } from 'vue-i18n'
import CustomDomainField from './CustomDomainField.vue'
import LockedDomainField from './LockedDomainField.vue'
import { LOCALES, UPTIME_WINDOWS } from './model'

defineProps<{
  disabled: boolean
  /** Whether the caller may set the custom domain; the field is hidden otherwise. */
  canCustomDomain: boolean
  /**
   * The stored domain while custom domains cannot be set (empty when there is none): it is shown read-only
   * so it is not forgotten, and can at most be removed.
   */
  lockedDomain?: string
  /** Whether the locked domain may be removed; the API lets a workspace admin do that. */
  canRemoveDomain?: boolean
  errors: Record<string, string>
}>()

const form = defineModel<PageForm>({ required: true })
const { t } = useI18n()
</script>

<template>
  <div>
    <v-text-field
      v-model="form.title"
      name="status_title"
      :label="`${t('statusPages.fields.title')} *`"
      maxlength="190"
      autocomplete="off"
      :disabled="disabled"
      :error-messages="errors.title"
    />
    <v-text-field
      v-model="form.slug"
      name="status_slug"
      :label="`${t('statusPages.fields.slug')} *`"
      :hint="t('statusPages.hints.slug', { slug: form.slug || '…' })"
      persistent-hint
      maxlength="64"
      autocomplete="off"
      :disabled="disabled"
      :error-messages="errors.slug"
    />
    <v-textarea
      v-model="form.description"
      name="status_description"
      :label="t('statusPages.fields.description')"
      rows="2"
      maxlength="10000"
      class="mt-2"
      :disabled="disabled"
      :error-messages="errors.description"
    />
    <v-select
      v-model="form.locale"
      data-test="status-locale"
      :items="LOCALES.map(l => ({ value: l, title: t(`statusPages.locale.${l}`) }))"
      :label="t('statusPages.fields.locale')"
      :disabled="disabled"
      :error-messages="errors.locale"
    />
    <v-switch
      v-model="form.published"
      name="status_published"
      :label="t('statusPages.fields.published')"
      :hint="t('statusPages.hints.published')"
      persistent-hint
      color="primary"
      inset
      :disabled="disabled"
      :error-messages="errors.published"
    />

    <CustomDomainField
      v-if="canCustomDomain"
      v-model="form.custom_domain"
      class="mt-4"
      :disabled="disabled"
      :error="errors.custom_domain"
    />
    <LockedDomainField
      v-else-if="lockedDomain"
      v-model="form.custom_domain"
      class="mt-4"
      :stored="lockedDomain"
      :disabled="disabled || !canRemoveDomain"
      :error="errors.custom_domain"
    />

    <h2 class="text-h6 mt-6 mb-2">
      {{ t('statusPages.display.title') }}
    </h2>
    <v-row dense>
      <v-col cols="12" sm="6">
        <v-text-field
          v-model.number="form.bar_days"
          name="status_bar_days"
          type="number"
          min="7"
          max="365"
          :label="t('statusPages.fields.barDays')"
          :hint="t('statusPages.hints.barDays')"
          persistent-hint
          :disabled="disabled"
          :error-messages="errors.bar_days"
        />
      </v-col>
      <v-col cols="12" sm="6">
        <v-select
          v-model="form.uptime_days"
          data-test="status-uptime-days"
          :items="UPTIME_WINDOWS.map(d => ({ value: d, title: t('statusPages.uptimeDays', { count: d }) }))"
          :label="t('statusPages.fields.uptimeDays')"
          :hint="t('statusPages.hints.uptimeDays')"
          persistent-hint
          :disabled="disabled"
          :error-messages="errors.uptime_days"
        />
      </v-col>
      <v-col cols="12" sm="6">
        <v-text-field
          v-model.number="form.incident_history_days"
          name="status_incident_history_days"
          type="number"
          min="0"
          max="90"
          :label="t('statusPages.fields.incidentHistoryDays')"
          :hint="t('statusPages.hints.incidentHistoryDays')"
          persistent-hint
          :disabled="disabled"
          :error-messages="errors.incident_history_days"
        />
      </v-col>
      <v-col cols="12" sm="6">
        <v-text-field
          v-model.number="form.upcoming_maintenance_days"
          name="status_upcoming_maintenance_days"
          type="number"
          min="0"
          max="90"
          :label="t('statusPages.fields.upcomingMaintenanceDays')"
          :hint="t('statusPages.hints.upcomingMaintenanceDays')"
          persistent-hint
          :disabled="disabled"
          :error-messages="errors.upcoming_maintenance_days"
        />
      </v-col>
    </v-row>
    <v-switch
      v-model="form.show_latency"
      name="status_show_latency"
      :label="t('statusPages.fields.showLatency')"
      color="primary"
      inset
      :disabled="disabled"
      :error-messages="errors.show_latency"
    />

    <h2 class="text-h6 mt-4 mb-2">
      {{ t('statusPages.branding.title') }}
    </h2>
    <v-text-field
      v-model="form.accent_color"
      name="status_accent_color"
      :label="t('statusPages.fields.accentColor')"
      :hint="t('statusPages.hints.accentColor')"
      placeholder="#2563eb"
      persistent-hint
      maxlength="7"
      autocomplete="off"
      :disabled="disabled"
      :error-messages="errors.accent_color"
    />
    <v-text-field
      v-model="form.logo_url"
      name="status_logo_url"
      type="url"
      :label="t('statusPages.fields.logoUrl')"
      :hint="t('statusPages.hints.https')"
      persistent-hint
      maxlength="2048"
      class="mt-2"
      :disabled="disabled"
      :error-messages="errors.logo_url"
    />
    <v-text-field
      v-model="form.favicon_url"
      name="status_favicon_url"
      type="url"
      :label="t('statusPages.fields.faviconUrl')"
      :hint="t('statusPages.hints.https')"
      persistent-hint
      maxlength="2048"
      class="mt-2"
      :disabled="disabled"
      :error-messages="errors.favicon_url"
    />
    <v-textarea
      v-model="form.footer"
      name="status_footer"
      :label="t('statusPages.fields.footer')"
      rows="2"
      maxlength="2000"
      class="mt-2"
      :disabled="disabled"
      :error-messages="errors.footer"
    />
    <v-textarea
      v-model="form.custom_css"
      name="status_custom_css"
      :label="t('statusPages.fields.customCss')"
      rows="3"
      maxlength="20000"
      class="mt-2"
      :disabled="disabled"
      :error-messages="errors.custom_css"
    />
  </div>
</template>
