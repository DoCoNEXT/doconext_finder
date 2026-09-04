<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Search;

/**
 * Where a search starts, rather than what it matches.
 *
 * A scope names one level and one id — never a combination. Either a plain
 * folder, or, when DoCoNEXT Core is installed, one rung of its hierarchy:
 * workspace, entity type, or a single entity. Those three are strictly nested,
 * so the deepest one the user picked is already the narrowest and ANDing its
 * ancestors would only restate it.
 *
 * Folder and the Core levels are alternatives rather than a cascade: a folder is
 * a place, an entity is a thing that has a place, and letting someone ask for
 * both would raise a question with no honest answer when they disagree.
 *
 * What the id means depends on the level. A folder id is a Nextcloud file id and
 * needs no translation; the Core levels carry Core's own ids, which
 * {@see \OCA\DcnFinder\Service\CoreScope::roots()} turns into folders.
 */
final class FileScope
{
    public const LEVEL_FOLDER = 'folder';
    public const LEVEL_REALM = 'realm';
    public const LEVEL_ENTITY_TYPE = 'entityType';
    public const LEVEL_ENTITY = 'entity';

    public const LEVELS = [
        self::LEVEL_FOLDER,
        self::LEVEL_REALM,
        self::LEVEL_ENTITY_TYPE,
        self::LEVEL_ENTITY,
    ];

    private function __construct(
        public readonly string $level,
        public readonly int $id,
    ) {
    }

    /**
     * @param array<string,mixed> $body the `scope` member of a search request
     * @throws \InvalidArgumentException on an unusable level or id
     */
    public static function fromArray(array $body): self
    {
        $level = (string)($body['level'] ?? '');
        if (!in_array($level, self::LEVELS, true)) {
            throw new \InvalidArgumentException(
                'scope.level must be one of: ' . implode(', ', self::LEVELS)
            );
        }

        $id = (int)($body['id'] ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('scope.id must be a positive integer');
        }

        return new self($level, $id);
    }
}
