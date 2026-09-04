<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Service;

use OCA\DcnFinder\Db\SavedSearch;
use OCA\DcnFinder\Db\SavedSearchMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Saved searches and recents.
 *
 * A "recent" is captured automatically whenever a search runs; a "saved" one is
 * named and kept deliberately. Both store the whole query, so re-running one
 * restores the term, the presets, the conditions and the sort together rather
 * than just the text.
 */
class SearchHistoryService
{
    /** How many recents to keep per user. Older ones are dropped on capture. */
    public const KEEP_RECENTS = 20;

    /**
     * How many searches a user may keep saved.
     *
     * A ceiling rather than a trim: a recent is something the app noticed, and
     * dropping the oldest costs nobody anything, but a saved search is something
     * a person decided to keep and silently discarding one would be a bug they
     * could not see. So the save is refused instead, and says so.
     *
     * The number matches what the list endpoint returns — before this, saving
     * past it simply made the newest ones invisible.
     */
    public const MAX_SAVED = 200;

    public function __construct(private SavedSearchMapper $mapper)
    {
    }

    /** @return array<string,mixed>[] */
    public function list(string $userId, string $kind): array
    {
        return array_map(
            static fn (SavedSearch $s) => $s->toArray(),
            $this->mapper->findByKind(
                $userId,
                $kind,
                $kind === SavedSearch::KIND_RECENT ? self::KEEP_RECENTS : self::MAX_SAVED,
            )
        );
    }

    /**
     * Records that a search ran. Re-running the same query moves the existing
     * entry to the top rather than adding a duplicate, so the list stays a
     * history of distinct searches instead of a keystroke log.
     *
     * @param array<string,mixed> $query
     */
    public function recordRecent(string $userId, array $query): void
    {
        if (self::isEmpty($query)) {
            return; // nothing worth remembering
        }

        $fingerprint = self::fingerprint($query);
        $existing = $this->mapper->findByFingerprint($userId, SavedSearch::KIND_RECENT, $fingerprint);

        if ($existing !== null) {
            $existing->setLastRun(time());
            $this->mapper->update($existing);

            return;
        }

        $entry = new SavedSearch();
        $entry->setUserId($userId);
        $entry->setKind(SavedSearch::KIND_RECENT);
        $entry->setQuery(json_encode($query, JSON_THROW_ON_ERROR));
        $entry->setFingerprint($fingerprint);
        $entry->setLastRun(time());
        $this->mapper->insert($entry);

        $this->mapper->trimRecents($userId, self::KEEP_RECENTS);
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    /**
     * @throws \RuntimeException when the user is already at {@see MAX_SAVED}
     */
    public function save(string $userId, string $name, string $description, array $query): array
    {
        if ($this->mapper->countByKind($userId, SavedSearch::KIND_SAVED) >= self::MAX_SAVED) {
            throw new \RuntimeException(
                'You have reached the maximum of ' . self::MAX_SAVED
                . ' saved searches. Delete one to save another.'
            );
        }

        $entry = new SavedSearch();
        $entry->setUserId($userId);
        $entry->setKind(SavedSearch::KIND_SAVED);
        $entry->setName(self::trimTo($name, 255) ?: 'Saved search');
        $entry->setDescription(self::trimTo($description, 1024));
        $entry->setQuery(json_encode($query, JSON_THROW_ON_ERROR));
        $entry->setFingerprint(self::fingerprint($query));
        $entry->setLastRun(time());

        return $this->mapper->insert($entry)->toArray();
    }

    /**
     * Renames a saved search. The query is deliberately not editable here — a
     * changed query is a different search, and silently rewriting one the user
     * saved would lose what they kept.
     *
     * @return array<string,mixed>
     * @throws DoesNotExistException
     */
    public function rename(string $userId, int $id, string $name, string $description): array
    {
        $entry = $this->mapper->findOwned($id, $userId);
        if ($entry->getKind() !== SavedSearch::KIND_SAVED) {
            // A named recent is a contradiction: recents are trimmed and deduped
            // behind the user's back. Keeping one means saving it, which the client
            // does by posting its query as a new saved search.
            throw new DoesNotExistException('Only saved searches can be renamed');
        }
        $entry->setName(self::trimTo($name, 255) ?: $entry->getName() ?? 'Saved search');
        $entry->setDescription(self::trimTo($description, 1024));

        return $this->mapper->update($entry)->toArray();
    }

    /**
     * Replaces the query a saved search holds, keeping its name and its place
     * in the list. Renaming deliberately cannot do this — see above — but
     * saving over a name that is already taken can, because the user was asked
     * about that one search by name and said to replace it.
     *
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     * @throws DoesNotExistException
     */
    public function replaceQuery(string $userId, int $id, array $query): array
    {
        $entry = $this->mapper->findOwned($id, $userId);
        if ($entry->getKind() !== SavedSearch::KIND_SAVED) {
            throw new DoesNotExistException('Only saved searches can be replaced');
        }
        $entry->setQuery(json_encode($query, JSON_THROW_ON_ERROR));
        $entry->setFingerprint(self::fingerprint($query));
        $entry->setLastRun(time());

        return $this->mapper->update($entry)->toArray();
    }

    /** @throws DoesNotExistException */
    public function touch(string $userId, int $id): void
    {
        $entry = $this->mapper->findOwned($id, $userId);
        $entry->setLastRun(time());
        $this->mapper->update($entry);
    }

    /** @throws DoesNotExistException */
    public function delete(string $userId, int $id): void
    {
        $this->mapper->delete($this->mapper->findOwned($id, $userId));
    }

    public function clearRecents(string $userId): void
    {
        foreach ($this->mapper->findByKind($userId, SavedSearch::KIND_RECENT, 1000) as $entry) {
            $this->mapper->delete($entry);
        }
    }

    /**
     * Identity of a query, ignoring presentation-only parts. Sort order is
     * excluded on purpose: re-sorting the same search is the same search, and
     * counting it separately would fill the recents with near-duplicates.
     *
     * @param array<string,mixed> $query
     */
    private static function fingerprint(array $query): string
    {
        $scope = is_array($query['scope'] ?? null) ? $query['scope'] : null;

        $identity = [
            'term' => trim((string)($query['term'] ?? '')),
            'conditions' => $query['conditions'] ?? [],
            // The stored query holds preset *ids*, not the mimetypes they resolve
            // to — reading 'mimetypes' here always found nothing, which quietly
            // made two searches differing only by file type the same recent.
            'typePreset' => $query['typePreset'] ?? null,
            'modifiedPreset' => $query['modifiedPreset'] ?? null,
            'matchAny' => (bool)($query['matchAny'] ?? false),
            // Level and id only: renaming an entity type must not split one
            // recent search into two.
            'scope' => $scope === null
                ? null
                : ['level' => $scope['level'] ?? '', 'id' => (int)($scope['id'] ?? 0)],
        ];

        return hash('sha256', json_encode($identity) ?: '');
    }

    /** @param array<string,mixed> $query */
    private static function isEmpty(array $query): bool
    {
        return trim((string)($query['term'] ?? '')) === ''
            && ($query['conditions'] ?? []) === []
            && ($query['typePreset'] ?? 'any') === 'any'
            && ($query['modifiedPreset'] ?? 'any') === 'any'
            // "Everything in this dossier" is a search someone will want back.
            && ($query['scope'] ?? null) === null;
    }

    private static function trimTo(string $value, int $length): string
    {
        return mb_substr(trim($value), 0, $length);
    }
}
