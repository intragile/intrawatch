<script setup lang="ts">
import { mdiTranslate, mdiWeatherNight, mdiWeatherSunny } from '@mdi/js'
import { useI18n } from 'vue-i18n'
import { usePreferences } from '../composables/usePreferences'

const { t } = useI18n()
const { isDark, toggleTheme, locale, locales, setLocale } = usePreferences()
</script>

<template>
  <v-main>
    <v-container class="auth-container">
      <div class="d-flex align-center mb-4">
        <span class="text-h5 font-weight-medium">{{ t('app.name') }}</span>
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
      <v-card>
        <slot />
      </v-card>
    </v-container>
  </v-main>
</template>

<style scoped>
.auth-container {
  max-width: 480px;
  padding-top: 8vh;
}
</style>
