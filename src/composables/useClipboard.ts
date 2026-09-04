import { ref } from 'vue'

/**
 * Copies text, with a short-lived "copied" flag for the button that asked.
 *
 * The Clipboard API needs a secure context, and a Nextcloud reached over plain
 * HTTP on a LAN is a real deployment — so a failure there falls back to the old
 * hidden-textarea trick rather than leaving the button silently dead.
 *
 * @param resetMs how long `copied` stays true after a successful copy
 */
export function useClipboard(resetMs = 2000) {
  const copied = ref(false)
  let timer: ReturnType<typeof setTimeout> | undefined

  async function copy(text: string): Promise<boolean> {
    if (!text) {
      return false
    }

    const done = (await writeAsync(text)) || writeLegacy(text)
    if (done) {
      copied.value = true
      clearTimeout(timer)
      timer = setTimeout(() => { copied.value = false }, resetMs)
    }

    return done
  }

  return { copied, copy }
}

/**
 * @param text what to put on the clipboard
 */
async function writeAsync(text: string): Promise<boolean> {
  try {
    if (!navigator.clipboard?.writeText) {
      return false
    }
    await navigator.clipboard.writeText(text)
    return true
  } catch {
    // Denied, or not a secure context. The caller falls back.
    return false
  }
}

/**
 * @param text what to put on the clipboard
 */
function writeLegacy(text: string): boolean {
  try {
    const field = document.createElement('textarea')
    field.value = text
    field.setAttribute('readonly', '')
    field.style.position = 'fixed'
    field.style.opacity = '0'
    document.body.appendChild(field)
    field.select()
    const done = document.execCommand('copy')
    document.body.removeChild(field)

    return done
  } catch {
    return false
  }
}
