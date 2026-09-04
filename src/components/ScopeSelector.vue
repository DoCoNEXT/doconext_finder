<template>
  <div v-if="available" class="scope">
    <NcSelect v-model="realm"
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
         you type. filterable=false hands the filtering to the backend instead of
         letting vue-select narrow a list it only partly has. -->
    <NcSelect v-model="entity"
              class="scope__select"
              label="label"
              :options="entityOptions"
              :filterable="false"
              :loading="searching"
              :disabled="disabled"
              :input-label="entityLabel"
              :placeholder="disabled ? placeholder : t('Type to search')"
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
  entityOptions.value = []
  publish()
}, { flush: 'sync' })

watch(type, () => {
  if (syncing) {
    return
  }
  entity.value = null
  entityOptions.value = []
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
 * @param term what has been typed into the entity field
 * @param loading vue-select's own spinner toggle
 */
function onSearch(term: string, loading: (state: boolean) => void) {
  clearTimeout(debounce)
  if (term.trim() === '') {
    entityOptions.value = []
    return
  }

  // A keystroke is not a question. Waiting a beat turns a word typed at speed
  // into one request instead of eight.
  debounce = setTimeout(async () => {
    searching.value = true
    loading(true)
    try {
      const found = await SearchApi.scopeEntities(term, type.value?.id)
      entityOptions.value = found.map((hit) => ({
        ...hit,
        // Two entities may share a name; their code and what they hang under is
        // what tells them apart, so it belongs on the line, not in a tooltip.
        label: hit.context ? `${hit.name} · ${hit.context}` : hit.name,
      }))
    } catch {
      entityOptions.value = []
    } finally {
      searching.value = false
      loading(false)
    }
  }, 250)
}
</script>

<style scoped lang="scss">
.scope {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 8px;
  margin-bottom: 16px;
}

/*
 * Every field in both filter rows is the same width, so the two rows read as one
 * grid. The entity field used to be wider for its long names — a little room
 * bought at the cost of the alignment.
 *
 * That trade was not needed: the dropdown is exactly as wide as the field, but
 * its options wrap onto a second line rather than truncate, so a name like
 * "Bezwaarprocedure omgevingsvergunning · DOS00042 · Voorbeeldbedrijf B.V." is
 * still readable in full at 260px (measured). Only the collapsed field, once a
 * choice is made, ellipsizes.
 *
 * NcSelect's own 260px minimum decides the real width; see FolderScope.vue.
 */
.scope__select {
  flex: 0 1 220px;
  min-width: 170px;
}
</style>
