<template>
  <!--
    The row menu. The details panel renders the same commands into the sidebar
    header's own NcActions, which only recognises NcAction* children — a wrapper
    component is invisible to it — so the loop is repeated there rather than
    this component being reused. The command list itself is shared.
  -->
  <NcActions :aria-label="t('Actions')" @click.stop>
    <!--
      The way in when the details panel is switched off: a row click selects,
      it never opens the panel, so asking for details has to be something you
      can actually ask for. The panel itself does not offer it — it is already
      showing what the entry would show.
    -->
    <NcActionButton @click="$emit('details', file)">
      <template #icon>
        <PanelRight :size="20" />
      </template>
      {{ t('Show details') }}
    </NcActionButton>
    <NcActionSeparator />

    <template v-for="command in commands" :key="command.id">
      <NcActionLink v-if="command.href" :href="command.href" :target="command.target">
        <template #icon>
          <component :is="command.icon" :size="20" />
        </template>
        {{ command.label }}
      </NcActionLink>
      <NcActionButton v-else @click="command.run?.()">
        <template #icon>
          <component :is="command.icon" :size="20" />
        </template>
        {{ command.label }}
      </NcActionButton>
    </template>
  </NcActions>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { NcActionButton, NcActionLink, NcActionSeparator, NcActions } from '@nextcloud/vue'
import { PanelRight } from '@lucide/vue'
import { useI18n } from '../composables/useI18n'
import { useFileCommands } from '../composables/useFileCommands'
import type { FileResult } from '../types/Search'

const { t } = useI18n()

const props = defineProps<{ file: FileResult }>()

const emit = defineEmits<{
  /** Show this file in the details panel, opening the panel if it is closed. */
  (e: 'details', file: FileResult): void
  (e: 'toggleFavorite', file: FileResult): void
  /** A command changed the file on the server; the list should reload. */
  (e: 'changed', file: FileResult): void
}>()

const { commandsFor } = useFileCommands((file) => emit('changed', file))

const commands = computed(() =>
  commandsFor(props.file, (file) => emit('toggleFavorite', file)))
</script>
