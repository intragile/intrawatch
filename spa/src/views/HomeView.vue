<script setup lang="ts">
import { mdiDomainPlus } from '@mdi/js'
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import EmptyState from '../components/common/EmptyState.vue'
import { useSession } from '../stores/session'
import CreateWorkspaceDialog from './workspace/CreateWorkspaceDialog.vue'

const { t } = useI18n()
const session = useSession()

const dialog = ref(false)
</script>

<template>
  <EmptyState
    :icon="mdiDomainPlus"
    :title="t('home.empty.title')"
    :text="session.canCreateWorkspace ? t('home.empty.text') : t('home.askForInvite')"
  >
    <template v-if="session.canCreateWorkspace" #actions>
      <v-btn color="primary" variant="flat" @click="dialog = true">
        {{ t('home.create') }}
      </v-btn>
    </template>
  </EmptyState>

  <CreateWorkspaceDialog v-if="session.canCreateWorkspace" v-model="dialog" />
</template>
