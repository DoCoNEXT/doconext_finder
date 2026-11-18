<template>
  <NcSettingsSection :name="t('Created by and Modified by')"
                     :description="t('Let people filter on who made a file and who last changed it. Finder then stores this in the metadata of every file: a hash of both accounts, and the account id of the maker.')">
    <NcCheckboxRadioSwitch v-if="status"
                           type="switch"
                           :model-value="status.enabled"
                           :disabled="busy"
                           @update:model-value="setEnabled">
      {{ t('Keep track of who makes and changes files') }}
    </NcCheckboxRadioSwitch>

    <p v-if="status && !status.enabled" class="backfill__hint">
      {{ t('While this is off nothing is recorded and both filters are not offered. What was stored earlier stays.') }}
    </p>

    <template v-if="status?.enabled">
      <h3>{{ t('Existing files') }}</h3>
      <p class="backfill__hint">
        {{ t('New files and changes are recorded as they happen. Filling in existing files is your choice, because it writes to the database for every file.') }}
      </p>

      <p class="backfill__state">{{ stateText }}</p>
      <NcProgressBar v-if="status.state === 'running' || status.state === 'paused'"
                     class="backfill__progress"
                     :value="status.percent" />

      <p class="backfill__hint">
        {{ t('It runs in small steps through Nextcloud\'s background jobs. If a maintenance window is configured on this server, it only runs during that window. You can pause it at any moment, and it continues where it stopped.') }}
      </p>

      <NcButton v-if="active"
                variant="secondary"
                :disabled="busy"
                @click="change('pause')">
        {{ t('Pause') }}
      </NcButton>
      <NcButton v-else-if="status.state !== 'done'"
                variant="primary"
                :disabled="busy"
                @click="change('start')">
        {{ status.state === 'paused' ? t('Continue') : t('Start') }}
      </NcButton>
    </template>

    <NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>
  </NcSettingsSection>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { NcButton, NcCheckboxRadioSwitch, NcNoteCard, NcProgressBar, NcSettingsSection } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { useI18n } from '../composables/useI18n'

interface BackfillStatus {
  enabled: boolean
  state: 'idle' | 'queued' | 'running' | 'paused' | 'done'
  percent: number
}

/** Work only moves when a background job runs, so looking more often shows nothing new. */
const REFRESH_MS = 30_000

const { t } = useI18n()

const url = (path = '') => generateUrl(`/apps/doconext_finder/api/admin/file-people${path}`)

const status = ref<BackfillStatus | null>(null)
const busy = ref(false)
const error = ref('')

/** Started, whether or not a background job has picked it up yet. */
const active = computed(() => status.value?.enabled === true
  && (status.value.state === 'queued' || status.value.state === 'running'))

const stateText = computed(() => {
  switch (status.value?.state) {
    case 'queued': return t('Started. It begins the next time Nextcloud runs its background jobs.')
    case 'running': return t('Running: {percent}% done.', { percent: status.value.percent })
    case 'paused': return t('Paused at {percent}%.', { percent: status.value.percent })
    case 'done': return t('Done. Existing files can be found with both filters.')
    default: return t('Not started. Until then both filters find only files made or changed from now on.')
  }
})

async function load() {
  try {
    status.value = (await axios.get<BackfillStatus>(url())).data
    error.value = ''
  } catch (e) {
    error.value = (e as Error).message
  }
}

async function apply(request: Promise<{ data: BackfillStatus }>) {
  busy.value = true
  try {
    status.value = (await request).data
    error.value = ''
  } catch (e) {
    error.value = (e as Error).message
  } finally {
    busy.value = false
  }
}

function change(action: 'start' | 'pause') {
  return apply(axios.post<BackfillStatus>(url(`/backfill/${action}`)))
}

function setEnabled(enabled: boolean) {
  return apply(axios.put<BackfillStatus>(url(), { enabled }))
}

let timer: ReturnType<typeof setInterval> | undefined

onMounted(() => {
  load()
  timer = setInterval(() => {
    if (active.value) {
      load()
    }
  }, REFRESH_MS)
})

onBeforeUnmount(() => clearInterval(timer))
</script>

<style scoped lang="scss">
.backfill {
  &__state {
    margin-bottom: 8px;
    font-weight: 500;
  }

  &__progress {
    max-width: 30em;
    margin-bottom: 12px;
  }

  &__hint {
    max-width: 60em;
    margin-bottom: 12px;
    color: var(--color-text-maxcontrast);
  }
}
</style>
