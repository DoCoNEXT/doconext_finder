/** API return shape for a note (camelCase — matches Note::toArray() in PHP). */
export interface Note {
	id: number
	title: string
	content: string
	createdAt: string
}
