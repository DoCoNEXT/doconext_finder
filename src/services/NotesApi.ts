/**
 * Thin API client for the notes endpoints. @nextcloud/axios handles the CSRF
 * token + session cookie automatically. Request bodies are snake_case; the
 * server returns camelCase.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import type { Note } from '../types/Note'

const url = (path: string) => generateUrl(`/apps/doconext_finder/api${path}`)

export const NotesApi = {
	async list(): Promise<Note[]> {
		const { data } = await axios.get<Note[]>(url('/notes'))
		return data
	},

	async create(title: string, content: string): Promise<Note> {
		const { data } = await axios.post<Note>(url('/notes'), { title, content })
		return data
	},

	async remove(id: number): Promise<void> {
		await axios.delete(url(`/notes/${id}`))
	},
}
