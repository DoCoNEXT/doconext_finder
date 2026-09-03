<template>
  <NcContent :app-name="appId">
    <NcAppNavigation>
      <template #list>
        <NcAppNavigationItem :name="t('Search')"
                             :active="page === 'search'"
                             @click="page = 'search'">
          <template #icon>
            <NcIconSvgWrapper :path="mdiMagnify" :size="20" />
          </template>
        </NcAppNavigationItem>
        <NcAppNavigationItem :name="t('Favorites')"
                             :active="page === 'favorites'"
                             @click="page = 'favorites'">
          <template #icon>
            <NcIconSvgWrapper :path="mdiStar" :size="20" />
          </template>
        </NcAppNavigationItem>
        <NcAppNavigationItem :name="t('Saved searches')"
                             :active="page === 'saved'"
                             @click="page = 'saved'">
          <template #icon>
            <NcIconSvgWrapper :path="mdiContentSaveOutline" :size="20" />
          </template>
        </NcAppNavigationItem>
      </template>
    </NcAppNavigation>

    <NcAppContent>
      <!--
        The three pages are kept alive rather than re-created: switching to
        Saved searches and back should not throw away the results you were
        looking at, which is the whole reason to leave the page.
      -->
      <KeepAlive>
        <SearchView v-if="page === 'search'" @saved="page = 'saved'" />
        <FavoritesView v-else-if="page === 'favorites'" />
        <SavedSearchesView v-else @run="runStored" />
      </KeepAlive>
    </NcAppContent>

    <!--
      NcAppSidebar must be a sibling of NcAppContent, not nested inside a page:
      placed within the content it renders below it instead of beside it.
    -->
    <FileDetails :file="selection.file"
                 @close="selection.clear()"
                 @toggle-favorite="store.toggleFavorite" />
  </NcContent>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import {
  NcAppContent,
  NcAppNavigation,
  NcAppNavigationItem,
  NcContent,
  NcIconSvgWrapper,
} from '@nextcloud/vue'
import { mdiContentSaveOutline, mdiMagnify, mdiStar } from '@mdi/js'
import { useI18n } from './composables/useI18n'
import { useSearchStore } from './stores/searchStore'
import { usePreferencesStore } from './stores/preferencesStore'
import { useSelectionStore } from './stores/selectionStore'
import { APP_ID } from './constants'
import FileDetails from './components/FileDetails.vue'
import SearchView from './views/SearchView.vue'
import FavoritesView from './views/FavoritesView.vue'
import SavedSearchesView from './views/SavedSearchesView.vue'
import type { StoredSearch } from './types/Search'

const { t } = useI18n()
const store = useSearchStore()

const preferences = usePreferencesStore()
const selection = useSelectionStore()

const appId = APP_ID

// Loaded once for the whole app: every page shows the same grid.
onMounted(() => {
  preferences.load()
  // Loaded here rather than per page: Favorites needs the metadata labels too.
  store.loadSchema()
})
const page = ref<'search' | 'favorites' | 'saved'>('search')

/**
 * Running a stored search loads it into the live search and switches to it, so
 * the result arrives somewhere you can refine it — not on a list page.
 * @param entry the saved or recent search to run
 */
function runStored(entry: StoredSearch) {
  store.apply(entry.query)
  page.value = 'search'
  // Re-running from history is not itself a new search worth recording; the
  // entry already exists and its timestamp was just bumped.
  store.run(t, 0, entry.kind !== 'saved')
}
</script>

<style scoped lang="scss">
:deep(.app-content-wrapper) {
  padding-top: 16px;
}
</style>
