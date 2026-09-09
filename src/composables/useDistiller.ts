/**
 * Turning a plain-language question into filters, by asking DoCoNEXT Core.
 *
 * The work is a model round trip, so it is scheduled and polled rather than
 * awaited: Core answers with a task id and this walks it to a verdict. What
 * comes back is never applied blindly — see filters/distilled.ts for what this
 * server can and cannot honour, and `dropped` for what it could not.
 */
import { onBeforeUnmount, ref } from 'vue'
import { CoreAiApi } from '../services/CoreAiApi'
import { applyDistilled } from '../filters/distilled'
import { HAS_CONTENT_SEARCH } from '../constants'
import type { Translate } from '../filters/presets'
import type { ScopeChip } from '../filters/distilled'
import type { FieldsResponse, SearchState } from '../types/Search'

/** How often to ask whether the model is done. */
const POLL_MS = 800

/**
 * When to give up. A distillation is a convenience over filters someone could
 * set by hand, so waiting a minute for one is worse than saying it did not work.
 */
const TIMEOUT_MS = 30000

export type DistilOutcome = 'ready' | 'empty' | 'failed' | 'timeout'

export function useDistiller() {
  /** True while a question is being understood. */
  const running = ref(false)

  /**
   * What Core understood, each carrying enough to undo itself. Kept as written
   * at distil time; the view decides which are still true of the query, so a
   * chip whose filter has since been changed or cleared stops showing itself.
   */
  const chips = ref<ScopeChip[]>([])

  /** Pieces this server could not apply, in the user's words. */
  const dropped = ref<string[]>([])

  /** How the last attempt ended, or '' before the first one. */
  const outcome = ref<DistilOutcome | ''>('')

  /**
   * Bumped on every new question. A poll loop that finds it changed abandons
   * itself: someone who types a second question is no longer waiting for the
   * answer to the first, and letting a stale one win would apply filters for a
   * question that is no longer on screen.
   */
  let generation = 0

  function reset() {
    generation += 1
    running.value = false
    chips.value = []
    dropped.value = []
    outcome.value = ''
  }

  onBeforeUnmount(() => {
    generation += 1
  })

  /**
   * Asks Core what a question means and returns the state to run, or null when
   * nothing usable came back.
   *
   * @param t translation function
   * @param question what the user typed
   * @param base the current search state, so their scope survives
   * @param schema this server's filterable surface
   * @param realmId the realm to distil against, or null for Core's own default
   */
  async function distil(
    t: Translate,
    question: string,
    base: SearchState,
    schema: FieldsResponse | null,
    realmId: number | null,
  ): Promise<SearchState | null> {
    reset()
    const mine = generation
    running.value = true

    try {
      const taskId = await CoreAiApi.distil(question, realmId)
      const deadline = Date.now() + TIMEOUT_MS

      for (;;) {
        if (mine !== generation) {
          return null // a newer question replaced this one
        }
        if (Date.now() > deadline) {
          outcome.value = 'timeout'
          return null
        }

        const poll = await CoreAiApi.poll(taskId, realmId)

        if (poll.status === 'ready' && poll.understood) {
          const applied = applyDistilled(t, poll.understood, base, schema, HAS_CONTENT_SEARCH)
          if (mine !== generation) {
            return null
          }
          chips.value = applied.chips
          dropped.value = applied.dropped
          outcome.value = 'ready'

          return applied.state
        }

        if (poll.status === 'empty_scope') {
          outcome.value = 'empty'
          return null
        }
        if (poll.status === 'failed') {
          outcome.value = 'failed'
          return null
        }

        await sleep(POLL_MS)
      }
    } catch {
      // Core unreachable, not allowed, or answering something unexpected. The
      // filters are still there to be set by hand, so this is a disappointment
      // rather than an error worth a stack trace.
      outcome.value = 'failed'

      return null
    } finally {
      if (mine === generation) {
        running.value = false
      }
    }
  }

  return { running, chips, dropped, outcome, distil, reset }
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => { setTimeout(resolve, ms) })
}
