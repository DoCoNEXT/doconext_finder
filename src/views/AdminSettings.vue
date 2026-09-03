<template>
  <NcSettingsSection :name="PRODUCT_NAME"
                     :description="t('How files open on people\'s own machines.')">
    <p class="hint">
      {{ t('Most files are handed to the Nextcloud desktop client, which opens the synced copy so edits sync back. The formats below go to DoCoNEXT Bridge instead — for files no local application can open as they are, such as Outlook messages, which it converts first.') }}
    </p>

    <NcTextField :model-value="draft"
                 class="field"
                 :label="t('Extensions handled by DoCoNEXT Bridge')"
                 :label-outside="true"
                 placeholder="eml, msg"
                 @update:model-value="onInput" />

    <p class="hint">
      {{ t('Comma-separated. Leave empty to send everything to the desktop client; the Bridge is then not needed at all.') }}
    </p>

    <NcButton variant="primary" :disabled="saving" @click="save">
      {{ saving ? t('Saving…') : t('Save') }}
    </NcButton>

    <NcNoteCard v-if="notice" :type="noticeType">{{ notice }}</NcNoteCard>
  </NcSettingsSection>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { NcButton, NcNoteCard, NcSettingsSection, NcTextField } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { useI18n } from '../composables/useI18n'
import { BRIDGE_EXTENSIONS, PRODUCT_NAME } from '../constants'

const { t } = useI18n()

const draft = ref(BRIDGE_EXTENSIONS.join(', '))
const saving = ref(false)
const notice = ref('')
const noticeType = ref<'success' | 'error'>('success')

function onInput(value: string | number) {
  draft.value = String(value)
  notice.value = ''
}

async function save() {
  saving.value = true
  try {
    const { data } = await axios.put<{ extensions: string[] }>(
      generateUrl('/apps/doconext_finder/api/admin/bridge-extensions'),
      { extensions: draft.value },
    )
    // Show what was stored, not what was typed: the server lower-cases, strips
    // dots and drops duplicates, and the admin should see the result.
    draft.value = data.extensions.join(', ')
    noticeType.value = 'success'
    notice.value = data.extensions.length
      ? t('Saved. Open a page again for the change to take effect.')
      : t('Saved. Everything now goes to the Nextcloud desktop client.')
  } catch (error) {
    noticeType.value = 'error'
    notice.value = (error as Error).message
  } finally {
    saving.value = false
  }
}
</script>

<style scoped lang="scss">
.hint {
  color: var(--color-text-maxcontrast);
  max-width: 60em;
  margin-bottom: 12px;
}

.field {
  max-width: 30em;
  margin-bottom: 4px;
}
</style>
