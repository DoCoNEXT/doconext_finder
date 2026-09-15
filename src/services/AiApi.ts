/**
 * Client for this app's plain-language search endpoints.
 *
 * The browser talks to this app only; this app asks DoCoNEXT Core in-process.
 * `@nextcloud/axios` adds the CSRF token and the cookie, and the routes are
 * same-origin, so none of the DAV or CSP subtleties elsewhere in this app apply.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { API_BASE } from '../constants'
import type { AiStatus, DistilPoll } from '../types/Ai'

const url = (path: string) => generateUrl(`${API_BASE}${path}`)

/**
 * Core reads "which workspace do I distil against" off the request, and honours
 * this header for calls between apps. This app has its own scope picker, which
 * may point somewhere else entirely — so when it does, say so, or the question
 * is understood against the wrong vocabulary. The header keeps Core's name
 * because Core is what reads it: both apps are looking at the one request.
 */
function realmHeaders(realmId: number | null): Record<string, string> {
  return realmId === null ? {} : { 'X-Dcn-Core-Realm-Id': String(realmId) }
}

export const AiApi = {
  /**
   * Whether distillation can run at all: enabled, a provider reachable, and
   * admin-allowlisted. Failure is not an error — an instance without Core
   * answers that nothing is available, and that simply means "no AI here".
   */
  async status(): Promise<AiStatus> {
    try {
      const { data } = await axios.get<AiStatus>(url('/ai/status'))
      return data
    } catch {
      return { enabled: false, available: false }
    }
  },

  /**
   * Hands a question over and gets a task id back. The work is a model round
   * trip, so it is scheduled rather than awaited.
   * @param question what the user typed, in their own words
   * @param realmId the workspace its schema should be read from, or null for the pinned one
   */
  async distil(question: string, realmId: number | null): Promise<number> {
    const { data } = await axios.post<{ taskId: number }>(
      url('/ai/distil'),
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
   * @param realmId the workspace the task was started for
   */
  async poll(taskId: number, realmId: number | null): Promise<DistilPoll> {
    const { data } = await axios.get<DistilPoll>(
      url(`/ai/distil/${taskId}`),
      { headers: realmHeaders(realmId) },
    )

    return data
  },
}
