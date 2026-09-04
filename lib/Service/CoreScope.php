<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Service;

use OCA\DcnFinder\AppInfo\AppConstants;
use OCA\DcnFinder\Search\FileScope;
use OCP\App\IAppManager;
use OCP\IServerContainer;
use Psr\Log\LoggerInterface;

/**
 * What a search can be narrowed to, and which folders that means.
 *
 * Two kinds of answer, and only one of them needs Core:
 *
 * - **Folder** — any folder in the user's own tree, chosen from the file picker.
 *   Plain Nextcloud, always available, and already a useful scope on a server
 *   that has never heard of DoCoNEXT. Its id is already a file id, so it never
 *   reaches this service's Core half at all.
 * - **Workspace, entity type, entity** — DoCoNEXT Core's vocabulary, offered
 *   only when Core is there, cascading from one to the next.
 *
 * This is the only place in Finder that knows Core exists. Core absent,
 * disabled, an older version without the public service, or simply throwing all
 * mean the same thing: no types, no entities, and a scope that resolves to
 * nothing rather than silently widening to the whole account. The folder level
 * keeps working throughout.
 */
class CoreScope
{
    private const CORE_APP_ID = 'doconext_core';

    /** Core's public, semver-stable surface. Referenced by name so it may be absent. */
    private const CORE_VOCABULARY = 'OCA\\DcnCore\\Public\\Search\\ScopeVocabularyService';

    /** A typeahead is a menu, not a result set. */
    private const MAX_SUGGESTIONS = 25;

    public function __construct(
        private IAppManager $appManager,
        private IServerContainer $container,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Whether Core's entity vocabulary is available, deciding whether the type
     * and entity fields are offered at all. The folder field does not depend on
     * this and is always shown.
     */
    public function hasEntityVocabulary(): bool
    {
        return $this->core() !== null;
    }

    /**
     * Core's workspaces, as the user may see them.
     *
     * @return list<array{id: int, name: string}>
     */
    public function realms(): array
    {
        $core = $this->core();

        return $core === null ? [] : $this->guard(static fn () => $core->realms(), []);
    }

    /**
     * Every entity type the user may see, each carrying the workspace it belongs
     * to so the picker can narrow the list without asking again.
     *
     * `name` is the plural the list shows; `singularName` is what one of them is
     * called, which is what the entity field below labels itself with once a type
     * is chosen.
     *
     * @return list<array{id: int, name: string, singularName: string, realmId: int}>
     */
    public function entityTypes(): array
    {
        $core = $this->core();

        return $core === null ? [] : $this->guard(static fn () => $core->entityTypes(), []);
    }

    /**
     * Entities are too many for a dropdown, so this feeds a typeahead.
     *
     * @return list<array{id: int, name: string, context: string}>
     */
    public function entities(string $term, ?int $entityTypeId = null): array
    {
        $term = trim($term);
        $core = $this->core();
        if ($term === '' || $core === null) {
            return [];
        }

        return $this->guard(
            static fn () => $core->entities($term, $entityTypeId, self::MAX_SUGGESTIONS),
            [],
        );
    }

    /**
     * The entity a file belongs to, or null when it sits outside every managed
     * folder — or when Core is not installed at all.
     *
     * The link comes from Core rather than being assembled here: Core owns its
     * own routes, and a URL built from another app's knowledge of them is a URL
     * that breaks quietly the day they change.
     *
     * @return array{id: int, name: string, code: string, typeId: int, typeName: string, url: string}|null
     */
    public function entityForFile(int $fileId): ?array
    {
        $core = $this->core();
        if ($core === null) {
            return null;
        }

        return $this->guard(static fn () => $core->entityForFile($fileId), null);
    }

    /**
     * The folders that bound a scope, as Nextcloud file ids.
     *
     * File ids rather than paths: a path is per-user and changes when someone
     * renames a folder, while an id is stable and has to be resolved through the
     * caller's own home folder anyway — and that resolution *is* the access
     * check.
     *
     * `truncated` says the scope has more roots than are worth querying; the
     * caller should ask the user to narrow down instead of running it.
     *
     * @return array{roots: list<int>, truncated: bool}
     */
    public function roots(FileScope $scope): array
    {
        // A folder scope is already a file id. Asking Core to translate it would
        // make a plain Nextcloud feature depend on an app that may not be there.
        if ($scope->level === FileScope::LEVEL_FOLDER) {
            return ['roots' => [$scope->id], 'truncated' => false];
        }

        $core = $this->core();
        if ($core === null) {
            return ['roots' => [], 'truncated' => false];
        }

        return $this->guard(
            static fn () => $core->roots($scope->level, $scope->id),
            ['roots' => [], 'truncated' => false],
        );
    }

    // ── Core ──────────────────────────────────────────────────────────────────

    /**
     * Core's vocabulary service, or null when it cannot be had. Every reason —
     * not installed, not enabled for this user, an older version without the
     * class, a container that refuses to build it — collapses to the same null.
     */
    private function core(): ?object
    {
        if (!$this->appManager->isEnabledForUser(self::CORE_APP_ID)) {
            return null;
        }
        if (!class_exists(self::CORE_VOCABULARY)) {
            return null;
        }

        try {
            return $this->container->get(self::CORE_VOCABULARY);
        } catch (\Throwable $e) {
            $this->logger->warning('Core scope vocabulary could not be resolved', [
                'exception' => $e,
                'app'       => AppConstants::APP_ID,
            ]);

            return null;
        }
    }

    /**
     * Anything Core throws is Core's problem, not a failed search: fall back to
     * the caller's empty value so the page still renders.
     *
     * @template T
     * @param callable(): T $call
     * @param T $fallback
     * @return T
     */
    private function guard(callable $call, mixed $fallback): mixed
    {
        try {
            return $call();
        } catch (\Throwable $e) {
            $this->logger->warning('Core scope call failed', [
                'exception' => $e,
                'app'       => AppConstants::APP_ID,
            ]);

            return $fallback;
        }
    }
}
