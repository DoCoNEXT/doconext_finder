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
  SquareArrowUpRight,
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
import { FileExistsError, pickLocalFiles, uploadTo } from '../services/upload'
import { folderOf } from '../filters/grouping'
import { coreProductName, HAS_ENTITY_SCOPE } from '../constants'
import { SearchApi } from '../services/SearchApi'
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

  /**
   * Says what went wrong in the user's language. The request's own message is
   * axios's ("Request failed with status code 507"): true, untranslated and no
   * help. The statuses a person can do something about get a sentence of their
   * own; anything else is left at what was being attempted.
   * @param attempted what failed, already translated
   * @param error what the request threw
   */
  function failed(attempted: string, error: unknown): void {
    const status = (error as { response?: { status?: number } } | null)?.response?.status
    const reason = {
      403: t('You do not have permission to do this.'),
      423: t('The file is locked, probably because it is open somewhere else.'),
      507: t('There is not enough storage space left.'),
    }[status ?? 0]

    showError(reason ? `${attempted} ${reason}` : attempted)
  }

  async function replaceVersion(file: FileResult) {
    const [picked] = await pickLocalFiles(false)
    if (!picked) {
      return
    }
    try {
      // Deliberately written to the existing path under its existing name: that
      // is what makes it a *version* rather than a second file.
      await uploadTo(file.path, picked, true)
      showSuccess(t('Uploaded a new version of {name}', { name: file.name }))
      onChanged?.(file)
    } catch (e) {
      failed(t('Could not upload a new version of {name}.', { name: file.name }), e)
    }
  }

  async function uploadHere(file: FileResult) {
    const picked = await pickLocalFiles(true)
    if (picked.length === 0) {
      return
    }
    const folder = targetFolder(file)
    // A name already in use is skipped and named, not replaced: the person
    // asked to add files to a folder, not to overwrite what was there.
    const skipped: string[] = []
    try {
      for (const content of picked) {
        try {
          await uploadTo(folder ? `${folder}/${content.name}` : content.name, content, false)
        } catch (e) {
          if (!(e instanceof FileExistsError)) {
            throw e
          }
          skipped.push(content.name)
        }
      }
      const uploaded = picked.length - skipped.length
      if (uploaded > 0) {
        showSuccess(t('Uploaded {count} file(s) to {folder}', {
          count: uploaded,
          folder: folder || '/',
        }))
        onChanged?.(file)
      }
      if (skipped.length > 0) {
        showError(t('Not uploaded, a file with that name is already there: {names}', { names: skipped.join(', ') }))
      }
    } catch (e) {
      failed(t('Could not upload to {folder}.', { folder: folder || '/' }), e)
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
      failed(t('Could not open {name} in the desktop app.', { name: file.name }), e)
    }
  }

  /**
   * @param file the row the menu belongs to
   * @param toggleFavorite called to star/unstar; omitted where the caller
   *   handles the star itself
   */
  /**
   * Opens the entity a file belongs to, in DoCoNEXT Core.
   *
   * A file outside every managed folder simply has no entity, which is not a
   * failure — so it says so rather than opening something that is not there.
   *
   * @param file the row that was acted on
   */
  async function openEntity(file: FileResult) {
    try {
      const entity = await SearchApi.entityForFile(file.fileid)
      if (entity === null) {
        showError(t('{name} does not belong to a record in {product}', {
          name: file.name,
          product: coreProductName(),
        }))

        return
      }
      window.open(entity.url, '_blank', 'noopener')
    } catch (e) {
      failed(t('Could not open {name} in {product}.', { name: file.name, product: coreProductName() }), e)
    }
  }

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

    // Offered for every row when Core is installed, and resolved only when
    // asked: which entity a file belongs to is a lookup per file, and doing it
    // for a whole page of results to decide whether to draw a menu entry would
    // cost a hundred queries to answer a question nobody asked yet.
    if (HAS_ENTITY_SCOPE) {
      commands.push({
        id: 'entity',
        label: t('Open in {product}', { product: coreProductName() }),
        icon: SquareArrowUpRight,
        run: () => openEntity(file),
      })
    }

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
