<template>
  <NcContent :app-name="appId" :class="{ 'finder-app--rail': railed }">
    <NcAppNavigation :aria-label="productName">
      <template #list>
        <NcAppNavigationItem :name="t('Search')"
                             :active="page === 'search'"
                             @click="go('search')">
          <template #icon>
            <NcIconSvgWrapper :path="mdiMagnify" :size="20" />
          </template>
        </NcAppNavigationItem>
        <NcAppNavigationItem :name="t('Favorites')"
                             :active="page === 'favorites'"
                             @click="go('favorites')">
          <template #icon>
            <NcIconSvgWrapper :path="mdiStar" :size="20" />
          </template>
        </NcAppNavigationItem>
        <NcAppNavigationItem :name="t('Searches')"
                             :active="page === 'searches'"
                             @click="go('searches')">
          <template #icon>
            <NcIconSvgWrapper :path="mdiContentSaveOutline" :size="20" />
          </template>
        </NcAppNavigationItem>
      </template>

      <!--
        Settings belongs at the foot of the navigation, where Nextcloud's own
        apps put it: it is not one of the places you work, it is where you go to
        change how they behave.
      -->
      <template #footer>
        <ul class="finder-nav__footer">
          <NcAppNavigationItem :name="t('Settings')"
                               :active="page === 'settings'"
                               @click="go('settings')">
            <template #icon>
              <NcIconSvgWrapper :path="mdiCogOutline" :size="20" />
            </template>
          </NcAppNavigationItem>
        </ul>
      </template>
    </NcAppNavigation>

    <NcAppContent>
      <!--
        The three pages are kept alive rather than re-created: switching to
        Searches and back should not throw away the results you were looking at,
        which is the whole reason to leave the page.
      -->
      <KeepAlive>
        <SearchView v-if="page === 'search'" @saved="go('searches')" />
        <FavoritesView v-else-if="page === 'favorites'" ref="favorites" />
        <SearchesView v-else-if="page === 'searches'" @run="runStored" />
        <SettingsView v-else />
      </KeepAlive>
    </NcAppContent>

    <!--
      NcAppSidebar must be a sibling of NcAppContent, not nested inside a page:
      placed within the content it renders below it instead of beside it.

      Only the pages that list results get it — the panel describes a result, so
      it has nothing to say next to the searches list or the settings.
    -->
    <FileDetails v-if="showsResults"
                 :file="selection.file"
                 @close="selection.clear()"
                 @changed="refreshList"
                 @toggle-favorite="toggleFavorite" />

    <FilePreviewDialog />
  </NcContent>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import {
  NcAppContent,
  NcAppNavigation,
  NcAppNavigationItem,
  NcContent,
  NcIconSvgWrapper,
} from '@nextcloud/vue'
import { mdiCogOutline, mdiContentSaveOutline, mdiMagnify, mdiStar } from '@mdi/js'
import { useI18n } from './composables/useI18n'
import { useNavigationRail } from './composables/useNavigationRail'
import { useSearchStore } from './stores/searchStore'
import { usePreferencesStore } from './stores/preferencesStore'
import { useSelectionStore } from './stores/selectionStore'
import { useHistoryStore } from './stores/historyStore'
import { APP_ID, PRODUCT_NAME } from './constants'
import FileDetails from './components/FileDetails.vue'
import FilePreviewDialog from './components/FilePreviewDialog.vue'
import SearchView from './views/SearchView.vue'
import FavoritesView from './views/FavoritesView.vue'
import SearchesView from './views/SearchesView.vue'
import SettingsView from './views/SettingsView.vue'
import type { FileResult, StoredSearch } from './types/Search'

type Page = 'search' | 'favorites' | 'searches' | 'settings'

const { t } = useI18n()
const store = useSearchStore()

const preferences = usePreferencesStore()
const selection = useSelectionStore()
const history = useHistoryStore()

const { railed } = useNavigationRail()

const appId = APP_ID
const productName = PRODUCT_NAME

// Loaded once for the whole app: every page shows the same grid.
onMounted(() => {
  preferences.load()
  // Loaded here rather than per page: Favorites needs the metadata labels too.
  store.loadSchema()
})

const page = ref<Page>('search')

/** Held so a command in the sidebar can make the favorites list re-read itself. */
const favorites = ref<InstanceType<typeof FavoritesView> | null>(null)

const showsResults = computed(() => page.value === 'search' || page.value === 'favorites')

/**
 * Switching page drops the selection. Search results and favorites are separate
 * lists, and the panel described a row of the one you just left — carrying it
 * across showed a file that is not in the list under it.
 * @param next the page to show
 */
function go(next: Page) {
  if (next !== page.value) {
    selection.clear()
  }
  page.value = next
  if (next === 'searches') {
    history.load(true)
  }
}

/**
 * Re-reads whichever list is on screen. A command in the details panel — a new
 * version, an upload — changed the file on the server, and the row beside the
 * panel is now describing what used to be there.
 */
function refreshList() {
  if (page.value === 'favorites') {
    favorites.value?.load()
  } else {
    store.run(t, store.offset, false)
  }
}

/**
 * The star in the sidebar header. On the favorites page unstarring removes the
 * row, so the list has to be re-read — otherwise the panel would sit next to a
 * file that no longer belongs to the list under it.
 * @param file the file the panel is describing
 */
async function toggleFavorite(file: FileResult) {
  await store.toggleFavorite(file)
  if (page.value === 'favorites') {
    favorites.value?.load()
  }
}

/**
 * Running a stored search loads it into the live search and switches to it, so
 * the result arrives somewhere you can refine it — not on a list page.
 * @param entry the saved or recent search to run
 */
function runStored(entry: StoredSearch) {
  store.apply(entry.query)
  go('search')
  // Re-running from history is not itself a new search worth recording; the
  // entry already exists and its timestamp was just bumped.
  store.run(t, 0, entry.kind !== 'saved')
}
</script>

<style scoped lang="scss">
// The pages lay themselves out as full-height columns so their controls can stay
// put while the results scroll; that only works if their parent hands down its
// height rather than growing with the content.
//
// That parent is <main class="app-content"> itself: NcAppContent only wraps the
// default slot in .app-content-wrapper when it also has a `list` slot, and this
// app has none — so the rule that used to name the wrapper matched nothing.
//
// The top padding is not decoration: the navigation toggle floats over the top
// inline-start corner of the content, and without the clearance it sat on the
// first row of controls. It ends one clickable area below its own offset, so
// the clearance has to clear that, not merely approach it.
:deep(.app-content) {
  display: flex;
  flex-direction: column;
  box-sizing: border-box;
  min-height: 0;
  padding-top: calc(var(--default-clickable-area, 44px) + var(--app-navigation-padding, 8px) * 2);
}

// Collapsed to a rail: the sidebar keeps its width for one icon per page
// instead of sliding out of view. See useNavigationRail for why the component
// needs the help.
.finder-app--rail {
  :deep(.app-navigation--closed) {
    // !important beats the library's own scoped rule, which has the same
    // specificity and no guaranteed order relative to ours.
    margin-inline-start: 0 !important;
    width: calc(var(--default-clickable-area) + var(--app-navigation-padding) * 2);
    // No overflow clipping here, however tempting: the toggle that opens the
    // navigation back up is positioned outside this box, and hiding the
    // overflow takes the only way out of the rail with it.

    // Only the icon survives; everything that needs the missing width goes.
    .app-navigation-entry__name,
    .app-navigation-entry__utils,
    .app-navigation-entry__counter-wrapper,
    .app-navigation-caption,
    .app-navigation-entry__children {
      display: none;
    }

    // The list reserves a scrollbar gutter unconditionally. At full width that
    // costs nothing; at rail width it takes a third of the entry, and the icon
    // — a fixed square that does not shrink — is pushed inline-start until it
    // sits on the 3px stripe that marks the active page. Give the entry its
    // width back rather than nudging the icon to compensate.
    .app-navigation__body {
      overflow-y: auto;
      scrollbar-width: none;
    }

    // The end padding keeps a label off the edge. With no label it only eats
    // into the icon's own box, which is already exactly one clickable area —
    // the same box, at the same inline offset, as when the labels are there.
    // Nothing centres the icon: that is what moved it in the first place.
    .app-navigation-entry-link,
    .app-navigation-entry-button {
      padding-inline-end: 0;
    }
  }
}

// The footer slot sits outside NcAppNavigationList, so it brings its own list
// element — an NcAppNavigationItem renders an <li> and needs one.
.finder-nav__footer {
  list-style: none;
  margin: 0;
  padding: var(--app-navigation-padding, 8px);
  border-top: 1px solid var(--color-border);
}
</style>
