<template>
  <!-- Scrolls on its own so opening the detail panel narrows the grid
       instead of clipping its right-hand columns. -->
  <div ref="scroller"
       class="results-scroll"
       :class="{ 'results-scroll--under-start': hidden.start, 'results-scroll--under-end': hidden.end }"
       @scroll.passive="measure">
    <table ref="table" class="results" :class="{ 'results--resizing': dragging !== null }">
      <thead>
        <tr>
          <th class="results__star" />
          <th v-for="column in columns"
              :key="column.id"
              :class="cellClass(column.id)"
              :style="sizeOf(column)"
              :aria-sort="ariaSortOf(column.id)">
            <SortHeader v-if="sortableAs(column.id)"
                        :field="sortableAs(column.id)!"
                        :sort="sort"
                        :descending="descending"
                        :starts-descending="sortStartsDescending(sortableAs(column.id)!, metadata)"
                        @sort="$emit('sort', $event)">
              {{ headerOf(column) }}
            </SortHeader>
            <template v-else>{{ headerOf(column) }}</template>
            <!--
              Drag to resize, double-click to let the column size itself again;
              arrow keys when focused. A separator in ARIA's sense: it divides
              two columns and its value is the width of the one before it.
            -->
            <span class="results__resizer"
                  :class="{ 'results__resizer--active': dragging?.id === column.id }"
                  role="separator"
                  aria-orientation="vertical"
                  tabindex="0"
                  :aria-label="t('Resize the {column} column', { column: headerOf(column) })"
                  :aria-valuemin="COLUMN_WIDTH.min"
                  :aria-valuemax="COLUMN_WIDTH.max"
                  :aria-valuenow="widthOf(column) ?? undefined"
                  @pointerdown="startResize($event, column)"
                  @dblclick="setWidth(column.id, null)"
                  @keydown="nudge($event, column)"
                  @click.stop />
          </th>
          <th class="results__actions" />
        </tr>
      </thead>

      <tbody>
        <template v-for="row in visibleRows" :key="row.id">
          <tr v-if="row.kind === 'group'" class="results__group">
            <th :colspan="columns.length + 2"
                :class="`results__group--l${row.level}`"
                :style="{ paddingInlineStart: `${8 + row.level * 20}px` }">
              <!--
                A bare <button>, not NcButton: the whole group heading is the
                control, and it must read as a heading row, not a button.
              -->
              <button class="results__toggle" @click="toggle(row.id)">
                <component :is="collapsed.has(row.id) ? ChevronRight : ChevronDown" :size="18" />
                <span>{{ row.label }}</span>
                <span class="muted">({{ row.count }})</span>
              </button>
            </th>
          </tr>

          <tr v-else
              :class="{ 'results__row--selected': row.file.fileid === selectedId }"
              @click="$emit('select', row.file)"
              @dblclick="activate(row.file)">
            <td class="results__star">
              <NcButton :aria-label="row.file.favorite ? t('Remove from favorites') : t('Add to favorites')"
                        variant="tertiary"
                        @click.stop="$emit('toggleFavorite', row.file)">
                <template #icon>
                  <Star :size="20" :fill="row.file.favorite ? 'currentColor' : 'none'" />
                </template>
              </NcButton>
            </td>

            <td v-for="column in columns"
                :key="column.id"
                :class="cellClass(column.id)"
                :style="sizeOf(column)">
              <span v-if="column.id === 'name'" class="results__name" :title="row.file.name">
                <component :is="iconFor(row.file.mimetype, row.file.isFolder)" :size="20" />
                <span>
                  {{ row.file.name }}
                  <!--
                    The passage that matched, under the name it belongs to. A
                    content search returns files whose relevance is invisible
                    from the outside — the term is nowhere in the name, the
                    path or any column — so without this the row gives no
                    reason for being in the list.
                  -->
                  <small v-if="row.file.excerpt" class="results__excerpt muted">
                    {{ row.file.excerpt }}
                  </small>
                </span>
              </span>
              <span v-else :title="cellText(row.file, column.id)" class="muted">
                {{ cellText(row.file, column.id) }}
              </span>
            </td>

            <td class="results__actions">
              <FileCommands :file="row.file"
                            @details="showDetails"
                            @toggle-favorite="$emit('toggleFavorite', $event)"
                            @changed="$emit('changed', $event)" />
            </td>
          </tr>
        </template>
      </tbody>
    </table>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { NcButton } from '@nextcloud/vue'
import { ChevronDown, ChevronRight, Star } from '@lucide/vue'
import { useI18n } from '../composables/useI18n'
import { usePreferencesStore } from '../stores/preferencesStore'
import { useSearchStore } from '../stores/searchStore'
import { useFileCommands } from '../composables/useFileCommands'
import { useColumnWidths } from '../composables/useColumnWidths'
import { iconFor } from '../filters/fields'
import {
  clampColumnWidth,
  COLUMN_WIDTH,
  columnLabel,
  columnSortField,
  columnValue,
  isNumericColumn,
  sortStartsDescending,
} from '../filters/columns'
import { buildRows } from '../filters/grouping'
import FileCommands from './FileCommands.vue'
import SortHeader from './SortHeader.vue'
import type { ColumnPref, FileResult, GroupScope } from '../types/Search'

const { t } = useI18n()
const preferences = usePreferencesStore()
const search = useSearchStore()
const { fileLink, folderLink } = useFileCommands()
const { widthOf: storedWidth, setWidth } = useColumnWidths()

const props = defineProps<{
  files: FileResult[]
  sort: string
  descending: boolean
  selectedId?: number
  /** Which list this is; grouping levels are kept per list. */
  scope: GroupScope
}>()

const emit = defineEmits<{
  (e: 'sort', field: string): void
  (e: 'toggleFavorite', file: FileResult): void
  (e: 'select', file: FileResult): void
  (e: 'changed', file: FileResult): void
}>()

/**
 * "Show details" is the one place a click may open the panel — it is what was
 * asked for. Everywhere else the panel's own switch decides, so that selecting a
 * row never rearranges the page around it.
 * @param file the row whose menu was used
 */
function showDetails(file: FileResult) {
  emit('select', file)
  preferences.setSidebarPinned(true)
}

/**
 * Whether columns are scrolled out of sight under the pinned ones, at either
 * edge — that is when the pinned columns show an edge. Measured rather than
 * left to a scroll-state container query, which only Chromium supports.
 */
const scroller = ref<HTMLElement | null>(null)
const table = ref<HTMLElement | null>(null)
const hidden = ref({ start: false, end: false })

function measure() {
  const el = scroller.value
  if (!el) {
    return
  }
  // Negative in a right-to-left page, where the start is on the right.
  const scrolled = Math.abs(el.scrollLeft)
  hidden.value = {
    start: scrolled > 1,
    end: el.scrollWidth - el.clientWidth - scrolled > 1,
  }
}

// The grid changes width with the window and the details panel, and the table
// with its columns and rows; either can hide or reveal columns without a scroll.
const resizes = new ResizeObserver(measure)
onMounted(() => {
  for (const el of [scroller.value, table.value]) {
    if (el) {
      resizes.observe(el)
    }
  }
})
onBeforeUnmount(() => resizes.disconnect())

const metadata = computed(() => search.metadata)

const columns = computed(() => preferences.visibleColumns)

const allRows = computed(() =>
  buildRows(t, props.files, preferences.groupingFor(props.scope)))

/**
 * Collapsed groups are held by id, and the ids carry the group path — so a group
 * stays collapsed while the rows around it change, and is forgotten once its
 * grouping level is removed.
 */
const collapsed = ref(new Set<string>())

function toggle(id: string) {
  const next = new Set(collapsed.value)
  if (!next.delete(id)) {
    next.add(id)
  }
  collapsed.value = next
}

/**
 * Hides everything under a collapsed header, nested headers included. It walks
 * the list rather than filtering row by row because a row belongs to a collapsed
 * group exactly when its id starts with that group's id.
 */
const visibleRows = computed(() => {
  if (collapsed.value.size === 0) {
    return allRows.value
  }

  const hidden: string[] = []
  return allRows.value.filter((row) => {
    while (hidden.length > 0 && !row.id.startsWith(`${hidden[hidden.length - 1]}/`)) {
      hidden.pop()
    }
    if (hidden.length > 0) {
      return false
    }
    if (row.kind === 'group' && collapsed.value.has(row.id)) {
      hidden.push(row.id)
      return true // the header stays, or there would be no way to reopen it
    }
    return true
  })
})

/**
 * What a double-click does is the user's choice.
 * @param file
 */
function activate(file: FileResult) {
  switch (preferences.doubleClick) {
    case 'folder':
      window.open(folderLink(file), '_blank', 'noreferrer')
      break
    case 'none':
      break
    default:
      window.open(fileLink(file), '_blank', 'noreferrer')
  }
}

/**
 * Built-in header, unless the user renamed the column.
 * @param column
 */
function headerOf(column: ColumnPref): string {
  return column.label || columnLabel(t, column.id, metadata.value)
}

function sortableAs(id: string): string | null {
  return columnSortField(id, metadata.value)
}

function ariaSortOf(id: string): 'ascending' | 'descending' | undefined {
  const field = sortableAs(id)
  if (field === null || field !== props.sort) {
    return undefined
  }
  return props.descending ? 'descending' : 'ascending'
}

function cellClass(id: string): string {
  if (id === 'name') {
    return 'results__name-cell'
  }
  return isNumericColumn(id) ? 'numeric' : ''
}

function cellText(file: FileResult, id: string): string {
  return columnValue(t, file, id)
}

/**
 * The column being resized and the width it has reached. Held here while the
 * drag lasts, so the grid follows the pointer without writing to storage on
 * every pixel; the width is stored once, when it ends.
 */
const dragging = ref<{ id: string, width: number } | null>(null)

/** How much of the grid's width a dragged name column may take. */
const NAME_SHARE = 0.6

/**
 * The widest a column can be dragged to here and now. For the name that is its
 * share of the grid, so the drag stops where the column stops growing, and the
 * width saved is the width seen.
 * @param column the column being resized
 * @param cell its heading
 */
function limitOf(column: ColumnPref, cell: HTMLElement): (width: number) => number {
  const grid = scroller.value?.clientWidth ?? Infinity
  const padding = cell.getBoundingClientRect().width - contentWidth(cell)
  const ceiling = column.id === 'name' ? grid * NAME_SHARE - padding : Infinity
  return (width: number) => clampColumnWidth(Math.min(width, ceiling))
}

/**
 * @param column a visible column
 * @return pixels, or null while it sizes itself
 */
function widthOf(column: ColumnPref): number | null {
  return dragging.value?.id === column.id ? dragging.value.width : storedWidth(column.id)
}

/**
 * The same width on the heading and on every cell under it, as width, min and
 * max at once: in a table that sizes its columns to their content, a width
 * alone is only a suggestion the content may overrule.
 *
 * The pinned name keeps a ceiling, as the stylesheet gives it: pinned, it
 * stays in view while the rest scrolls under it, and at full width it would
 * leave no grid to scroll. Dragged, the ceiling is higher than the 40% it
 * sizes itself to — a width someone chose outranks a default.
 * @param column a visible column
 */
function sizeOf(column: ColumnPref): Record<string, string> | undefined {
  const width = widthOf(column)
  if (width === null) {
    return undefined
  }
  const size = column.id === 'name' ? `min(${width}px, ${NAME_SHARE * 100}cqi)` : `${width}px`
  return { width: size, minWidth: size, maxWidth: size }
}

/**
 * The width a cell's content box has now — what `width` is about to be set to,
 * so the drag starts where the column already is instead of jumping.
 * @param cell the column's heading
 */
function contentWidth(cell: HTMLElement): number {
  const style = getComputedStyle(cell)
  const padding = style.boxSizing === 'border-box'
    ? 0
    : parseFloat(style.paddingLeft) + parseFloat(style.paddingRight)
  return cell.getBoundingClientRect().width - padding
}

/**
 * Right-to-left, the column's end is on its left, and dragging left widens it.
 * @param cell the column's heading
 */
function directionOf(cell: HTMLElement): 1 | -1 {
  return getComputedStyle(cell).direction === 'rtl' ? -1 : 1
}

/**
 * @param event the press on a column's resize handle
 * @param column the column before the handle
 */
function startResize(event: PointerEvent, column: ColumnPref) {
  if (event.button !== 0) {
    return
  }
  const handle = event.currentTarget as HTMLElement
  const cell = handle.parentElement as HTMLElement
  const start = event.clientX
  const startWidth = contentWidth(cell)
  const direction = directionOf(cell)
  const limit = limitOf(column, cell)

  // Captured, so the drag carries on when the pointer leaves the thin handle
  // — which it does at once — and ends wherever the button is let go.
  event.preventDefault()
  handle.setPointerCapture(event.pointerId)
  dragging.value = { id: column.id, width: limit(startWidth) }

  const move = (moved: PointerEvent) => {
    dragging.value = { id: column.id, width: limit(startWidth + (moved.clientX - start) * direction) }
  }
  const end = () => {
    handle.removeEventListener('pointermove', move)
    handle.removeEventListener('pointerup', end)
    handle.removeEventListener('pointercancel', end)
    const width = dragging.value?.width
    dragging.value = null
    if (width !== undefined) {
      setWidth(column.id, width)
    }
  }
  handle.addEventListener('pointermove', move)
  handle.addEventListener('pointerup', end)
  handle.addEventListener('pointercancel', end)
}

let nudged: ReturnType<typeof setTimeout> | undefined

/**
 * Arrow keys widen and narrow the focused column, a step at a time. Saved a
 * moment after the last key, not on every one of them.
 * @param event the key pressed on a resize handle
 * @param column the column before the handle
 */
function nudge(event: KeyboardEvent, column: ColumnPref) {
  if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
    return
  }
  event.preventDefault()
  const cell = (event.currentTarget as HTMLElement).parentElement as HTMLElement
  const step = (event.key === 'ArrowRight' ? 16 : -16) * directionOf(cell)
  const width = limitOf(column, cell)((dragging.value?.id === column.id ? dragging.value.width : contentWidth(cell)) + step)
  dragging.value = { id: column.id, width }

  clearTimeout(nudged)
  nudged = setTimeout(() => {
    dragging.value = null
    setWidth(column.id, width)
  }, 500)
}
</script>

<style scoped lang="scss">
// Scrolls both ways itself, within the height the view gives it. A sticky cell
// sticks to its nearest scroll container, and one that only scrolled sideways
// would carry the header off with the rows when the view scrolled down.
.results-scroll {
  max-height: 100%;
  overflow: auto;
  // Lets the pinned name size itself against the grid's width.
  container-type: inline-size;
}

.results {
  width: 100%;
  min-width: 640px;
  // Not 'collapse': collapsed borders belong to the table, so a sticky cell's
  // border would stay behind while the cell itself moves.
  border-collapse: separate;
  border-spacing: 0;

  th,
  td {
    text-align: start;
    padding: 4px 8px;
    border-bottom: 1px solid var(--color-border);
    max-width: 340px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;

    // Pinned, the name would otherwise take up to 340px of a narrow grid and
    // leave hardly any room to scroll the other columns through. Here, not
    // under &__name-cell, or the 340px above outranks it.
    &.results__name-cell {
      max-width: min(340px, 40cqi);
    }

    // Not clipped, or the edge drawn just outside them (below) would be. The
    // name truncates itself, in its own span; the menu is only a button.
    &.results__name-cell,
    &.results__actions {
      overflow: visible;
    }
  }

  th {
    color: var(--color-text-maxcontrast);
    font-weight: 600;
  }

  // The column headings stay in view while the rows scroll under them. Above
  // the pinned actions cells of the body, which would otherwise paint over
  // them, being later in the document.
  thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    background: var(--color-main-background);

    // The handle shows itself where it can be used: a faint line while the
    // pointer is on the heading row, a strong one on the handle itself.
    &:hover .results__resizer::after {
      background: var(--color-border-dark);
    }

    // The corners are pinned both ways, and must stay above everything else.
    &.results__star,
    &.results__name-cell,
    &.results__actions {
      z-index: 3;
    }
  }

  tbody tr {
    cursor: pointer;

    &:hover {
      background: var(--color-background-hover);
    }
  }

  &__group th {
    padding-top: 14px;
    color: var(--color-main-text);
    font-weight: 700;
    background: var(--color-background-soft, transparent);
  }

  // Deeper levels read as subordinate rather than as more headings.
  &__group--l1 th,
  &__group--l1 {
    font-weight: 600;
    font-size: 95%;
  }

  // Sticky within its full-width heading cell, so the group's name stays in
  // view, like the file names, while the columns scroll sideways.
  &__toggle {
    position: sticky;
    inset-inline-start: 8px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: none;
    border: none;
    padding: 0;
    font: inherit;
    color: inherit;
    cursor: pointer;
  }

  &__group--l2,
  &__group--l3 {
    font-weight: 600;
    font-size: 90%;
    color: var(--color-text-maxcontrast);
  }

  &__row--selected {
    background: var(--color-primary-element-light);
  }

  // A strip on the heading's trailing edge, wider than the line it draws so it
  // is easy to catch. Inside the heading, which is sticky and therefore the
  // containing block, so it travels with the column.
  &__resizer {
    position: absolute;
    inset-block: 0;
    inset-inline-end: 0;
    width: 8px;
    cursor: col-resize;
    touch-action: none;

    &::after {
      content: '';
      position: absolute;
      inset-block: 6px;
      inset-inline-end: 0;
      width: 2px;
      border-radius: 1px;
    }

    &:hover::after,
    &:focus-visible::after,
    &--active::after {
      background: var(--color-primary-element);
    }

    &:focus-visible {
      outline: none;
    }
  }

  // While a column is dragged, nothing gets selected along the way and the
  // cursor stays the resize cursor wherever the pointer goes.
  &--resizing {
    cursor: col-resize;
    user-select: none;
  }

  &__star {
    // Exactly the button plus the cell's padding, so the pinned name can start
    // where the star ends instead of overlapping it or leaving a gap.
    width: var(--default-clickable-area);
    inset-inline-start: 0;

    &--on {
      color: var(--color-favorite);
    }
  }

  &__name-cell {
    inset-inline-start: calc(var(--default-clickable-area) + 16px);
  }

  &__actions {
    width: 44px;
    inset-inline-end: 0;
  }

  // Pinned while the rest scrolls sideways: the star and name so a row still
  // says which file it is, the menu so it is never out of reach.
  &__star,
  &__name-cell,
  &__actions {
    position: sticky;
    z-index: 1;
    // Opaque, or the columns scrolling underneath show through. The row's
    // hover and selected colours are repeated below for the same reason.
    background: var(--color-main-background);
  }

  // Every cell, not only the row: a pinned cell paints its own background, and
  // must change with the row it belongs to.
  &__row--selected td {
    background: var(--color-primary-element-light);
  }

  tbody tr:hover td {
    background: var(--color-background-hover);
  }

  &__excerpt {
    // Two lines: enough to show the term in context, never enough to turn the
    // row into a paragraph and cost the list its scannability.
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    overflow: hidden;
    font-size: 90%;
    line-height: 1.35;
  }

  &__name {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;

    svg {
      flex: 0 0 auto;
    }

    span {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    &:hover span {
      text-decoration: underline;
    }
  }
}

// While columns are hidden under the pinned ones, a soft edge says so — the
// same one the CRM's lists draw. A strip just outside the cell, full height,
// rather than a box-shadow, which fades out at the top and bottom of every
// cell and turns into a dashed bar down the grid.
.results-scroll--under-start .results__name-cell::after,
.results-scroll--under-end .results__actions::before {
  content: '';
  position: absolute;
  top: 0;
  bottom: 0;
  width: 8px;
  pointer-events: none;
}

.results-scroll--under-start .results__name-cell::after {
  inset-inline-start: 100%;
  background: linear-gradient(to right, rgba(0, 0, 0, 0.12), transparent);
}

.results-scroll--under-end .results__actions::before {
  inset-inline-end: 100%;
  background: linear-gradient(to left, rgba(0, 0, 0, 0.12), transparent);
}

// The edges fade away from the pinned column, which is mirrored right-to-left.
.results-scroll--under-start .results__name-cell:dir(rtl)::after {
  background: linear-gradient(to left, rgba(0, 0, 0, 0.12), transparent);
}

.results-scroll--under-end .results__actions:dir(rtl)::before {
  background: linear-gradient(to right, rgba(0, 0, 0, 0.12), transparent);
}

.numeric {
  text-align: end;
  white-space: nowrap;
}

.muted {
  color: var(--color-text-maxcontrast);
}
</style>
