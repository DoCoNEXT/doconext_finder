<template>
  <NcDialog :name="title"
            size="normal"
            @closing="emit('close')">
    <p class="muted">
      {{ t('Whoever you share with runs the same search and sees only the files they can open themselves.') }}
    </p>

    <!--
      filterable=false: the server already narrowed the list by the typed name
      and by the admin's sharing rules; vue-select must not narrow it again.
    -->
    <NcSelect :model-value="null"
              class="share__picker"
              label="displayName"
              :input-label="t('Share with')"
              :options="available"
              :filterable="false"
              :loading="searching"
              :placeholder="t('Name of a person, group or team')"
              @search="onSearch"
              @option:selected="add">
      <template #option="sharee">
        <span class="share__option">
          <component :is="iconOf(sharee.type)" :size="18" />
          <span>{{ sharee.displayName }}</span>
          <span class="muted">{{ kindOf(sharee.type) }}</span>
        </span>
      </template>
      <template #no-options>
        {{ term.trim() === '' ? t('Type a name to search') : t('No one found') }}
      </template>
    </NcSelect>

    <NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>
    <NcLoadingIcon v-if="loading" :size="28" />

    <p v-else-if="shares.length === 0" class="muted share__empty">
      {{ t('Not shared with anyone yet.') }}
    </p>

    <ul v-else class="share__list">
      <li v-for="share in shares" :key="share.id" class="share__row">
        <component :is="iconOf(share.type)" :size="20" class="share__icon" />
        <span class="share__who">
          <strong>{{ share.displayName }}</strong>
          <span class="muted">{{ kindOf(share.type) }}</span>
        </span>
        <NcCheckboxRadioSwitch :model-value="share.canEdit"
                               :disabled="busy.has(share.id)"
                               @update:model-value="setCanEdit(share, $event)">
          {{ t('Can edit') }}
        </NcCheckboxRadioSwitch>
        <NcButton variant="tertiary"
                  :aria-label="t('Stop sharing with {name}', { name: share.displayName })"
                  :disabled="busy.has(share.id)"
                  @click="remove(share)">
          <template #icon>
            <X :size="20" />
          </template>
        </NcButton>
      </li>
    </ul>

    <template #actions>
      <NcButton variant="primary" @click="emit('close')">
        {{ t('Done') }}
      </NcButton>
    </template>
  </NcDialog>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import {
  NcButton,
  NcCheckboxRadioSwitch,
  NcDialog,
  NcLoadingIcon,
  NcNoteCard,
  NcSelect,
} from '@nextcloud/vue'
import { User, Users, UsersRound, X } from '@lucide/vue'
import { useI18n } from '../composables/useI18n'
import { SearchApi } from '../services/SearchApi'
import type { SearchShare, Sharee, ShareType, StoredSearch } from '../types/Search'

const props = defineProps<{ entry: StoredSearch }>()
const emit = defineEmits<{ (e: 'close'): void }>()

const { t } = useI18n()

const title = computed(() => t('Share "{name}"', { name: props.entry.name ?? '' }))

const shares = ref<SearchShare[]>([])
const options = ref<Sharee[]>([])
const term = ref('')
const loading = ref(true)
const searching = ref(false)
const error = ref('')
/** Shares with a request in flight, so a second click cannot race the first. */
const busy = reactive(new Set<number>())

/** Who is already shared with is not offered a second time. */
const available = computed(() => options.value.filter((sharee) =>
  !shares.value.some((share) => share.type === sharee.type && share.with === sharee.id)))

onMounted(async () => {
  try {
    shares.value = await SearchApi.shares(props.entry.id)
  } catch (e) {
    error.value = (e as Error).message
  } finally {
    loading.value = false
  }
})

function iconOf(type: ShareType) {
  return type === 'group' ? Users : type === 'circle' ? UsersRound : User
}

function kindOf(type: ShareType): string {
  return type === 'group' ? t('Group') : type === 'circle' ? t('Team') : t('User')
}

let debounce: ReturnType<typeof setTimeout> | undefined

/**
 * @param typed what has been typed into the picker
 * @param setLoading vue-select's own spinner
 */
function onSearch(typed: string, setLoading: (state: boolean) => void) {
  term.value = typed
  clearTimeout(debounce)
  if (typed.trim() === '') {
    options.value = []
    return
  }
  debounce = setTimeout(async () => {
    searching.value = true
    setLoading(true)
    try {
      options.value = await SearchApi.sharees(typed)
    } catch {
      options.value = []
    } finally {
      searching.value = false
      setLoading(false)
    }
  }, 250)
}

/**
 * Shares read-only first: being able to change a search for everyone is the
 * larger thing to give, so it is a second, deliberate click.
 * @param sharee who was picked
 */
async function add(sharee: Sharee) {
  error.value = ''
  try {
    shares.value.push(await SearchApi.share(props.entry.id, sharee.type, sharee.id, false))
    options.value = []
  } catch (e) {
    error.value = (e as Error).message
  }
}

/**
 * @param share the share to change
 * @param canEdit what it should allow
 */
async function setCanEdit(share: SearchShare, canEdit: boolean) {
  await guarded(share, async () => {
    Object.assign(share, await SearchApi.updateShare(props.entry.id, share.id, canEdit))
  })
}

/**
 * @param share the share to end
 */
async function remove(share: SearchShare) {
  await guarded(share, async () => {
    await SearchApi.unshare(props.entry.id, share.id)
    shares.value = shares.value.filter((other) => other.id !== share.id)
  })
}

/**
 * @param share the share the action is about
 * @param action the request
 */
async function guarded(share: SearchShare, action: () => Promise<void>) {
  error.value = ''
  busy.add(share.id)
  try {
    await action()
  } catch (e) {
    error.value = (e as Error).message
  } finally {
    busy.delete(share.id)
  }
}
</script>

<style scoped lang="scss">
.share {
  &__picker {
    width: 100%;
    margin: 12px 0;
  }

  &__option {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  &__empty {
    margin: 12px 0;
  }

  &__list {
    list-style: none;
    padding: 0;
    margin: 0 0 8px;
  }

  &__row {
    display: flex;
    align-items: center;
    gap: 8px;
    min-height: 44px;
    border-bottom: 1px solid var(--color-border);
  }

  &__icon {
    flex: none;
  }

  &__who {
    display: flex;
    flex-direction: column;
    flex: 1 1 auto;
    min-width: 0;

    strong {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
  }
}
</style>
