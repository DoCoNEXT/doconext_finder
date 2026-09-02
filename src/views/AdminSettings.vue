<template>
  <NcSettingsSection :name="PRODUCT_NAME" :description="t('Admin settings for this app.')">
    <p class="hint">
      {{ t('Add your admin controls here (e.g. a display-name field, feature toggles). Wire them to a backend endpoint + app-config.') }}
    </p>
  </NcSettingsSection>
</template>

<script setup lang="ts">
import { NcSettingsSection } from '@nextcloud/vue'
import { useI18n } from '../composables/useI18n'
import { PRODUCT_NAME } from '../constants'

const { t } = useI18n()
</script>

<style scoped lang="scss">
.hint {
	color: var(--color-text-maxcontrast);
}
</style>
