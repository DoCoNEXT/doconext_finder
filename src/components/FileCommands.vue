<template>
  <!--
    The row menu. The details panel renders the same commands into the sidebar
    header's own NcActions, which only recognises NcAction* children — a wrapper
    component is invisible to it — so the loop is repeated there rather than
    this component being reused. The command list itself is shared.
  -->
  <NcActions :aria-label="t('Actions')" @click.stop>
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
import { NcActionButton, NcActionLink, NcActions } from '@nextcloud/vue'
import { useI18n } from '../composables/useI18n'
import { useFileCommands } from '../composables/useFileCommands'
import type { FileResult } from '../types/Search'

const { t } = useI18n()

const props = defineProps<{ file: FileResult }>()

const emit = defineEmits<{
  (e: 'toggle-favorite', file: FileResult): void
  /** A command changed the file on the server; the list should reload. */
  (e: 'changed', file: FileResult): void
}>()

const { commandsFor } = useFileCommands((file) => emit('changed', file))

const commands = computed(() =>
  commandsFor(props.file, (file) => emit('toggle-favorite', file)))
</script>
