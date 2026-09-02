// Stub for @nextcloud/l10n in unit tests — identity translation.
export function translate(_app: string, text: string): string {
	return text
}

export function translatePlural(_app: string, singular: string, plural: string, count: number): string {
	return count === 1 ? singular : plural
}
