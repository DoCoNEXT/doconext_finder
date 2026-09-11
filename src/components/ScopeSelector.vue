<template>
  <div v-if="available" class="scope">
    <!-- A choice of one is no choice: with a single workspace every type below
         already belongs to it. Still shown when a restored search is scoped to
         a workspace, since a scope nobody can see is one nobody can clear. -->
    <NcSelect v-if="realms.length > 1 || store.query.scope?.level === 'realm'"
              v-model="realm"
              class="scope__select"
              label="name"
              :options="realms"
              :disabled="disabled"
              :input-label="t('Workspace')"
              :placeholder="placeholder" />

    <NcSelect v-model="type"
              class="scope__select"
              label="name"
              :options="typeOptions"
              :disabled="disabled"
              :input-label="t('Entity type')"
              :placeholder="placeholder" />

    <!-- Entities are far too many for a dropdown, so this one asks the server as
         you type — and, before anything is typed, offers what you starred and
         what changed last. filterable=false hands the filtering to the backend
         instead of letting vue-select narrow a list it only partly has. -->
    <NcSelect v-model="entity"
              class="scope__select"
              label="label"
              :options="entityOptions"
              :filterable="false"
              :loading="searching"
              :disabled="disabled"
              :input-label="entityLabel"
              :placeholder="disabled ? placeholder : t('Type to search')"
              @open="onOpen"
              @search="onSearch" />
  </div>
</template>

<script setup lang="ts">
/**
 * Narrows a search to part of DoCoNEXT Core: a workspace, one of its entity
 * types, or a single entity.
 *
 * The three are strictly nested, so what the store keeps is the *deepest* one
 * chosen: picking an entity makes its type redundant, not additional. The fields
 * above it stay filled in as context but no longer decide anything.
 *
 * The whole row goes quiet while a folder scope is set. A folder is a place and
 * an entity is a thing that has a place; offering both at once would raise a
 * question with no honest answer when they disagree.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { NcSelect } from '@nextcloud/vue'
import { HAS_ENTITY_SCOPE } from '../constants'
import { useI18n } from '../composables/useI18n'
import { useSearchStore } from '../stores/searchStore'
import { SearchApi } from '../services/SearchApi'
import type { ScopeEntity, ScopeEntityType, ScopeRealm } from '../types/Search'

/** An entity suggestion with its display line precomputed. */
interface EntityOption extends ScopeEntity {
  label: string
}

const { t } = useI18n()
const store = useSearchStore()

const available = HAS_ENTITY_SCOPE

const realms = ref<ScopeRealm[]>([])
const types = ref<ScopeEntityType[]>([])

const realm = ref<ScopeRealm | null>(null)
const type = ref<ScopeEntityType | null>(null)
const entity = ref<EntityOption | null>(null)

const entityOptions = ref<EntityOption[]>([])
const searching = ref(false)

/**
 * Guards the two-way sync with the store.
 *
 * The watchers below are deliberately `flush: 'sync'`. With Vue's default timing
 * they run after this flag has already been put back, so a rebuild from the
 * store looked exactly like a user's choice — which is how the entity type field
 * ended up reloading itself forever.
 */
let syncing = false

const disabled = computed(() => store.query.scope?.level === 'folder')

const placeholder = computed(() => (disabled.value ? t('Searching a folder') : t('Any')))

onMounted(async () => {
  if (!available) {
    return
  }
  try {
    const vocabulary = await SearchApi.scope()
    realms.value = vocabulary.realms ?? []
    types.value = vocabulary.entityTypes ?? []
  } catch {
    // An unreachable vocabulary is not a broken page: the fields offer nothing,
    // and the search runs unscoped as it always did.
    realms.value = []
    types.value = []
  }
  adopt()
})

/**
 * The entity field has no useful name until a type is picked — "Entity" is the
 * honest placeholder — and once one is, that type's own singular says it far
 * better than any generic word: you are picking a Huurovereenkomst, not an
 * entity.
 */
const entityLabel = computed(() => type.value?.singularName || t('Entity'))

/** Types are filtered in the browser: both lists are short and arrive together. */
const typeOptions = computed(() => (realm.value
  ? types.value.filter((candidate) => candidate.realmId === realm.value?.id)
  : types.value))

// Narrowing a level invalidates everything below it — an entity from another
// workspace is not a narrower version of the new one, it is a contradiction.
watch(realm, () => {
  if (syncing) {
    return
  }
  if (realm.value && type.value && type.value.realmId !== realm.value.id) {
    type.value = null
  }
  entity.value = null
  forgetEntities()
  publish()
}, { flush: 'sync' })

watch(type, () => {
  if (syncing) {
    return
  }
  entity.value = null
  forgetEntities()
  publish()
}, { flush: 'sync' })

watch(entity, () => {
  if (!syncing) {
    publish()
  }
}, { flush: 'sync' })

/** Reflects an externally set scope — a saved search, "New search", or a folder. */
watch(() => store.query.scope, (next) => {
  const current = currentSelection()
  if (next?.level !== current?.level || next?.id !== current?.id) {
    adopt()
  }
}, { flush: 'sync' })

/** The deepest level the user has actually chosen. */
function currentSelection() {
  if (entity.value) {
    return { level: 'entity' as const, id: entity.value.id, label: entity.value.label }
  }
  if (type.value) {
    return { level: 'entityType' as const, id: type.value.id, label: type.value.name }
  }
  if (realm.value) {
    return { level: 'realm' as const, id: realm.value.id, label: realm.value.name }
  }
  return null
}

/**
 * Writes this row's answer to the store — unless a folder holds the scope, which
 * this row must never overwrite.
 */
function publish() {
  const selection = currentSelection()
  if (selection === null && disabled.value) {
    return
  }
  store.query.scope = selection
}

/**
 * Rebuilds the fields from the store's scope.
 *
 * A stored scope carries only its deepest level, so an entity cannot be walked
 * back up to its type — the stored label is shown instead. That is the honest
 * answer: the label was true when it was saved, and re-deriving the hierarchy
 * would mean a lookup for something nobody is going to act on.
 */
function adopt() {
  syncing = true
  const scope = store.query.scope

  realm.value = null
  type.value = null
  entity.value = null
  forgetEntities()

  if (scope?.level === 'realm') {
    realm.value = realms.value.find((candidate) => candidate.id === scope.id)
      ?? { id: scope.id, name: scope.label }
  } else if (scope?.level === 'entityType') {
    const known = types.value.find((candidate) => candidate.id === scope.id)
    type.value = known ?? { id: scope.id, name: scope.label, singularName: scope.label, realmId: 0 }
    realm.value = realms.value.find((candidate) => candidate.id === known?.realmId) ?? null
  } else if (scope?.level === 'entity') {
    entity.value = { id: scope.id, name: scope.label, context: '', label: scope.label }
    entityOptions.value = [entity.value]
  }

  syncing = false
}

let debounce: ReturnType<typeof setTimeout> | undefined

/**
 * What the entity list currently holds: nothing yet, the suggestions shown
 * before anything is typed, or the matches for a typed term.
 */
let listed: 'nothing' | 'suggestions' | 'matches' = 'nothing'

/** Answers arrive out of order; only the latest question may fill the list. */
let fetchSeq = 0

/**
 * Empties the entity list, including an answer still on its way — one asked
 * under the previous type would otherwise land in the list for the new one.
 */
function forgetEntities() {
  clearTimeout(debounce)
  fetchSeq++
  searching.value = false
  entityOptions.value = []
  listed = 'nothing'
}

/**
 * Fetched on first open rather than on mount: most searches never touch this
 * field, and an unopened list costs nothing to leave unasked.
 */
function onOpen() {
  if (listed === 'nothing') {
    fetchEntities('')
  }
}

/**
 * @param term what has been typed into the entity field
 */
function onSearch(term: string) {
  clearTimeout(debounce)
  if (term.trim() === '') {
    // Clearing a term goes back to the suggestions rather than to nothing. The
    // empty search vue-select emits when it closes an untouched box has nothing
    // to restore.
    if (listed === 'matches') {
      fetchEntities('')
    }
    return
  }

  // A keystroke is not a question. Waiting a beat turns a word typed at speed
  // into one request instead of eight.
  debounce = setTimeout(() => fetchEntities(term), 250)
}

/**
 * @param term what to match, or '' for the suggestions
 */
async function fetchEntities(term: string) {
  const seq = ++fetchSeq
  listed = term === '' ? 'suggestions' : 'matches'
  searching.value = true
  try {
    const found = await SearchApi.scopeEntities(term, type.value?.id)
    if (seq !== fetchSeq) {
      return
    }
    entityOptions.value = found.map((hit) => ({
      ...hit,
      // Two entities may share a name; their code and what they hang under is
      // what tells them apart, so it belongs on the line, not in a tooltip.
      label: hit.context ? `${hit.name} · ${hit.context}` : hit.name,
    }))
  } catch {
    if (seq === fetchSeq) {
      entityOptions.value = []
      listed = 'nothing' // so the next open asks again
    }
  } finally {
    if (seq === fetchSeq) {
      searching.value = false
    }
  }
}
</script>

<style scoped lang="scss">
/*
 * No row of its own: the fields join the grid of the view they sit in, so they
 * share its columns instead of matching them by having the same width.
 */
.scope {
  display: contents;
}

/*
 * A line of their own all the same, since these are DoCoNEXT scopes and the
 * fields before them are not.
 *
 * The entity names are long, but they need no wider field: the dropdown's
 * options wrap onto a second line rather than truncate, so a name like
 * "Bezwaarprocedure omgevingsvergunning · DOS00042 · Voorbeeldbedrijf B.V." is
 * still readable in full at 260px (measured). Only the collapsed field, once a
 * choice is made, ellipsizes.
 */
.scope__select:first-child {
  grid-column-start: 1;
}
</style>
