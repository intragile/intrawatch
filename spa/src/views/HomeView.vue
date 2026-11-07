<script setup lang="ts">
import { mdiDomainPlus } from '@mdi/js'
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import EmptyState from '../components/common/EmptyState.vue'
import CreateWorkspaceDialog from './workspace/CreateWorkspaceDialog.vue'

const { t } = useI18n()

const dialog = ref(false)
</script>

<template>
  <EmptyState
    :icon="mdiDomainPlus"
    :title="t('home.empty.title')"
    :text="t('home.empty.text')"
  >
    <template #actions>
      <v-btn color="primary" variant="flat" @click="dialog = true">
        {{ t('home.create') }}
      </v-btn>
    </template>
  </EmptyState>

  <CreateWorkspaceDialog v-model="dialog" />
</template>
