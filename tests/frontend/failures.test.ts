import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import type { FileResult } from '../../src/types/Search'

// What the user is told when the server says no. Every case here was once
// silent: a setting that looked saved and was not, an empty list that read as
// "your searches are gone", a star that sprang back without a word.
//
// The server is replaced wholesale, so these run without a page or a network.

const showError = vi.fn()
vi.mock('@nextcloud/dialogs', () => ({ showError, showSuccess: vi.fn() }))

const api = {
	savePreferences: vi.fn(),
	history: vi.fn(),
	setFavorite: vi.fn(),
}
vi.mock('../../src/services/SearchApi', () => ({ SearchApi: api }))

const ai = { distil: vi.fn(), poll: vi.fn() }
vi.mock('../../src/services/AiApi', () => ({ AiApi: ai }))

// The distiller is a composable and registers a teardown hook; outside a
// component there is nothing to register it on, which is all Vue would warn about.
vi.mock('vue', async (original) => ({ ...(await original<typeof import('vue')>()), onBeforeUnmount: vi.fn() }))

/** Lets a save that was started but not awaited run to its end. */
const settled = () => new Promise((resolve) => setTimeout(resolve, 0))

const PREFERENCES = {
	columns: [{ id: 'name', visible: true, label: '' }],
	grouping: [],
	favoritesGrouping: [],
	pageSize: 50,
	sort: 'mtime',
	descending: true,
	doubleClick: 'open',
	sidebarPinned: false,
	highlightColor: '',
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.clearAllMocks()
})

describe('preferences', () => {
	it('puts a setting back, and says so, when saving it fails', async () => {
		const { usePreferencesStore } = await import('../../src/stores/preferencesStore')
		const preferences = usePreferencesStore()
		preferences.adopt(structuredClone(PREFERENCES))
		api.savePreferences.mockRejectedValue(new Error('500'))

		preferences.setPageSize(200)
		await settled()

		expect(preferences.pageSize).toBe(50)
		expect(showError).toHaveBeenCalledOnce()
	})

	it('keeps what the server stored when saving succeeds', async () => {
		const { usePreferencesStore } = await import('../../src/stores/preferencesStore')
		const preferences = usePreferencesStore()
		preferences.adopt(structuredClone(PREFERENCES))
		api.savePreferences.mockResolvedValue({ ...structuredClone(PREFERENCES), pageSize: 200 })

		preferences.setPageSize(200)
		await settled()

		expect(preferences.pageSize).toBe(200)
		expect(showError).not.toHaveBeenCalled()
	})
})

describe('saved and recent searches', () => {
	it('records a failed load, so the page can say so instead of showing empty lists', async () => {
		const { useHistoryStore } = await import('../../src/stores/historyStore')
		const history = useHistoryStore()
		api.history.mockRejectedValue(new Error('unreachable'))

		await history.load()

		expect(history.error).not.toBe('')
		expect(history.saved).toEqual([])
	})
})

describe('favorites', () => {
	it('springs the star back and says why when the server refuses', async () => {
		const { useSearchStore } = await import('../../src/stores/searchStore')
		const file = { fileid: 7, name: 'contract.pdf', favorite: false } as FileResult
		api.setFavorite.mockRejectedValue(new Error('403'))

		await useSearchStore().toggleFavorite(file)

		expect(file.favorite).toBe(false)
		expect(showError).toHaveBeenCalledOnce()
	})
})

describe('plain-language questions', () => {
	const ask = async () => {
		const { useDistiller } = await import('../../src/composables/useDistiller')
		const distiller = useDistiller()
		await distiller.distil((text: string) => text, 'pdfs from last week', {} as never, null, null)

		return distiller.outcome.value
	}

	it('says "too many questions" when the server rate-limits', async () => {
		ai.distil.mockRejectedValue({ response: { status: 429 } })

		expect(await ask()).toBe('busy')
	})

	it('says it could not understand when anything else fails', async () => {
		ai.distil.mockRejectedValue({ response: { status: 500 } })

		expect(await ask()).toBe('failed')
	})
})
