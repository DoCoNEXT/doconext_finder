<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Search;

/**
 * Which mounts actually know who owns a file.
 *
 * `Node::getOwner()` looks like it always answers, and it does — but only two
 * kinds of storage record an owner. The rest inherit
 * `\OC\Files\Storage\Common::getOwner()`, which returns `\OC_User::getUser()`:
 * **whoever is asking**. So the same file reports `alice` to alice and `bob` to
 * bob, and a "Created by" column built on it shows every reader their own name.
 * Measured 2026-09-10 on a team folder and an SMB mount alike — see
 * `docs/search-coverage-external-and-federated.md`.
 *
 * The two that do know:
 *
 * - a home mount (`''`) — `Storage\Home::getOwner()` returns the home's user;
 * - a share (`'shared'`) — `SharedStorage::getOwner()` returns the share's
 *   owner, and a federated share returns the remote cloud id
 *   (`carla@example.com`), which names a real person too.
 *
 * Everything else — `'group'` for a team folder, `'external'` for external
 * storage, and whatever a mount provider invents next — has no owner to report,
 * and an empty cell is the honest answer. The list is therefore an allow-list:
 * a mount type nobody has checked is assumed not to know.
 */
final class FileOwner
{
    /** Mount types whose storage records an owner rather than echoing the reader. */
    private const KNOWS_OWNER = ['', 'shared'];

    /**
     * The owner to report for a node, given its mount type and what the storage
     * answered — empty when the storage does not actually know.
     */
    public static function of(string $mountType, string $reported): string
    {
        return in_array($mountType, self::KNOWS_OWNER, true) ? $reported : '';
    }
}
