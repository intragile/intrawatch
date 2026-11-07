<script setup lang="ts">
import { mdiHammerWrench } from '@mdi/js'
import { useI18n } from 'vue-i18n'
import EmptyState from '../components/common/EmptyState.vue'

const { t } = useI18n()
</script>

<template>
  <EmptyState :icon="mdiHammerWrench" :title="t('placeholder.title')" :text="t('placeholder.text')" />
</template>
