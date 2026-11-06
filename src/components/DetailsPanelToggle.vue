<template>
  <!--
    The details panel's own toggle, the mirror of the navigation rail's: an
    icon button that opens or closes the panel on its side. It replaced a
    "Details panel" switch among the view options, which read as a setting of
    the list rather than as the panel beside it.
  -->
  <NcButton variant="tertiary"
            :aria-label="label"
            :title="label"
            @click="preferences.setSidebarPinned(!preferences.sidebarPinned)">
    <template #icon>
      <component :is="preferences.sidebarPinned ? PanelRightClose : PanelRightOpen" :size="20" />
    </template>
  </NcButton>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { NcButton } from '@nextcloud/vue'
import { PanelRightClose, PanelRightOpen } from '@lucide/vue'
import { useI18n } from '../composables/useI18n'
import { usePreferencesStore } from '../stores/preferencesStore'

const { t } = useI18n()
const preferences = usePreferencesStore()

const label = computed(() => (preferences.sidebarPinned ? t('Hide details panel') : t('Show details panel')))
</script>
