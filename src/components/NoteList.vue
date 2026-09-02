<template>
	<div class="notes">
		<!-- Add form -->
		<form class="notes__add" @submit.prevent="onAdd">
			<input v-model="title"
				class="notes__input"
				type="text"
				:placeholder="t('Note title…')">
			<NcButton variant="primary" :disabled="!title.trim() || adding" @click="onAdd">
				<template v-if="adding" #icon><NcLoadingIcon :size="18" /></template>
				{{ t('Add') }}
			</NcButton>
		</form>

		<!-- List -->
		<div v-if="store.loading" class="notes__state">
			<NcLoadingIcon :size="20" /> {{ t('Loading…') }}
		</div>
		<div v-else-if="store.notes.length === 0" class="notes__state">
			{{ t('No notes yet.') }}
		</div>
		<ul v-else class="notes__list">
			<li v-for="note in store.notes" :key="note.id" class="notes__item">
				<span class="notes__item-title">{{ note.title }}</span>
				<NcButton variant="tertiary" :aria-label="t('Delete')" @click="store.remove(note.id)">
					<template #icon>
						<!-- Inline icon to keep the template dependency-free; swap for
						     @lucide/vue or vue-material-design-icons in a real app. -->
						<svg width="18" height="18" viewBox="0 0 24 24" fill="none"
							stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
							<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m2 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" />
						</svg>
					</template>
				</NcButton>
			</li>
		</ul>
	</div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import { useI18n } from '../composables/useI18n'
import { useNotesStore } from '../stores/notesStore'

const { t } = useI18n()
const store = useNotesStore()
const title = ref('')
const adding = ref(false)

async function onAdd() {
	if (!title.value.trim()) return
	adding.value = true
	try {
		await store.add(title.value.trim(), '')
		title.value = ''
	} catch (e) {
		showError(t('Could not add note'))
	} finally {
		adding.value = false
	}
}

onMounted(() => { void store.load() })
</script>

<style scoped lang="scss">
.notes {
	display: flex;
	flex-direction: column;
	gap: 12px;

	&__add {
		display: flex;
		gap: 8px;
	}

	&__input {
		flex: 1;
		border: 1px solid var(--color-border-maxcontrast);
		border-radius: var(--border-radius-large, 8px);
		padding: 6px 10px;
	}

	&__state {
		display: flex;
		align-items: center;
		gap: 8px;
		color: var(--color-text-maxcontrast);
		padding: 12px 0;
	}

	&__list {
		display: flex;
		flex-direction: column;
	}

	&__item {
		display: flex;
		align-items: center;
		gap: 8px;
		padding: 8px 4px;
		border-bottom: 1px solid var(--color-border);
	}

	&__item-title {
		flex: 1;
	}
}
</style>
