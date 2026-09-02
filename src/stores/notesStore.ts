/** Pinia store for notes — the example state container. */
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { NotesApi } from '../services/NotesApi'
import type { Note } from '../types/Note'

export const useNotesStore = defineStore('notes', () => {
	const notes = ref<Note[]>([])
	const loading = ref(false)

	async function load(): Promise<void> {
		loading.value = true
		try {
			notes.value = await NotesApi.list()
		} finally {
			loading.value = false
		}
	}

	async function add(title: string, content: string): Promise<void> {
		const note = await NotesApi.create(title, content)
		notes.value.unshift(note)
	}

	async function remove(id: number): Promise<void> {
		await NotesApi.remove(id)
		notes.value = notes.value.filter((n) => n.id !== id)
	}

	return { notes, loading, load, add, remove }
})
