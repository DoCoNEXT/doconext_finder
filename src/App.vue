<template>
	<NcContent :app-name="appId">
		<NcAppContent>
			<div class="app-template">
				<h2 class="app-template__title">{{ productName }}</h2>
				<p class="app-template__intro">
					{{ t('This is the DoCoNEXT app template. Replace this page with your app.') }}
				</p>
				<NoteList />
			</div>
		</NcAppContent>
	</NcContent>
</template>

<script setup lang="ts">
import { NcContent, NcAppContent } from '@nextcloud/vue'
import { useI18n } from './composables/useI18n'
import { APP_ID, productName } from './constants'
import NoteList from './components/NoteList.vue'

const { t } = useI18n()
const appId = APP_ID
</script>

<style scoped lang="scss">
.app-template {
	max-width: 640px;
	margin: 0 auto;
	padding: 24px;

	&__title {
		font-size: 22px;
		font-weight: 700;
		margin-bottom: 4px;
	}

	&__intro {
		color: var(--color-text-maxcontrast);
		margin-bottom: 16px;
	}
}
</style>
