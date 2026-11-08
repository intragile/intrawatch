<script setup lang="ts">
import { mdiTranslate, mdiWeatherNight, mdiWeatherSunny } from '@mdi/js'
import { useI18n } from 'vue-i18n'
import AppLogo from '../components/common/AppLogo.vue'
import { usePreferences } from '../composables/usePreferences'

const { t } = useI18n()
const { isDark, toggleTheme, locale, locales, setLocale } = usePreferences()
</script>

<template>
  <v-main>
    <v-container class="auth-container">
      <div class="d-flex align-center mb-6">
        <AppLogo :size="32" />
        <v-spacer />
        <v-menu>
          <template #activator="{ props: activator }">
            <v-btn v-bind="activator" variant="text" :prepend-icon="mdiTranslate" :aria-label="t('locale.label')">
              {{ locale.toUpperCase() }}
            </v-btn>
          </template>
          <v-list density="compact" :aria-label="t('locale.label')">
            <v-list-item
              v-for="code in locales"
              :key="code"
              :active="code === locale"
              :title="t(`locale.${code}`)"
              :lang="code"
              @click="setLocale(code)"
            />
          </v-list>
        </v-menu>
        <v-btn
          icon
          variant="text"
          :aria-label="isDark ? t('theme.toLight') : t('theme.toDark')"
          @click="toggleTheme"
        >
          <v-icon :icon="isDark ? mdiWeatherSunny : mdiWeatherNight" />
        </v-btn>
      </div>
      <v-card class="auth-card">
        <slot />
      </v-card>
    </v-container>
  </v-main>
</template>

<style scoped>
.auth-container {
  max-width: 460px;
  padding-top: 10vh;
}

.auth-card {
  padding: 12px 8px 8px;
}

.auth-card :deep(.v-card-title) {
  font-family: 'Barlow Semi Condensed', 'Barlow', system-ui, sans-serif;
  font-size: 1.5rem !important;
  font-weight: 600;
  line-height: 1.25;
  white-space: normal;
  padding-bottom: 16px;
}
</style>
