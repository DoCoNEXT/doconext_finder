<template>
	<NcSettingsSection :name="productName" :description="t('Your personal settings for this app.')">
		<p class="hint">
			{{ t('Add per-user preferences here. Persist them via a backend endpoint + per-user config.') }}
		</p>
	</NcSettingsSection>
</template>

<script setup lang="ts">
import { NcSettingsSection } from '@nextcloud/vue'
import { useI18n } from '../composables/useI18n'
import { productName } from '../constants'

const { t } = useI18n()
</script>

<style scoped lang="scss">
.hint {
	color: var(--color-text-maxcontrast);
}
</style>
