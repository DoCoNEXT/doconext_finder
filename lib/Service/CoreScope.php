<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Service;

use OCA\DcnFinder\AppInfo\AppConstants;
use OCA\DcnFinder\Search\FileScope;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
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
 * It answers one more question of Core's, unrelated to scoping but sharing the
 * same seam: **what a principal id means.** Core stores the people a file names
 * as bare ids, so a column showing one needs a name from somewhere, and only
 * Core can say which keys hold them at all.
 *
 * Together with {@see CoreDistiller}, which hands Core a plain-language
 * question, this is where the app binds a Core service; nothing else does.
 * Core absent, disabled, an older version without the public service, or simply
 * throwing all mean the same thing: no types, no entities, no names — a scope that resolves
 * to nothing rather than silently widening to the whole account, and an id
 * shown as it was stored. The folder level keeps working throughout.
 */
class CoreScope
{
    private const CORE_APP_ID = 'doconext_core';

    /** Core's public, semver-stable surface. Referenced by name so it may be absent. */
    private const CORE_VOCABULARY = 'OCA\\DcnCore\\Public\\Search\\ScopeVocabularyService';

    /** Core's public principal lookup, absent on older versions of Core. */
    private const CORE_PRINCIPALS = 'OCA\\DcnCore\\Public\\Principals\\PrincipalNameService';

    /** A typeahead is a menu, not a result set. */
    private const MAX_SUGGESTIONS = 25;

    public function __construct(
        private IAppManager $appManager,
        private ContainerInterface $container,
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
     * An empty term is still a question: before anything is typed, the field
     * offers the user's starred entities and then the ones changed most recently,
     * as Core's own pickers do. A Core too old to answer it offers nothing until
     * something is typed, which is how this field always behaved.
     *
     * @return list<array{id: int, name: string, context: string}>
     */
    public function entities(string $term, ?int $entityTypeId = null): array
    {
        $term = trim($term);
        $core = $this->core();
        if ($core === null) {
            return [];
        }

        if ($term === '') {
            return method_exists($core, 'suggestedEntities')
                ? $this->guard(
                    static fn () => $core->suggestedEntities($entityTypeId, self::MAX_SUGGESTIONS),
                    [],
                )
                : [];
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
    /**
     * The metadata keys whose values name people rather than say something:
     * `dcn_core_behandelaar` and its like, each with what it holds — the one
     * kind of principal it accepts where Core's field says so, and whether it
     * can name several at once. Empty without Core, which leaves every value
     * shown exactly as it is stored.
     *
     * The kind is worth carrying: a user and a group may share an id, and
     * knowing which was meant is the difference between showing the account
     * `admin` and the group of that name. Holding several is worth carrying
     * because such a field stores them comma-separated in one string, which is
     * a different search from a single one.
     *
     * A Core that predates the richer answer still says which keys hold people.
     * Those fields keep their names and their picker, and count as single —
     * which is what every principal field on such a server was searched as.
     *
     * @return array<string, array{type: ?string, multi: bool}>
     */
    public function principalFields(): array
    {
        $core = $this->corePrincipals();
        if ($core === null) {
            return [];
        }

        if (method_exists($core, 'principalMetadataFields')) {
            return $this->guard(static fn () => $core->principalMetadataFields(), []);
        }

        return $this->guard(static function () use ($core): array {
            $fields = [];
            foreach ($core->principalMetadataKeys() as $key => $type) {
                $fields[(string) $key] = ['type' => $type, 'multi' => false];
            }

            return $fields;
        }, []);
    }

    /**
     * Names for stored principals, keyed by what was asked about: a bare id, or
     * `user:alice` where the key's own kind is known. An id Core cannot place is
     * simply absent from the answer, and the caller shows the id — which is the
     * truest thing left about a deleted account.
     *
     * @param list<string> $ids ids, or `type:id` for a key of a known kind
     * @return array<string, string> what was asked => display name
     */
    public function principalNames(array $ids): array
    {
        $core = $this->corePrincipals();
        if ($core === null || $ids === []) {
            return [];
        }

        return $this->guard(static function () use ($core, $ids): array {
            $names = [];
            foreach ($core->namesFor($ids) as $id => $principal) {
                if ($principal->isKnown()) {
                    $names[(string) $id] = $principal->displayName;
                }
            }

            return $names;
        }, []);
    }

    /**
     * People a principal field can be filtered on, for a typeahead.
     *
     * The field's key travels rather than a principal type, because the field's
     * own settings decide who may be offered — one kind or any of them, guests
     * in or out, certain groups only — and those settings are Core's to know.
     * Finder asks about a field; Core answers who fits it.
     *
     * An empty term offers nothing. Unlike an entity field, a principal field
     * has no short list worth showing before a name is typed: everyone on the
     * server would qualify.
     *
     * @return list<array{type: string, id: string, displayName: string}>
     */
    public function principalSuggestions(string $metadataKey, string $term): array
    {
        $term = trim($term);
        $core = $this->corePrincipals();
        if ($term === '' || $core === null || !method_exists($core, 'suggestionsFor')) {
            return [];
        }

        return $this->guard(static function () use ($core, $metadataKey, $term): array {
            $found = [];
            foreach ($core->suggestionsFor($metadataKey, $term, self::MAX_SUGGESTIONS) as $principal) {
                $found[] = [
                    'type'        => $principal->type,
                    'id'          => $principal->id,
                    'displayName' => $principal->displayName,
                ];
            }

            return $found;
        }, []);
    }

    private function core(): ?object
    {
        return $this->coreService(self::CORE_VOCABULARY);
    }

    private function corePrincipals(): ?object
    {
        return $this->coreService(self::CORE_PRINCIPALS);
    }

    /**
     * One of Core's public services, or null when this server has no Core, has
     * it switched off, or runs a version that predates the service.
     */
    private function coreService(string $class): ?object
    {
        if (!$this->appManager->isEnabledForUser(self::CORE_APP_ID)) {
            return null;
        }
        if (!class_exists($class)) {
            return null;
        }

        try {
            return $this->container->get($class);
        } catch (\Throwable $e) {
            $this->logger->warning(AppConstants::LOG_PREFIX . ' A Core service could not be resolved', [
                'service'   => $class,
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
            $this->logger->warning(AppConstants::LOG_PREFIX . ' Core scope call failed', [
                'exception' => $e,
                'app'       => AppConstants::APP_ID,
            ]);

            return $fallback;
        }
    }
}
