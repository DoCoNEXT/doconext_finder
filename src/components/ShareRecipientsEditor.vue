<template>
  <div class="recipients">
    <!--
      The picker Nextcloud's own share dialogs use: an avatar and status for a
      person, and the line that tells two of the same name apart. The people
      in it come from the server, already narrowed by the admin's rules.
    -->
    <NcSelectUsers v-model="picked"
                   class="recipients__picker"
                   :input-label="t('Share with')"
                   :options="available"
                   :loading="searching || checking"
                   :disabled="disabled"
                   :placeholder="t('Name of a person, group or team')"
                   @search="onSearch"
                   @update:model-value="pick" />

    <NcNoteCard v-if="refusal" type="warning">{{ refusal }}</NcNoteCard>

    <p v-if="rows.length === 0" class="muted recipients__empty">
      {{ t('Not shared with anyone yet.') }}
    </p>

    <ul v-else class="recipients__list">
      <li v-for="row in rows" :key="row.key" class="recipients__row">
        <NcListItemIcon class="recipients__who"
                        :name="row.displayName"
                        :subname="row.type === 'user' ? '' : kindOf(row.type)"
                        v-bind="avatarOf(row.type, row.with)" />
        <NcCheckboxRadioSwitch class="recipients__edit"
                               :model-value="row.canEdit"
                               :disabled="disabled"
                               @update:model-value="emit('toggle', row, $event)">
          {{ t('Can edit') }}
        </NcCheckboxRadioSwitch>
        <NcButton variant="tertiary"
                  :aria-label="t('Stop sharing with {name}', { name: row.displayName })"
                  :disabled="disabled"
                  @click="emit('remove', row)">
          <template #icon>
            <X :size="20" />
          </template>
        </NcButton>
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import {
  NcButton,
  NcCheckboxRadioSwitch,
  NcListItemIcon,
  NcNoteCard,
  NcSelectUsers,
} from '@nextcloud/vue'
import type { NcSelectUsersModel } from '@nextcloud/vue/components/NcSelectUsers'
import { X } from '@lucide/vue'
import { useI18n } from '../composables/useI18n'
import { SearchApi } from '../services/SearchApi'
import type { Sharee, ShareType, StagedShare } from '../types/Search'

/**
 * Who a search is, or is about to be, shared with.
 *
 * Shows and edits a list; it sends nothing itself. Whoever holds it decides
 * when the list becomes shares — the save dialog does that when it saves, so
 * a search being saved for the first time can be shared in the same step.
 *
 * The one thing it does ask the server is whether a pick is allowed, as soon
 * as it is made: a refusal belongs next to the name that caused it, not after
 * the search has already been saved.
 */
const props = defineProps<{
  rows: StagedShare[]
  disabled?: boolean
}>()
const emit = defineEmits<{
  (e: 'add', sharee: Sharee): void
  (e: 'toggle', row: StagedShare, canEdit: boolean): void
  (e: 'remove', row: StagedShare): void
}>()

const { t } = useI18n()

const options = ref<Sharee[]>([])
/** Always empty again once a pick has been taken; the picker only adds. */
const picked = ref<NcSelectUsersModel | undefined>(undefined)
const searching = ref(false)
const checking = ref(false)
const refusal = ref('')

/**
 * Who is already in the list is not offered a second time. A user and a group
 * may share an id, so the picker's id carries the kind too.
 */
const available = computed((): NcSelectUsersModel[] => options.value
  .filter((sharee) => !props.rows.some((row) => row.type === sharee.type && row.with === sharee.id))
  .map((sharee) => ({
    id: keyOf(sharee.type, sharee.id),
    displayName: sharee.displayName,
    // A group has no second line of its own; saying what it is reads better
    // than nothing beside an avatar of initials.
    subname: sharee.subname || (sharee.type === 'user' ? '' : kindOf(sharee.type)),
    ...avatarOf(sharee.type, sharee.id),
  })))

/**
 * @param type the kind of recipient
 * @param id their id
 */
function keyOf(type: ShareType, id: string): string {
  return `${type}:${id}`
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
 * @param choice what the picker reports as picked
 */
async function pick(choice: NcSelectUsersModel | NcSelectUsersModel[] | undefined) {
  const sharee = options.value.find((candidate) =>
    !Array.isArray(choice) && keyOf(candidate.type, candidate.id) === choice?.id)
  picked.value = undefined
  if (!sharee) {
    return
  }
  refusal.value = ''
  checking.value = true
  try {
    refusal.value = await SearchApi.checkSharee(sharee.type, sharee.id)
  } catch (e) {
    refusal.value = (e as Error).message
  } finally {
    checking.value = false
  }
  if (refusal.value === '') {
    options.value = []
    emit('add', sharee)
  }
}
</script>

<style scoped lang="scss">
.recipients {
  &__picker {
    width: 100%;
    margin: 4px 0 12px;
  }

  &__empty {
    margin: 4px 0 12px;
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
