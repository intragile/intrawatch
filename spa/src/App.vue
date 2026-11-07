<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import PasswordConfirmDialog from './components/common/PasswordConfirmDialog.vue'
import ReloginDialog from './components/common/ReloginDialog.vue'
import { useNotify } from './composables/useNotify'
import AppLayout from './layouts/AppLayout.vue'
import AuthLayout from './layouts/AuthLayout.vue'

const route = useRoute()
const { t } = useI18n()
const { notices, dismiss } = useNotify()

const notice = computed(() => notices.value[0] ?? null)
</script>

<template>
  <v-app>
    <AuthLayout v-if="route.meta.layout === 'auth'">
      <router-view />
    </AuthLayout>
    <AppLayout v-else-if="route.matched.length > 0">
      <router-view />
    </AppLayout>

    <ReloginDialog />
    <PasswordConfirmDialog />

    <v-snackbar
      :key="notice?.id"
      :model-value="notice !== null"
      :color="notice?.color"
      timeout="5000"
      role="status"
      @update:model-value="notice && dismiss(notice.id)"
    >
      {{ notice?.text }}
      <template #actions>
        <v-btn variant="text" @click="notice && dismiss(notice.id)">
          {{ t('common.close') }}
        </v-btn>
      </template>
    </v-snackbar>
  </v-app>
</template>
