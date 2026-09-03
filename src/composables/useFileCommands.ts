/**
 * Everything you can do to a file, as one list.
 *
 * The row menu and the details panel offer the same commands, and they drifted
 * apart when each built its own. Describing them as data instead of as markup
 * lets both render the identical menu — and lets the set grow without touching
 * either component.
 *
 * The commands mirror DoCoNEXT Finder for desktop, minus the ones a browser
 * cannot honour.
 */
import { computed } from 'vue'
import type { Component } from 'vue'
import {
  Download,
  ExternalLink,
  FileSearch,
  FolderOpen,
  FolderUp,
  Laptop,
  Link,
  Star,
  StarOff,
  Upload,
} from '@lucide/vue'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { useI18n } from './useI18n'
import { usePreviewStore } from '../stores/previewStore'
import { downloadFile } from '../services/download'
import { openLocally } from '../services/openLocally'
import { pickLocalFiles, uploadTo } from '../services/upload'
import { folderOf } from '../filters/grouping'
import type { FileResult } from '../types/Search'

export interface FileCommand {
  id: string
  label: string
  /** The icon that stands for the command. */
  icon: Component
  /** Set for commands that are a link; the rest carry `run`. */
  href?: string
  target?: string
  run?: () => void | Promise<void>
}

/**
 * /f/{id} is Nextcloud's own permalink; it resolves folders as well as files.
 * @param file the row to link to
 */
export function fileLink(file: FileResult): string {
  return generateUrl(`/f/${file.fileid}`)
}

/**
 * @param file the row whose folder to link to
 */
export function folderLink(file: FileResult): string {
  const dir = file.isFolder ? file.path : folderOf(file)

  return `${generateUrl('/apps/files/files')}?dir=${encodeURIComponent(`/${dir}`)}`
}

/**
 * @param onChanged called after a command changed the file on the server, so
 *   the caller can refresh the list it is showing
 */
export function useFileCommands(onChanged?: (_file: FileResult) => void) {
  const { t } = useI18n()
  const preview = usePreviewStore()

  /**
   * The folder an "upload here" lands in: the folder itself when the row is a
   * folder, otherwise the folder the file sits in.
   * @param file
   */
  function targetFolder(file: FileResult): string {
    return file.isFolder ? file.path : folderOf(file)
  }

  async function replaceVersion(file: FileResult) {
    const [picked] = await pickLocalFiles(false)
    if (!picked) {
      return
    }
    try {
      // Deliberately written to the existing path under its existing name: that
      // is what makes it a *version* rather than a second file.
      await uploadTo(file.path, picked)
      showSuccess(t('Uploaded a new version of {name}', { name: file.name }))
      onChanged?.(file)
    } catch (e) {
      showError((e as Error).message)
    }
  }

  async function uploadHere(file: FileResult) {
    const picked = await pickLocalFiles(true)
    if (picked.length === 0) {
      return
    }
    const folder = targetFolder(file)
    try {
      for (const content of picked) {
        await uploadTo(folder ? `${folder}/${content.name}` : content.name, content)
      }
      showSuccess(t('Uploaded {count} file(s) to {folder}', {
        count: picked.length,
        folder: folder || '/',
      }))
      onChanged?.(file)
    } catch (e) {
      showError((e as Error).message)
    }
  }

  async function copyLink(file: FileResult) {
    const url = new URL(fileLink(file), window.location.origin).href
    try {
      await navigator.clipboard.writeText(url)
      showSuccess(t('Link copied'))
    } catch {
      // Clipboard access needs a secure context and permission; say so rather
      // than failing silently.
      showError(t('Could not copy the link'))
    }
  }

  async function openInDesktop(file: FileResult) {
    try {
      await openLocally(file)
    } catch (e) {
      showError((e as Error).message)
    }
  }

  /**
   * @param file the row the menu belongs to
   * @param toggleFavorite called to star/unstar; omitted where the caller
   *   handles the star itself
   */
  function commandsFor(
    file: FileResult,
    toggleFavorite?: (_file: FileResult) => void,
  ): FileCommand[] {
    const commands: FileCommand[] = [
      {
        id: 'open',
        label: t('Open in Files'),
        icon: ExternalLink,
        href: fileLink(file),
        target: '_blank',
      },
    ]

    if (!file.isFolder) {
      commands.push({
        id: 'local',
        label: t('Open in local app'),
        icon: Laptop,
        run: () => openInDesktop(file),
      })
    }

    commands.push({
      id: 'folder',
      label: t('Open containing folder'),
      icon: FolderOpen,
      href: folderLink(file),
      target: '_blank',
    })

    if (!file.isFolder) {
      commands.push(
        {
          id: 'preview',
          label: t('Open full preview'),
          icon: FileSearch,
          run: () => preview.open(file),
        },
        {
          id: 'download',
          label: t('Download'),
          icon: Download,
          run: () => downloadFile(file),
        },
        {
          id: 'version',
          label: t('Upload new version'),
          icon: Upload,
          run: () => replaceVersion(file),
        },
      )
    }

    commands.push({
      id: 'upload',
      label: t('Upload to this folder'),
      icon: FolderUp,
      run: () => uploadHere(file),
    })

    if (toggleFavorite) {
      commands.push({
        id: 'favorite',
        label: file.favorite ? t('Remove from favorites') : t('Add to favorites'),
        icon: file.favorite ? StarOff : Star,
        run: () => toggleFavorite(file),
      })
    }

    commands.push({
      id: 'link',
      label: t('Copy link'),
      icon: Link,
      run: () => copyLink(file),
    })

    return commands
  }

  return { commandsFor, fileLink, folderLink, hasPreview: computed(() => preview.file !== null) }
}
