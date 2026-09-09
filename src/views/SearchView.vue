<template>
  <div class="finder">
    <!-- The box first, on a line of its own with the two buttons that act on
         it; the filters that narrow it sit underneath. Type and Modified used
         to share the line and pushed the box down to a third of the width. -->
    <div class="finder__bar">
      <SearchBox v-model="searchTerm"
                 class="finder__term"
                 :search-in="searchIn"
                 :targets="HAS_CONTENT_SEARCH"
                 :understand="HAS_ENTITY_SCOPE"
                 :understand-disabled="!aiReady || distiller.running.value || store.loading || searchTerm.trim() === ''"
                 :understanding="distiller.running.value"
                 @update:search-in="searchIn = $event"
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
                @click="store.reset()">
        <template #icon>
          <X :size="20" />
        </template>
        {{ t('New search') }}
      </NcButton>
    </div>

    <!-- Folder sits with the other two filters that need nothing but Nextcloud;
         the DoCoNEXT fields get their own line below. -->
    <div class="finder__presets">
      <FolderScope />

      <NcSelect v-model="typeOption"
                class="finder__preset"
                label="label"
                :options="typeOptions"
                :clearable="false"
                :input-label="t('Type')" />

      <NcSelect v-model="timeOption"
                class="finder__preset"
                label="label"
                :options="timeOptions"
                :clearable="false"
                :input-label="t('Modified')" />
    </div>

    <ScopeSelector />

    <!--
      What the question was understood to mean, in Core's own words. Shown
      because a scope that arrives without explanation is a scope nobody can
      correct: the chips say what was applied, the line below says what could
      not be. Both stay editable in the filters underneath — these are a
      receipt, not a control.
    -->
    <div v-if="distiller.chips.value.length" class="finder__chips">
      <span v-for="chip in distiller.chips.value" :key="chip" class="finder__chip">{{ chip }}</span>
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

    <details class="finder__filters" :open="store.query.conditions.length > 0">
      <summary>{{ filterSummary }}</summary>

      <div class="finder__match">
        <span>{{ t('Match:') }}</span>
        <NcCheckboxRadioSwitch v-model="matchMode" type="radio" value="all" name="match">
          {{ t('all conditions') }}
        </NcCheckboxRadioSwitch>
        <NcCheckboxRadioSwitch v-model="matchMode" type="radio" value="any" name="match">
          {{ t('any condition') }}
        </NcCheckboxRadioSwitch>
      </div>

      <template v-if="store.schema">
        <ConditionRow v-for="(condition, index) in store.query.conditions"
                      :key="index"
                      :condition="condition"
                      :schema="store.schema"
                      @update:condition="store.query.conditions.splice(index, 1, $event)"
                      @remove="store.query.conditions.splice(index, 1)" />

        <NcButton @click="addCondition">
          {{ t('Add condition') }}
        </NcButton>
      </template>
      <NcLoadingIcon v-else :size="20" />
    </details>

    <NcNoteCard v-if="message" :type="messageType">{{ message }}</NcNoteCard>

    <ViewOptions v-if="store.results.length"
                 scope="search"
                 :partial="store.hasMore && !store.loadedAll" />

    <!--
      A content search is the one query in this app that is not exhaustive: the
      index answers with its best matches, and the filters narrow those. Saying
      so is the difference between "these are all of them" and "these are the
      ones it found first", which otherwise look identical.
    -->
    <p v-if="rankedResults" class="muted finder__ranked">
      {{ t('Ranked by how well the contents match. The index answers with its best matches, so this is not a complete list.') }}
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
  NcNoteCard,
  NcSelect,
} from '@nextcloud/vue'
import { Save, Search, X } from '@lucide/vue'
import { HAS_CONTENT_SEARCH, HAS_ENTITY_SCOPE } from '../constants'
import { useI18n } from '../composables/useI18n'
import { useSearchStore } from '../stores/searchStore'
import { useSelectionStore } from '../stores/selectionStore'
import { useHistoryStore } from '../stores/historyStore'
import { useSaveSearch } from '../composables/useSaveSearch'
import { useDistiller } from '../composables/useDistiller'
import { CoreAiApi } from '../services/CoreAiApi'
import { anyTime, anyType, fileTypePresets, modifiedPresets } from '../filters/presets'
import ConditionRow from '../components/ConditionRow.vue'
import FolderScope from '../components/FolderScope.vue'
import ScopeSelector from '../components/ScopeSelector.vue'
import FileTable from '../components/FileTable.vue'
import SearchBox from '../components/SearchBox.vue'
import ViewOptions from '../components/ViewOptions.vue'
import type { FileTypePreset, ModifiedPreset } from '../filters/presets'
import type { StoredSearch } from '../types/Search'

const { t } = useI18n()
const store = useSearchStore()

/**
 * Where the one box writes, when neither field has anything in it yet.
 *
 * Only consulted in that case: which field a query *is* using outranks what was
 * clicked last, so loading a saved content search shows its term instead of an
 * empty name box beside a control claiming to search names.
 */
const preferredSearchIn = ref<'name' | 'content'>('name')

/**
 * Which of the two term fields the one box reads and writes.
 *
 * The state keeps them apart — a name term and a content term are different
 * predicates, and only one of them is answered by the index — but a user has a
 * single question and types it once. Switching carries the text across rather
 * than stranding it in a field nobody can see any more.
 */
const searchIn = computed<'name' | 'content'>({
  get: () => {
    if (store.query.content.trim() !== '') {
      return 'content'
    }
    return store.query.term.trim() !== '' ? 'name' : preferredSearchIn.value
  },
  set: (next) => {
    const carried = searchIn.value === 'content' ? store.query.content : store.query.term
    preferredSearchIn.value = next
    store.query.term = next === 'name' ? carried : ''
    store.query.content = next === 'content' ? carried : ''

    // Relevance only exists while the index is ranking something. Left selected
    // after a switch back to names it would sort by an order nothing computes;
    // not selected on a switch to contents it would throw away the one ordering
    // a content search is good at.
    if (next === 'content') {
      store.query.sort = 'relevance'
      store.query.descending = true
    } else if (store.query.sort === 'relevance') {
      store.query.sort = 'mtime'
    }
  },
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
    aiReady.value = (await CoreAiApi.status()).available
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

const searchTerm = computed({
  get: () => (searchIn.value === 'content' ? store.query.content : store.query.term),
  set: (value: string) => {
    if (searchIn.value === 'content') {
      store.query.content = value
    } else {
      store.query.term = value
    }
  },
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
  get: () => timeOptions.find((o) => o.id === store.query.modifiedPreset) ?? anyTime(t),
  set: (next) => { store.query.modifiedPreset = next?.id ?? 'any' },
})

const matchMode = computed<'all' | 'any'>({
  get: () => (store.query.matchAny ? 'any' : 'all'),
  set: (next) => { store.query.matchAny = next === 'any' },
})

const filterSummary = computed(() =>
  store.query.conditions.length
    ? t('Advanced filters ({count})', { count: store.query.conditions.length })
    : t('Advanced filters'))

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

/** True while the rows on screen came back ranked rather than enumerated. */
const rankedResults = computed(() => (
  store.results.length > 0 && store.query.content.trim() !== ''
))

const messageType = computed(() => (
  store.error === 'capped' || store.error === 'scope-truncated' ? 'warning' : 'error'
))

function search() {
  store.run(t, 0)
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

  &__chip {
    padding: 2px 10px;
    border-radius: var(--border-radius-pill, 16px);
    background: var(--color-primary-element-light);
    color: var(--color-primary-element-light-text);
    font-size: 90%;
  }

  &__dropped {
    margin: 0 0 6px;
    font-size: 90%;
  }

  &__unapplied {
    margin: 4px 0 0;
    padding-inline-start: 20px;
  }

  &__presets {
    display: flex;
    align-items: end;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 16px;
  }

  &__preset {
    flex: 0 1 220px;
    min-width: 170px;
  }

  &__filters {
    margin-bottom: 16px;

    summary {
      cursor: pointer;
      padding: 4px 0;
      color: var(--color-text-maxcontrast);
      /*
       * A summary is a block, so it spans the row and swallows clicks far to the
       * right of its own words — the panel would open from a click in empty
       * space. Shrink it to what it actually says.
       */
      width: fit-content;
    }
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
