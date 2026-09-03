<template>
  <NcContent :app-name="appId" :class="railClass">
    <NcAppNavigation :aria-label="productName">
      <!--
        The navigation's header row. The toggle lives in it rather than floating
        over the content the way Nextcloud's own does, which is what lets every
        page start at the top instead of reserving a strip for it; the stylesheet
        explains the trade. It replaces the library's toggle above the mobile
        breakpoint, and an app with something of its own to put up here would
        share the row with it.
      -->
      <template #search>
        <NcButton v-if="canRail"
                  class="nav-rail-toggle"
                  variant="tertiary"
                  :aria-label="railToggleLabel"
                  :title="railToggleLabel"
                  @click="toggleRail">
          <template #icon>
            <NcIconSvgWrapper :svg="railed ? panelLeftOpen : panelLeftClose" :size="20" />
          </template>
        </NcButton>
      </template>

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
            <!--
              A list with a magnifier, not a floppy disk: the page holds recent
              searches as well as saved ones, and a save icon claimed it was
              only the saved half.
            -->
            <NcIconSvgWrapper :path="mdiTextSearch" :size="20" />
          </template>
        </NcAppNavigationItem>
      </template>

      <!--
        Settings belongs at the foot of the navigation, where Nextcloud's own
        apps put it. The preferences themselves live on the app's own Personal
        Settings page, not in here — this just opens it, the way Nextcloud's own
        apps do, so it stays reachable from Settings even without the app open.
      -->
      <template #footer>
        <ul class="finder-nav__footer">
          <NcAppNavigationItem :name="t('Settings')" @click="openSettings">
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
  NcButton,
  NcContent,
  NcIconSvgWrapper,
} from '@nextcloud/vue'
import {
  mdiCogOutline,
  mdiMagnify,
  mdiStar,
  mdiTextSearch,
} from '@mdi/js'
import { generateUrl } from '@nextcloud/router'
import { useI18n } from './composables/useI18n'
import { useNavigationRail } from './composables/useNavigationRail'
import { panelLeftClose, panelLeftOpen } from './icons/panelLeft'
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
import type { FileResult, StoredSearch } from './types/Search'

type Page = 'search' | 'favorites' | 'searches'

const { t } = useI18n()
const store = useSearchStore()

const preferences = usePreferencesStore()
const selection = useSelectionStore()
const history = useHistoryStore()

const { railed, canRail, railClass, toggleRail } = useNavigationRail()

const railToggleLabel = computed(() => (railed.value ? t('Expand menu') : t('Collapse menu')))

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

/**
 * Opens the app's Personal Settings page in its own tab, rather than
 * navigating away from the app in this one.
 */
function openSettings() {
  window.open(generateUrl(`/settings/user/${APP_ID}`), '_blank', 'noopener')
}
</script>

<style scoped lang="scss">
// The rail itself: shared with the other DoCoNEXT apps, so it lives in a
// stylesheet of its own rather than in this component.
@use './styles/navigation-rail';

// The pages lay themselves out as full-height columns so their controls can stay
// put while the results scroll; that only works if their parent hands down its
// height rather than growing with the content.
//
// That parent is <main class="app-content"> itself: NcAppContent only wraps the
// default slot in .app-content-wrapper when it also has a `list` slot, and this
// app has none — so the rule that used to name the wrapper matched nothing.
//
// Below the mobile breakpoint the library's own toggle floats over the top
// inline-start corner of the content, and without clearance it sits on the first
// row of controls; it ends one clickable area below its own offset, so the
// clearance has to clear that rather than merely approach it. Above the
// breakpoint our own toggle lives inside the navigation instead of over the
// content, and the page gets those sixty pixels back — all but the breathing
// room every page wants between the header and its first control, which is set
// here rather than page by page so the three of them start on the same line.
:deep(.app-content) {
  display: flex;
  flex-direction: column;
  box-sizing: border-box;
  min-height: 0;
  padding-top: 12px;
}

@media only screen and (width < 1024px) {
  :deep(.app-content) {
    padding-top: calc(var(--default-clickable-area, 44px) + var(--app-navigation-padding, 8px) * 2);
  }
}

// The footer slot sits outside NcAppNavigationList, so it brings its own list
// element — an NcAppNavigationItem renders an <li> and needs one.
.finder-nav__footer {
  list-style: none;
  margin: 0;
  padding: var(--app-navigation-padding, 8px);
}
</style>
