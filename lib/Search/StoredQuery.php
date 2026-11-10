<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Search;

/**
 * Turns a stored search back into the request that runs it, on the server.
 *
 * A stored search holds the *interface* state — which type and date preset were
 * picked, not the mimetypes and timestamps behind them — so that "Last 7 days"
 * means the seven days before each run. Resolving that is normally the page's
 * job (`searchStore.request()` in src/stores/searchStore.ts); a scheduled search
 * runs with no page open, so this does the same here. The two must agree: what
 * the job counted is what the page shows when the user opens the search, and a
 * difference reads as "it said 12 and I see 9".
 *
 * Which is why every rule below names its twin in src/. The builtin type lists
 * are checked against src/filters/presets.ts by StoredQueryTest.
 */
final class StoredQuery
{
    /**
     * The builtin Type filters, as src/filters/presets.ts defines them.
     *
     * The ids are {@see \OCA\DcnFinder\AppInfo\AppConstants::BUILTIN_FILE_TYPE_IDS};
     * the labels are translated client-side and not needed here.
     */
    public const BUILTIN_MIMETYPES = [
        'files' => ['application/%', 'text/%', 'image/%', 'video/%', 'audio/%', 'message/%'],
        'documents' => [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.oasis.opendocument.text',
            'text/plain',
            'text/markdown',
        ],
        'spreadsheets' => [
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.oasis.opendocument.spreadsheet',
            'text/csv',
        ],
        'presentations' => [
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.oasis.opendocument.presentation',
        ],
        'pdf' => ['application/pdf'],
        'images' => ['image/%'],
        'video' => ['video/%'],
        'audio' => ['audio/%'],
        'email' => ['message/rfc822', 'application/vnd.ms-outlook'],
        'archives' => [
            'application/zip',
            'application/x-tar',
            'application/gzip',
            'application/x-7z-compressed',
            'application/vnd.rar',
        ],
        'folders' => ['httpd/unix-directory'],
    ];

    /** `RETIRED_FIELDS` in src/filters/fields.ts: dropped, as the page drops them. */
    public const RETIRED_FIELDS = ['owner', 'path'];

    /** `RENAMED_PRESETS` in src/filters/presets.ts. */
    private const RENAMED_PRESETS = ['year' => 'this-year'];

    private const DAY = 86400;

    /**
     * @param array<string,mixed> $stored the query as SavedSearch keeps it
     * @param list<array<string,mixed>> $typeFilters the configured Type filters,
     *                                               as FileTypeFilterSettings::list() gives them
     * @param \DateTimeImmutable $now the moment of the run, in the user's own time zone:
     *                                "today" and "this month" are questions about their calendar
     * @return array<string,mixed> a body for {@see FileQuery::fromArray()}, without paging
     */
    public static function toRequest(array $stored, array $typeFilters, \DateTimeImmutable $now): array
    {
        $stored = self::restored($stored);
        $term = trim((string)($stored['term'] ?? ''));
        [$after, $before] = self::modifiedRange(
            (string)($stored['modifiedPreset'] ?? 'any'),
            $now,
        );
        $scope = is_array($stored['scope'] ?? null) ? $stored['scope'] : null;

        return [
            'term' => $term,
            'content' => ($stored['searchContent'] ?? false) === true ? $term : '',
            'conditions' => self::conditions($stored['conditions'] ?? []),
            'mimetypes' => self::mimetypes($stored, $typeFilters),
            'modifiedAfter' => $after,
            'modifiedBefore' => $before,
            // Level and id only, as the page sends it: the label is display.
            'scope' => $scope === null ? null : ['level' => $scope['level'] ?? '', 'id' => $scope['id'] ?? 0],
            'matchAny' => (bool)($stored['matchAny'] ?? false),
            'sort' => (string)($stored['sort'] ?? 'mtime'),
            'descending' => (bool)($stored['descending'] ?? true),
        ];
    }

    /**
     * `restored()` in src/services/SearchApi.ts: a search kept from before the
     * term and the phrase to look inside files became one question carries the
     * phrase in `content`.
     *
     * @param array<string,mixed> $stored
     * @return array<string,mixed>
     */
    private static function restored(array $stored): array
    {
        $phrase = trim((string)($stored['content'] ?? ''));
        unset($stored['content']);
        if ($phrase === '') {
            return $stored;
        }

        if (trim((string)($stored['term'] ?? '')) === '') {
            $stored['term'] = $phrase;
        }
        $stored['searchContent'] = true;
        if (($stored['sort'] ?? '') === FileQuery::SORT_RELEVANCE) {
            $stored['sort'] = 'mtime';
        }

        return $stored;
    }

    /**
     * Half-filled rows and retired fields dropped, the display label stripped —
     * `isUsable()`, `RETIRED_FIELDS` and `sendable()` on the page.
     *
     * @return list<array<string,mixed>>
     */
    private static function conditions(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $condition) {
            if (!is_array($condition)) {
                continue;
            }
            $value = $condition['value'] ?? null;
            if ($value === null || $value === '' || in_array($condition['field'] ?? '', self::RETIRED_FIELDS, true)) {
                continue;
            }
            unset($condition['label']);
            $out[] = $condition;
        }

        return $out;
    }

    /**
     * The picked Type filters, as ids — `typeIds()` in src/services/SearchApi.ts.
     * A search stored before several could be picked holds one id in
     * `typePreset`, "any" for none.
     *
     * @param array<string,mixed> $stored
     * @return list<string>
     */
    public static function typeIds(array $stored): array
    {
        if (is_array($stored['typePresets'] ?? null)) {
            return array_values(array_filter(
                $stored['typePresets'],
                static fn ($id): bool => is_string($id) && $id !== 'any',
            ));
        }

        $legacy = (string)($stored['typePreset'] ?? 'any');

        return $legacy === 'any' ? [] : [$legacy];
    }

    /**
     * Every mimetype of every picked type, once — `selectedTypes()` and
     * `mimetypesOf()` in src/filters/presets.ts. Each id is the distilled type
     * when that is the one it names, else the configured filter. An id no
     * configured filter answers to adds nothing — the page does the same, so an
     * admin retiring a filter widens the search in both places alike.
     *
     * @param array<string,mixed> $stored
     * @param list<array<string,mixed>> $typeFilters
     * @return list<string>
     */
    private static function mimetypes(array $stored, array $typeFilters): array
    {
        $custom = is_array($stored['customType'] ?? null) ? $stored['customType'] : null;
        $out = [];

        foreach (self::typeIds($stored) as $preset) {
            array_push($out, ...self::mimetypesOfType($preset, $custom, $typeFilters));
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array<string,mixed>|null $custom
     * @param list<array<string,mixed>> $typeFilters
     * @return list<string>
     */
    private static function mimetypesOfType(string $preset, ?array $custom, array $typeFilters): array
    {
        if ($custom !== null && ($custom['id'] ?? null) === $preset) {
            return self::strings($custom['mimetypes'] ?? []);
        }

        foreach ($typeFilters as $entry) {
            if (($entry['id'] ?? null) !== $preset) {
                continue;
            }

            return ($entry['type'] ?? '') === 'custom'
                ? self::strings($entry['mimetypes'] ?? [])
                : (self::BUILTIN_MIMETYPES[$preset] ?? []);
        }

        return [];
    }

    /**
     * `modifiedPresets()` in src/filters/presets.ts, period for period.
     *
     * @return array{0: ?int, 1: ?int} after, before
     */
    public static function modifiedRange(string $preset, \DateTimeImmutable $now): array
    {
        $preset = self::RENAMED_PRESETS[$preset] ?? $preset;
        $time = $now->getTimestamp();
        $midnight = $now->setTime(0, 0);
        $month = $midnight->modify('first day of this month');
        $year = $midnight->setDate((int)$now->format('Y'), 1, 1);

        return match ($preset) {
            'today' => [$midnight->getTimestamp(), null],
            'week' => [$time - 7 * self::DAY, null],
            'month' => [$time - 30 * self::DAY, null],
            'this-month' => [$month->getTimestamp(), null],
            'last-month' => [$month->modify('-1 month')->getTimestamp(), $month->getTimestamp()],
            'this-year' => [$year->getTimestamp(), null],
            'last-year' => [$year->modify('-1 year')->getTimestamp(), $year->getTimestamp()],
            default => [null, null],
        };
    }

    /** @return list<string> */
    private static function strings(mixed $raw): array
    {
        return is_array($raw)
            ? array_values(array_filter(array_map(static fn ($m) => is_string($m) ? $m : '', $raw), static fn (string $m) => $m !== ''))
            : [];
    }
}
