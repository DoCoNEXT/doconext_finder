<template>
  <div class="finder">
    <!-- The box first, on a line of its own with the two buttons that act on
         it; the filters that narrow it sit underneath. Type and Modified used
         to share the line and pushed the box down to a third of the width. -->
    <div class="finder__bar">
      <SearchBox v-model="searchTerm"
                 class="finder__term"
                 :placeholder="termPlaceholder"
                 :understand="HAS_ENTITY_SCOPE"
                 :understand-disabled="!aiReady || distiller.running.value || store.loading || searchTerm.trim() === ''"
                 :understanding="distiller.running.value"
                 @search="search"
                 @pick="runSuggestion"
                 @understand="askAi" />

      <NcButton variant="primary" :disabled="store.loading" @click="search">
        {{ t('Search') }}
      </NcButton>
      <NcButton :disabled="!store.hasCriteria" @click="save">
        <template #icon>
          <Save :size="20" />
        </template>
        {{ t('Save') }}
      </NcButton>
      <!--
        Clearing by hand meant emptying the box, putting both presets back on
        "any" and deleting every condition row one by one — five actions to get
        back to where the page starts.
      -->
      <NcButton variant="tertiary"
                :disabled="!store.hasCriteria && !store.searched"
                :aria-label="t('New search')"
                :title="t('Clear the term, the filters and the results')"
                @click="newSearch">
        <template #icon>
          <X :size="20" />
        </template>
        {{ t('New search') }}
      </NcButton>
    </div>

    <!--
      Not a filter, so not folded away with them: it does not narrow what
      matches, it widens it, and it changes what the words in the box mean — so
      it sits right under the box, on a line of its own. Tucked in beside the
      filters, or at the far end of their line, it went unnoticed, and hardly
      anyone would find out the files can be searched inside at all. Only
      offered when this server has an index to look inside.
    -->
    <NcCheckboxRadioSwitch v-if="HAS_CONTENT_SEARCH"
                           v-model="searchContent"
                           type="switch"
                           class="finder__contents"
                           :title="t('Also match files whose text mentions the term, not only their names')">
      {{ t('Search file contents too') }}
    </NcCheckboxRadioSwitch>

    <!--
      The filters fold away under one line, and start folded: most searches are a
      term and nothing else, and three rows of controls pushed the results down
      the page for every one of them. Folded is not hidden, though — the line
      says which filters are still narrowing the search, since a filter that
      acts where nobody can see it is the kind nobody thinks to undo.
    -->
    <div class="finder__toggle">
      <NcButton variant="tertiary"
                :aria-expanded="filtersOpen"
                aria-controls="finder-filters"
                @click="filtersOpen = !filtersOpen">
        <template #icon>
          <component :is="filtersOpen ? ChevronDown : ChevronRight" :size="20" />
        </template>
        {{ t('Filters') }}
      </NcButton>
      <span v-if="!filtersOpen && activeFilters"
            class="muted finder__active"
            :title="activeFilters">{{ activeFilters }}</span>
    </div>

    <!--
      v-show, not v-if: the workspace fields load their vocabulary when they
      mount and keep the levels above a chosen entity only in themselves, so
      folding them away must not tear them down.
    -->
    <div v-show="filtersOpen" id="finder-filters" class="finder__panel">
      <!--
        One grid for every field, so the columns line up because they are the
        same columns rather than because every field happens to be as wide.
        The workspace fields join it from their own component and start a line
        of their own.
      -->
      <div class="finder__grid">
        <NcSelect v-model="typeOption"
                  label="label"
                  :options="typeOptions"
                  :clearable="false"
                  :input-label="t('Type')" />

        <NcSelect v-model="timeOption"
                  label="label"
                  :options="timeOptions"
                  :clearable="false"
                  :input-label="t('Modified')" />

        <FolderScope />

        <ScopeSelector />
      </div>

      <!--
        Conditions without a fold of their own: a second fold inside the first
        looked like its sibling rather than its child. "All or any" only means
        something once there are two to combine.
      -->
      <div v-if="store.query.conditions.length > 1" class="finder__match">
        <span>{{ t('Match:') }}</span>
        <NcCheckboxRadioSwitch v-model="matchMode" type="radio" value="all" name="match">
          {{ t('all conditions') }}
        </NcCheckboxRadioSwitch>
        <NcCheckboxRadioSwitch v-model="matchMode" type="radio" value="any" name="match">
          {{ t('any condition') }}
        </NcCheckboxRadioSwitch>
      </div>

      <template v-if="conditionSchema">
        <ConditionRow v-for="(condition, index) in store.query.conditions"
                      :key="index"
                      :condition="condition"
                      :schema="conditionSchema"
                      @update:condition="store.query.conditions.splice(index, 1, $event)"
                      @remove="store.query.conditions.splice(index, 1)" />

        <NcButton variant="tertiary" @click="addCondition">
          <template #icon>
            <Plus :size="20" />
          </template>
          {{ t('Add condition') }}
        </NcButton>
      </template>
      <NcLoadingIcon v-else :size="20" />
    </div>

    <!--
      What the question was understood to mean, and a way to disagree with it.
      Each chip shows only while the query still carries the filter it names, so
      clearing one here, changing it in the filters above, or starting a new
      search all take it off the row without anything having to remember to.
    -->
    <div v-if="scopeChips.length" class="finder__chips">
      <NcChip v-for="chip in scopeChips"
              :key="chip.label"
              :text="chip.label"
              :aria-label="t('Remove {filter}', { filter: chip.label })"
              @close="clearChip(chip)" />
    </div>
    <!--
      A warning, not a muted aside: this says the search on screen is narrower
      than the question that produced it, which is the one thing about a
      distilled scope somebody has to notice.
    -->
    <NcNoteCard v-if="distiller.dropped.value.length" type="warning">
      {{ t('Understood, but not applied — this search does not cover it:') }}
      <ul class="finder__unapplied">
        <li v-for="item in distiller.dropped.value" :key="item">{{ item }}</li>
      </ul>
    </NcNoteCard>
    <p v-if="aiMessage" class="muted finder__dropped">{{ aiMessage }}</p>

    <NcNoteCard v-if="message" :type="messageType">{{ message }}</NcNoteCard>

    <ViewOptions v-if="store.results.length"
                 scope="search"
                 :partial="store.hasMore && !store.loadedAll" />

    <!--
      Reading inside the files is the one part of a search here that is not
      exhaustive: the index answers with its best matches, and the filters
      narrow those. Names are matched in full, so only the other half of the
      list is capped — saying so is the difference between "these are all of
      them" and "these are the ones it found first".
    -->
    <p v-if="rankedResults" class="muted finder__ranked">
      {{ t('Every matching name is listed. Files that only mention the term are the index’s best matches, so there may be more of those.') }}
    </p>

    <div class="finder__results">
      <NcLoadingIcon v-if="store.loading" class="finder__loading" :size="32" />

      <NcEmptyContent v-else-if="store.searched && store.results.length === 0"
                      :name="t('No files found')"
                      :description="t('Try a different term, or loosen the filters.')">
        <template #icon>
          <Search />
        </template>
      </NcEmptyContent>

      <template v-else-if="store.results.length">
        <FileTable :files="store.results"
                   scope="search"
                   :sort="store.query.sort"
                   :descending="store.query.descending"
                   :selected-id="selection.file?.fileid"
                   @sort="store.sortBy(t, $event)"
                   @toggle-favorite="store.toggleFavorite"
                   @changed="refresh"
                   @select="selection.select($event)" />
      </template>
    </div>

    <div v-if="store.results.length" class="finder__paging">
      <template v-if="!store.loadedAll">
        <NcButton :disabled="store.offset === 0 || store.loading" @click="page(-1)">
          {{ t('Previous') }}
        </NcButton>
        <NcButton :disabled="!store.hasMore || store.loading" @click="page(1)">
          {{ t('Next') }}
        </NcButton>
        <NcButton v-if="store.hasMore" :disabled="store.loading" @click="store.loadAll(t)">
          {{ t('Load all') }}
        </NcButton>
      </template>
      <span class="muted">{{ rangeLabel }}</span>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import {
  NcButton,
  NcCheckboxRadioSwitch,
  NcEmptyContent,
  NcLoadingIcon,
  NcChip,
  NcNoteCard,
  NcSelect,
} from '@nextcloud/vue'
import { ChevronDown, ChevronRight, Plus, Save, Search, X } from '@lucide/vue'
import { HAS_CONTENT_SEARCH, HAS_ENTITY_SCOPE } from '../constants'
import { useI18n } from '../composables/useI18n'
import { useSearchStore } from '../stores/searchStore'
import { useSelectionStore } from '../stores/selectionStore'
import { useHistoryStore } from '../stores/historyStore'
import { useSaveSearch } from '../composables/useSaveSearch'
import { useDistiller } from '../composables/useDistiller'
import { AiApi } from '../services/AiApi'
import { anyTime, anyType, canonicalModifiedPreset, fileTypePresets, modifiedPresets } from '../filters/presets'
import { describeQuery } from '../filters/describe'
import ConditionRow from '../components/ConditionRow.vue'
import FolderScope from '../components/FolderScope.vue'
import ScopeSelector from '../components/ScopeSelector.vue'
import FileTable from '../components/FileTable.vue'
import SearchBox from '../components/SearchBox.vue'
import ViewOptions from '../components/ViewOptions.vue'
import type { FileTypePreset, ModifiedPreset } from '../filters/presets'
import type { ScopeChip } from '../filters/distilled'
import type { StoredSearch } from '../types/Search'

const { t } = useI18n()
const store = useSearchStore()

/**
 * Whether the term is looked for inside the files as well as in their names.
 *
 * A toggle beside one box rather than a choice between two: the search used to
 * ask "search in names or in contents", which made a user pick a field before
 * they had asked their question, and quietly emptied the box when they changed
 * their mind. Looking inside is now something a search *also* does, so turning
 * it on can only ever add rows.
 */
const searchContent = computed<boolean>({
  get: () => store.query.searchContent,
  set: (next) => { store.query.searchContent = next },
})

const distiller = useDistiller()

/**
 * Whether Core's AI can actually run. A round trip, unlike everything else this
 * page gates on — Core publishes its name in the capabilities but not yet its
 * AI availability, so there is nothing synchronous to read. Gating whether the
 * button is enabled, rather than whether it exists, keeps the bar from
 * reflowing once the answer lands.
 */
const aiReady = ref(false)

onMounted(async () => {
  if (HAS_ENTITY_SCOPE) {
    aiReady.value = (await AiApi.status()).available
  }
})

const aiMessage = computed(() => {
  switch (distiller.outcome.value) {
    case 'empty': return t('Nothing in that question maps to a filter this workspace has.')
    case 'failed': return t('Could not understand that question. The filters below still work.')
    case 'timeout': return t('Understanding the question took too long. Try again, or set the filters yourself.')
    default: return ''
  }
})

/** The box says how wide its net is, since the control that decides sits below it. */
const termPlaceholder = computed(() => (
  store.query.searchContent
    ? t('Search by name and contents, or pick a recent or saved search…')
    : t('Search by name, or pick a recent or saved search…')
))

const searchTerm = computed({
  get: () => store.query.term,
  set: (value: string) => { store.query.term = value },
})

/**
 * Hands whatever is in the box to Core, applies what comes back, and runs it.
 *
 * The question is consumed: it was a way of describing filters, and leaving it
 * in the box afterwards would search for its words as well as its meaning.
 */
async function askAi() {
  const question = searchTerm.value.trim()
  if (question === '') {
    return
  }

  const realmId = store.query.scope?.level === 'realm' ? store.query.scope.id : null
  const understood = await distiller.distil(t, question, store.query, store.schema, realmId)

  if (understood) {
    store.apply(understood)
    store.run(t, 0)
  }
}
const selection = useSelectionStore()
const history = useHistoryStore()
const { saveSearch } = useSaveSearch()

/**
 * The configured Type filters, plus the distilled one when a question asked for
 * something the list has no name for. Shown as an ordinary option so the filter
 * is visible and can be changed — a filter that acts but does not appear is the
 * kind nobody can correct.
 */
const typeOptions = computed(() => {
  const presets = fileTypePresets(t)
  const custom = store.query.customType

  return custom ? [...presets, custom] : presets
})
const timeOptions = modifiedPresets(t)

onMounted(() => store.loadSchema())

// A new result set makes the old selection meaningless — and the panel would
// otherwise keep describing a file that is no longer on screen.
watch(() => store.results, () => selection.onResults())

/**
 * The store keeps preset *ids* (that is what history stores); the dropdowns bind
 * whole option objects. Translate between the two here rather than storing the
 * objects, so a stored search never carries a stale label.
 */
const typeOption = computed<FileTypePreset>({
  get: () => typeOptions.value.find((o) => o.id === store.query.typePreset) ?? anyType(t),
  set: (next) => {
    store.query.typePreset = next?.id ?? 'any'
    // Picking any other option retires the distilled one: keeping it in the
    // list would offer a type that belongs to a question already answered.
    if (next?.id !== store.query.customType?.id) {
      store.query.customType = null
    }
  },
})

const timeOption = computed<ModifiedPreset>({
  get: () => timeOptions.find((o) => o.id === canonicalModifiedPreset(store.query.modifiedPreset)) ?? anyTime(t),
  set: (next) => { store.query.modifiedPreset = next?.id ?? 'any' },
})

const matchMode = computed<'all' | 'any'>({
  get: () => (store.query.matchAny ? 'any' : 'all'),
  set: (next) => { store.query.matchAny = next === 'any' },
})

/**
 * Whether the filters are unfolded. Starts folded on every visit and is not a
 * preference: it says how much room the controls take, not how anyone searches.
 * The page is kept alive, so it does stay as it was left while switching pages.
 */
const filtersOpen = ref(false)

/**
 * The filters in force, for the folded line. Neither the term nor the contents
 * switch is one of them: both stay on screen whether the filters are folded or not.
 */
/** The schema the condition rows label their fields from, named for the scope. */
const conditionSchema = computed(() => store.schema && { ...store.schema, metadata: store.metadata })

const activeFilters = computed(() => describeQuery(t, { ...store.query, searchContent: false }, store.schema?.metadata))

const rangeLabel = computed(() => {
  const first = store.offset + 1
  const last = store.offset + store.results.length
  if (store.loadedAll) {
    return t('Showing all {count}', { count: store.results.length })
  }
  return store.hasMore
    ? t('Showing {first}–{last}+', { first, last })
    : t('Showing {first}–{last}', { first, last })
})

/** Store errors are codes for cases the view words itself; the rest pass through. */
const message = computed(() => {
  switch (store.error) {
    case '': return ''
    case 'no-criteria': return t('Enter a search term, or pick a filter.')
    case 'capped': return t('Stopped after {count} results. Narrow the search to see the rest.', { count: store.results.length })
    case 'scope-truncated': return t('This scope covers too many folders to search at once. Pick a dossier type or a single dossier.')
    default: return store.error
  }
})

/**
 * The distilled chips that are still true of the query.
 *
 * Derived rather than stored: the filters they name are ordinary parts of the
 * search, editable in their own controls, and a chip that outlived its filter
 * would be claiming something the search no longer does.
 */
const scopeChips = computed(() => distiller.chips.value.filter((chip) => {
  switch (chip.kind) {
    case 'type':
      return store.query.typePreset !== 'any'
    case 'topic':
      return store.query.term.trim() !== ''
    default:
      return store.query.conditions.some((c) => c.field === chip.field && c.value === chip.value)
  }
}))

/**
 * @param chip the chip to take back out of the query
 */
function clearChip(chip: ScopeChip) {
  if (chip.kind === 'type') {
    store.query.typePreset = 'any'
    store.query.customType = null
  } else if (chip.kind === 'topic') {
    store.query.term = ''
  } else {
    store.query.conditions = store.query.conditions.filter(
      (c) => !(c.field === chip.field && c.value === chip.value),
    )
  }
}

/** True while part of what is on screen came from the index rather than the filecache. */
const rankedResults = computed(() => (
  store.results.length > 0 && store.query.searchContent && store.query.term.trim() !== ''
))

const messageType = computed(() => (
  store.error === 'capped' || store.error === 'scope-truncated' ? 'warning' : 'error'
))

function search() {
  store.run(t, 0)
}

/**
 * Clears the search and everything said about it.
 *
 * The chips look after themselves — they show only while the query still
 * carries what they name — but what the distiller could not apply, and how the
 * last attempt ended, are notes about a question that is now gone.
 */
function newSearch() {
  store.reset()
  distiller.reset()
}

/**
 * Re-reads the page that is on screen after a command changed a file. Not
 * `search()`: that would jump back to the first page and record the search
 * again, and neither is what "I just uploaded a new version" means.
 */
function refresh() {
  store.run(t, store.offset, false)
}

/**
 * Picking a suggestion is an explicit choice of a whole search, so it loads and
 * runs it — unlike a click in the Searches list, which only selects.
 * @param entry the saved or recent search that was picked
 */
function runSuggestion(entry: StoredSearch) {
  history.markRun(entry)
  store.apply(entry.query)
  // Re-running a stored search is not itself a new search worth recording.
  store.run(t, 0, entry.kind !== 'saved')
}

function page(direction: number) {
  store.run(t, store.offset + direction * store.pageSize, false)
}

function addCondition() {
  const schema = store.schema
  const field = schema && Object.keys(schema.fields)[0]
  const operator = field ? schema.operators[field]?.[0] : undefined
  if (!field || !operator) {
    return
  }
  store.query.conditions.push({ field, operator, value: '' })
}

/**
 * Saving used to jump to the Searches page, which threw away the results you
 * were looking at and the criteria you were still refining. Saving is a note
 * to self, not a destination: the toast says it worked and the page stays put.
 */
async function save() {
  try {
    await saveSearch(store.query, store.query.term || t('Saved search'))
  } catch (e) {
    store.error = (e as Error).message
  }
}
</script>

<style scoped lang="scss">
// The page is a column that fills the content area: the criteria and the paging
// bar keep their place while only the results scroll, so the controls you are
// working with never scroll off. Full width on purpose — a result grid with
// several metadata columns needs every pixel.
.finder {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
  padding: 0 16px 12px;

  // The box and the buttons that act on it, on one line and vertically centred
  // on it — the buttons belong to the box, not to the row below.
  &__bar {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 10px;

    // The box gives way, never the actions. Without this the buttons shrink to
    // "Sear…" and "Sa…" once the term control claims its width — and a button
    // whose label is cut is a button nobody can be sure of. Aimed through the
    // component's own class because NcButton sets its box properties at the
    // specificity a single scoped class reaches.
    :deep(.button-vue) {
      flex: 0 0 auto;
    }
  }

  &__term {
    flex: 1 1 auto;
    min-width: 0;
  }

  &__toggle {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
    margin-bottom: 8px;

    :deep(.button-vue) {
      flex: 0 0 auto;
    }
  }

  // One line, however many filters there are: the full list is in the title,
  // and one click away in the controls themselves.
  &__active {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  &__ranked {
    margin: 0 0 4px;
    font-size: 90%;
  }

  &__chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 6px;
  }

  &__dropped {
    margin: 0 0 6px;
    font-size: 90%;
  }

  &__unapplied {
    margin: 4px 0 0;
    padding-inline-start: 20px;
  }

  // The tracks are NcSelect's own width, and not a little wider: the condition
  // rows below keep the library's width for their dropdowns, and wider columns
  // left their Field and Condition boxes short of the ones above them.
  &__grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, 260px);
    align-items: end;
    gap: 12px 8px;
    margin-bottom: 12px;
  }

  // As wide as what it says: a switch spanning the page would flip from a click
  // far to the right of its label.
  &__contents {
    width: fit-content;
    margin-bottom: 4px;
  }

  &__panel {
    margin-bottom: 16px;
  }

  &__match {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 8px 0 12px;
    color: var(--color-text-maxcontrast);
  }

  &__loading {
    margin: 32px auto;
  }

  // Everything above this stays; this is the only part that scrolls.
  &__results {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
  }

  &__paging {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 16px;
  }
}

.muted {
  color: var(--color-text-maxcontrast);
}
</style>
