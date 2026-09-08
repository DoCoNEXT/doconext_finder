<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Service;

use OCA\DcnFinder\AppInfo\AppConstants;
use OCA\DcnFinder\Search\MetadataFields;
use OCP\Config\IUserConfig;

/**
 * Per-user interface preferences: which columns the result grid shows, in what
 * order, under what label, and how results are grouped.
 *
 * Stored as one JSON blob in the user's config rather than in a table. It is a
 * small document read whole on load and written whole on change, never queried
 * or joined — a table would add a migration for every new preference and buy
 * nothing.
 *
 * Unknown keys are dropped on read, so a preference removed from a later version
 * cannot resurrect itself, and a hand-edited value cannot inject anything into
 * the interface.
 */
class PreferencesService
{
    private const KEY = 'preferences';

    /**
     * Columns the grid can show, in their default order.
     *
     * `type` and `mimetype` are deliberately both here: "Type" is the readable
     * category a person filters by ("Spreadsheets"), `mimetype` the exact media
     * type a person pastes into a condition. Collapsing them loses one or the
     * other.
     */
    public const COLUMNS = [
        'name', 'folder', 'size', 'modified', 'created', 'type', 'mimetype', 'createdBy', 'modifiedBy',
    ];

    /** Shown unless the user says otherwise; the rest start hidden. */
    private const DEFAULT_VISIBLE = ['name', 'folder', 'size', 'modified'];

    /** Built-in grouping fields. A `meta:` key also works. */
    public const GROUPINGS = ['folder', 'type', 'mimetype', 'modified', 'createdBy', 'modifiedBy'];

    /**
     * Grouping levels a user may stack. Beyond a handful the headers outnumber
     * the rows and the grid stops being a list.
     */
    private const MAX_GROUPING_LEVELS = 4;

    /** Page sizes offered. Bounded because every row costs a preview request later. */
    public const PAGE_SIZES = [25, 50, 100, 200];

    /** What a double-click on a row does. */
    public const CLICK_ACTIONS = ['open', 'folder', 'none'];

    /** Columns the grid can sort on, mirroring FileQuery::SORTS. */
    private const SORTS = ['name', 'size', 'mtime', 'creation_time'];

    public function __construct(
        private IUserConfig $userConfig,
        private MetadataFields $metadataFields,
    ) {
    }

    /** @return array<string,mixed> */
    public function get(string $userId): array
    {
        $stored = $this->userConfig->getValueArray($userId, AppConstants::APP_ID, self::KEY, []);

        return $this->sanitise($stored);
    }

    /**
     * @param array<string,mixed> $preferences
     * @return array<string,mixed> what was actually stored
     */
    public function set(string $userId, array $preferences): array
    {
        $clean = $this->sanitise($preferences);
        $this->userConfig->setValueArray($userId, AppConstants::APP_ID, self::KEY, $clean);

        return $clean;
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function sanitise(array $raw): array
    {
        $pageSize = (int)($raw['pageSize'] ?? 50);
        $sort = (string)($raw['sort'] ?? 'mtime');

        return [
            'columns'       => $this->sanitiseColumns($raw['columns'] ?? null),
            'grouping'      => $this->sanitiseGrouping($raw['grouping'] ?? []),
            // Favorites is its own list with its own shape; grouping it by folder
            // while the search results are grouped by type is a normal thing to
            // want, so the two keep separate levels.
            'favoritesGrouping' => $this->sanitiseGrouping($raw['favoritesGrouping'] ?? []),
            'pageSize'      => in_array($pageSize, self::PAGE_SIZES, true) ? $pageSize : 50,
            // A metadata key is a valid sort too, as long as it is still indexed.
            'sort'          => $this->sanitiseSort($sort),
            'descending'    => (bool)($raw['descending'] ?? true),
            'doubleClick'   => in_array($raw['doubleClick'] ?? '', self::CLICK_ACTIONS, true)
                ? (string)$raw['doubleClick']
                : 'open',
            'sidebarPinned' => (bool)($raw['sidebarPinned'] ?? false),
        ];
    }

    /**
     * The grid sorts on one column. A sort saved against a metadata field that
     * has since been removed — or was never filterable — falls back to the
     * modification time rather than being rejected.
     */
    private function sanitiseSort(string $sort): string
    {
        if (in_array($sort, self::SORTS, true)) {
            return $sort;
        }

        return MetadataFields::isMetadata($sort)
            && $this->metadataFields->isFilterable(MetadataFields::key($sort))
                ? $sort
                : 'mtime';
    }

    /**
     * Grouping is a list of levels, applied outermost first. Unknown or repeated
     * levels are dropped rather than rejected, so a preference saved while an app
     * was installed keeps working after it is removed — minus that level.
     *
     * @return list<string>
     */
    private function sanitiseGrouping(mixed $raw): array
    {
        $levels = [];
        foreach (is_array($raw) ? $raw : [] as $level) {
            $level = (string)$level;
            $known = in_array($level, self::GROUPINGS, true)
                || (MetadataFields::isMetadata($level) && $this->metadataFields->exists(MetadataFields::key($level)));

            if ($known && !in_array($level, $levels, true)) {
                $levels[] = $level;
            }
        }

        return array_slice($levels, 0, self::MAX_GROUPING_LEVELS);
    }

    /**
     * Returns the built-in columns exactly once in the user's order, plus any
     * metadata columns they added. Unknown ids are dropped and missing built-ins
     * appended in their default order, so a column added in a later version shows
     * up for existing users instead of silently never appearing.
     *
     * Metadata columns are only ever present because the user asked for them:
     * this server advertises around a hundred keys, and defaulting them on would
     * bury the grid.
     *
     * @return list<array{id: string, visible: bool, label: string}>
     */
    private function sanitiseColumns(mixed $raw): array
    {
        $byId = [];
        foreach (is_array($raw) ? $raw : [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = (string)($entry['id'] ?? '');
            $known = in_array($id, self::COLUMNS, true)
                || (MetadataFields::isMetadata($id) && $this->metadataFields->exists(MetadataFields::key($id)));
            if (!$known || isset($byId[$id])) {
                continue;
            }
            $byId[$id] = [
                'id'      => $id,
                'visible' => (bool)($entry['visible'] ?? true),
                // A renamed header is free text; cap it so it cannot break the layout.
                'label'   => mb_substr(trim((string)($entry['label'] ?? '')), 0, 40),
            ];
        }

        foreach (self::COLUMNS as $id) {
            $byId[$id] ??= [
                'id'      => $id,
                'visible' => in_array($id, self::DEFAULT_VISIBLE, true),
                'label'   => '',
            ];
        }

        // Name is what identifies a row; hiding it would leave an unreadable grid.
        // Rewritten whole rather than poking at ['visible']: the loop above put
        // every built-in column in $byId, but assigning into one key of a value
        // psalm cannot prove is set turns the entry's shape partial.
        $byId['name'] = [
            'id'      => 'name',
            'visible' => true,
            'label'   => $byId['name']['label'] ?? '',
        ];

        $ordered = [];
        foreach (is_array($raw) ? $raw : [] as $entry) {
            $id = is_array($entry) ? (string)($entry['id'] ?? '') : '';
            if (isset($byId[$id]) && !in_array($id, array_column($ordered, 'id'), true)) {
                $ordered[] = $byId[$id];
            }
        }
        foreach (self::COLUMNS as $id) {
            if (!in_array($id, array_column($ordered, 'id'), true)) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }
}
