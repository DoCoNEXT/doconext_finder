/**
 * Client for DoCoNEXT Core's file distiller.
 *
 * Called straight from the browser, on the session the page already has:
 * `@nextcloud/axios` adds the CSRF token and the cookie, and Core's routes are
 * same-origin, so none of the DAV or CSP subtleties elsewhere in this app apply.
 *
 * Deliberately no PHP coupling to Core. This app never loads a Core class for
 * the distiller, so an instance without Core simply never reaches these calls —
 * the smart-search affordance is not offered there at all.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import type { AiStatus, DistilPoll } from '../types/Ai'

/** Core's own app id; its routes live under /apps/doconext_core/api. */
const CORE_API = '/apps/doconext_core/api'

const url = (path: string) => generateUrl(`${CORE_API}${path}`)

/**
 * Core resolves "which realm's schema do I distil against" from the user's
 * pinned preference. This app has its own scope picker, which may point
 * somewhere else entirely — so when it does, say so, or the question is
 * understood against the wrong vocabulary.
 */
function realmHeaders(realmId: number | null): Record<string, string> {
  return realmId === null ? {} : { 'X-Dcn-Core-Realm-Id': String(realmId) }
}

export const CoreAiApi = {
  /**
   * Whether distillation can run at all: Core enabled, a provider reachable,
   * and admin-allowlisted. Failure is not an error — an instance without Core
   * answers 404, and that simply means "no AI here".
   */
  async status(): Promise<AiStatus> {
    try {
      const { data } = await axios.get<AiStatus>(url('/ai/status'))
      return data
    } catch {
      return { enabled: false, available: false, contextChat: false }
    }
  },

  /**
   * Hands a question to Core and gets a task id back. The work is a model round
   * trip, so it is scheduled rather than awaited.
   * @param question what the user typed, in their own words
   * @param realmId the realm its schema should be read from, or null for Core's own
   */
  async distil(question: string, realmId: number | null): Promise<number> {
    const { data } = await axios.post<{ taskId: number }>(
      url('/ai/distil-files'),
      { question },
      { headers: realmHeaders(realmId) },
    )

    return data.taskId
  },

  /**
   * One poll of a running distillation.
   *
   * 'empty_scope' is a real answer, not a failure: the model understood the
   * question and found nothing in it that maps to a filter this schema has.
   * @param taskId as returned by distil()
   * @param realmId the realm the task was started for
   */
  async poll(taskId: number, realmId: number | null): Promise<DistilPoll> {
    const { data } = await axios.get<DistilPoll>(
      url(`/ai/distil-files/${taskId}`),
      { headers: realmHeaders(realmId) },
    )

    return data
  },
}
