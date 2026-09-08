/**
 * Translation helper. ALWAYS use this — never import `translate` from
 * `@nextcloud/l10n` directly (it needs the app id on every call). The English
 * string itself is the translation key; missing keys fall back to it.
 *
 *   const { t } = useI18n()
 *   t('Save')
 *   t('Deleted {name}', { name })
 */
import { translate, translatePlural } from '@nextcloud/l10n'
import { APP_ID } from '../constants'

export function useI18n() {
  return {
    t: (text: string, vars?: Record<string, unknown>) => translate(APP_ID, text, vars),
    n: (singular: string, plural: string, count: number, vars?: Record<string, unknown>) =>
      translatePlural(APP_ID, singular, plural, count, vars),
  }
}
