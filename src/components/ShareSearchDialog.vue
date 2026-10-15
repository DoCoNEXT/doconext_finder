<template>
  <NcDialog :name="title"
            size="normal"
            @closing="emit('close')">
    <p class="muted">
      {{ t('Whoever you share with runs the same search and sees only the files they can open themselves.') }}
    </p>

    <!--
      The picker Nextcloud's own share dialogs use: an avatar and status for a
      person, and the line that tells two of the same name apart. The people
      in it come from the server, already narrowed by the admin's rules.
    -->
    <NcSelectUsers v-model="picked"
                   class="share__picker"
                   :input-label="t('Share with')"
                   :options="available"
                   :loading="searching"
                   :placeholder="t('Name of a person, group or team')"
                   @search="onSearch"
                   @update:model-value="add" />

    <NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>
    <NcLoadingIcon v-if="loading" :size="28" />

    <p v-else-if="shares.length === 0" class="muted share__empty">
      {{ t('Not shared with anyone yet.') }}
    </p>

    <ul v-else class="share__list">
      <li v-for="share in shares" :key="share.id" class="share__row">
        <NcListItemIcon class="share__who"
                        :name="share.displayName"
                        :subname="share.type === 'user' ? '' : kindOf(share.type)"
                        v-bind="avatarOf(share.type, share.with)" />
        <NcCheckboxRadioSwitch class="share__edit"
                               :model-value="share.canEdit"
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

    <!--
      Here rather than in the row's menu: the list above is exactly who the
      link works for, so it is the one place the link can be read right.
    -->
    <div class="share__link">
      <NcButton class="share__copy" variant="secondary" @click="copyLink">
        <template #icon>
          <LinkIcon :size="20" />
        </template>
        {{ t('Copy link') }}
      </NcButton>
      <span class="muted">{{ linkReach }}</span>
    </div>

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
  NcListItemIcon,
  NcLoadingIcon,
  NcNoteCard,
  NcSelectUsers,
} from '@nextcloud/vue'
import type { NcSelectUsersModel } from '@nextcloud/vue/components/NcSelectUsers'
import { Link as LinkIcon, X } from '@lucide/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { useClipboard } from '../composables/useClipboard'
import { searchLink } from '../services/links'
import { useI18n } from '../composables/useI18n'
import { SearchApi } from '../services/SearchApi'
import type { SearchShare, Sharee, ShareType, StoredSearch } from '../types/Search'

const props = defineProps<{ entry: StoredSearch }>()
const emit = defineEmits<{ (e: 'close'): void }>()

const { t } = useI18n()

const title = computed(() => t('Share "{name}"', { name: props.entry.name ?? '' }))

const shares = ref<SearchShare[]>([])
const options = ref<Sharee[]>([])
/** Always empty again once a pick has been shared; the picker only adds. */
const picked = ref<NcSelectUsersModel | undefined>(undefined)
const loading = ref(true)
const searching = ref(false)
const error = ref('')
/** Shares with a request in flight, so a second click cannot race the first. */
const busy = reactive(new Set<number>())

const { copy } = useClipboard()

/**
 * Who the link opens the search for. Said every time, because a link reads as
 * a way to share — and it is not one.
 */
const linkReach = computed(() => (shares.value.length === 0
  ? t('This link works only for you until you share the search with someone.')
  : t('Opens this search for you and for everyone above. Anyone else sees nothing.')))

async function copyLink() {
  if (await copy(searchLink(props.entry.id))) {
    showSuccess(t('Link copied'))
  } else {
    showError(t('The link could not be copied'))
  }
}

/**
 * Who is already shared with is not offered a second time. A user and a group
 * may share an id, so the picker's id carries the kind too.
 */
const available = computed((): NcSelectUsersModel[] => options.value
  .filter((sharee) => !shares.value.some((share) => share.type === sharee.type && share.with === sharee.id))
  .map((sharee) => ({
    id: keyOf(sharee),
    displayName: sharee.displayName,
    // A group has no second line of its own; saying what it is reads better
    // than nothing beside an avatar of initials.
    subname: sharee.subname || (sharee.type === 'user' ? '' : kindOf(sharee.type)),
    ...avatarOf(sharee.type, sharee.id),
  })))

/**
 * @param sharee who the server offered
 */
function keyOf(sharee: Sharee): string {
  return `${sharee.type}:${sharee.id}`
}

/**
 * A person gets their avatar and status; a group or a team, which has
 * neither, gets the initials of its name.
 * @param type the kind of recipient
 * @param id their id
 */
function avatarOf(type: ShareType, id: string): { user?: string, isNoUser: boolean } {
  return type === 'user' ? { user: id, isNoUser: false } : { isNoUser: true }
}

onMounted(async () => {
  try {
    shares.value = await SearchApi.shares(props.entry.id)
  } catch (e) {
    error.value = (e as Error).message
  } finally {
    loading.value = false
  }
})

function kindOf(type: ShareType): string {
  return type === 'group' ? t('Group') : type === 'circle' ? t('Team') : t('User')
}

let debounce: ReturnType<typeof setTimeout> | undefined

/**
 * @param typed what has been typed into the picker
 */
function onSearch(typed: string) {
  clearTimeout(debounce)
  if (typed.trim() === '') {
    options.value = []
    return
  }
  debounce = setTimeout(async () => {
    searching.value = true
    try {
      options.value = await SearchApi.sharees(typed)
    } catch {
      options.value = []
    } finally {
      searching.value = false
    }
  }, 250)
}

/**
 * Shares read-only first: being able to change a search for everyone is the
 * larger thing to give, so it is a second, deliberate click.
 * @param choice what the picker reports as picked
 */
async function add(choice: NcSelectUsersModel | NcSelectUsersModel[] | undefined) {
  const sharee = options.value.find((candidate) => !Array.isArray(choice) && keyOf(candidate) === choice?.id)
  picked.value = undefined
  if (!sharee) {
    return
  }
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

  &__link {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 16px 0 8px;
  }

  // Its label is short and must read whole; the sentence beside it wraps.
  &__copy {
    flex: none;
  }

  &__who {
    flex: 1 1 auto;
    min-width: 0;
  }

  // The name gives way to a long recipient, not the switch's label.
  &__edit {
    flex: none;
  }
}
</style>
