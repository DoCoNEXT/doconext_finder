<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Service;

use OCA\DcnFinder\AppInfo\AppConstants;
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

    /** Columns the grid can show, in their default order. */
    public const COLUMNS = ['name', 'folder', 'size', 'modified', 'created', 'type'];

    /** Shown unless the user says otherwise; `created` and `type` start hidden. */
    private const DEFAULT_VISIBLE = ['name', 'folder', 'size', 'modified'];

    /** Fields the result list can be grouped by; '' means no grouping. */
    public const GROUPINGS = ['', 'folder', 'type', 'modified'];

    public function __construct(private IUserConfig $userConfig)
    {
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
        return [
            'columns'  => $this->sanitiseColumns($raw['columns'] ?? null),
            'grouping' => in_array($raw['grouping'] ?? '', self::GROUPINGS, true) ? (string)$raw['grouping'] : '',
        ];
    }

    /**
     * Returns every known column exactly once, in the user's order, with unknown
     * ids dropped and missing ones appended in their default order — so a column
     * added in a later version shows up for existing users instead of silently
     * never appearing.
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
            if (!in_array($id, self::COLUMNS, true) || isset($byId[$id])) {
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
        $byId['name']['visible'] = true;

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
