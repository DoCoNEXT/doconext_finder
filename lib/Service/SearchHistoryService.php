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

    /**
     * The largest stored query, encoded. A real one is well under a kilobyte —
     * a term, a few conditions, a scope — so this only stops a request the page
     * did not send from parking megabytes per row, two hundred rows a user.
     */
    public const MAX_QUERY_BYTES = 65536;

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

        // Running a saved search is a run of that saved search, and nothing
        // besides: it already holds a place in the list, so a recent recorded
        // beside it would only show the same query twice. Bumping it is what
        // keeps the ordering honest — the search box orders both kinds on
        // last_run, and without this a saved search would carry the time it was
        // named forever and sink past recents however often it is used.
        $saved = $this->mapper->findByFingerprint($userId, SavedSearch::KIND_SAVED, $fingerprint);

        if ($saved !== null) {
            $saved->setLastRun(time());
            $this->mapper->update($saved);
            $this->dropRecentTwin($userId, $fingerprint);

            return;
        }

        $existing = $this->mapper->findByFingerprint($userId, SavedSearch::KIND_RECENT, $fingerprint);

        if ($existing !== null) {
            $existing->setLastRun(time());
            $this->mapper->update($existing);

            return;
        }

        $entry = new SavedSearch();
        $entry->setUserId($userId);
        $entry->setKind(SavedSearch::KIND_RECENT);
        $entry->setQuery(self::encode($query));
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
        $entry->setQuery(self::encode($query));
        $entry->setFingerprint(self::fingerprint($query));
        $entry->setLastRun(time());

        $saved = $this->mapper->insert($entry)->toArray();

        // Saving happens right after running, so the same query is nearly
        // always sitting in recents as well. The saved entry represents it from
        // here on — dropped only once that entry exists.
        $this->dropRecentTwin($userId, $entry->getFingerprint());

        return $saved;
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
        $entry->setQuery(self::encode($query));
        $entry->setFingerprint(self::fingerprint($query));
        $entry->setLastRun(time());

        $replaced = $this->mapper->update($entry)->toArray();
        $this->dropRecentTwin($userId, $entry->getFingerprint());

        return $replaced;
    }

    /**
     * Removes the recent that carries the same query as a saved search. Recents
     * exist to offer back what was never named; once a query has a name, the
     * pair would read as two searches that happen to be identical.
     */
    private function dropRecentTwin(string $userId, string $fingerprint): void
    {
        $recent = $this->mapper->findByFingerprint($userId, SavedSearch::KIND_RECENT, $fingerprint);

        if ($recent !== null) {
            $this->mapper->delete($recent);
        }
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
            // Two searches for the same word, one of them reading inside the
            // files, are different searches: they return different rows and cost
            // very different amounts to run.
            'searchContent' => (bool)($query['searchContent'] ?? false),
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
            // Not `searchContent`: that says where the term is looked for, so it
            // means nothing without one. Older stored searches kept the phrase
            // to look inside files for in a field of its own, and a query that
            // carries only that is still a search someone will want back.
            && trim((string)($query['content'] ?? '')) === ''
            && ($query['conditions'] ?? []) === []
            && ($query['typePreset'] ?? 'any') === 'any'
            && ($query['modifiedPreset'] ?? 'any') === 'any'
            // "Everything in this dossier" is a search someone will want back.
            && ($query['scope'] ?? null) === null;
    }

    /**
     * The one way a query reaches the table, so the size ceiling holds for a
     * saved search, a recent and a replaced query alike.
     *
     * @param array<string,mixed> $query
     * @throws \InvalidArgumentException when it encodes to more than {@see MAX_QUERY_BYTES}
     */
    private static function encode(array $query): string
    {
        $json = json_encode($query, JSON_THROW_ON_ERROR);
        if (strlen($json) > self::MAX_QUERY_BYTES) {
            throw new \InvalidArgumentException('This search is too large to keep');
        }

        return $json;
    }

    private static function trimTo(string $value, int $length): string
    {
        return mb_substr(trim($value), 0, $length);
    }
}
