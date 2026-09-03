<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Service;

use OCA\DcnFinder\AppInfo\AppConstants;
use OCA\DcnFinder\Search\FileQuery;
use OCP\IAppConfig;

/**
 * Which categories the search page's "Type" filter offers, and in what order.
 *
 * Two kinds of entry:
 * - builtin: references one of {@see AppConstants::BUILTIN_FILE_TYPE_IDS} by id
 *   only. Its label and mimetype list are translated client-side and never
 *   stored here — an admin can drop it from the list or move it, but cannot
 *   rename it without breaking translation.
 * - custom: admin-authored label and mimetype list, stored verbatim and shown
 *   as-is in every language.
 *
 * @psalm-type BuiltinEntry = array{type: 'builtin', id: string}
 * @psalm-type CustomEntry = array{type: 'custom', id: string, label: string, mimetypes: list<string>}
 */
class FileTypeFilterSettings
{
    public function __construct(private IAppConfig $appConfig)
    {
    }

    /**
     * @return list<BuiltinEntry|CustomEntry>
     */
    public function list(): array
    {
        $raw = $this->appConfig->getValueString(AppConstants::APP_ID, AppConstants::FILE_TYPE_FILTERS_KEY, '');
        if ($raw === '') {
            return self::defaults();
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : self::defaults();
    }

    /**
     * Unknown builtin ids, unusable custom entries and duplicate ids are dropped
     * rather than rejected outright — the admin should see the list they will
     * actually get, the same way bridge extensions normalises instead of failing.
     *
     * @param array<int,mixed> $entries what the client sent, in the order shown
     * @return list<BuiltinEntry|CustomEntry> what was actually stored
     */
    public function save(array $entries): array
    {
        $normalized = [];
        $seenIds = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $normalizedEntry = ($entry['type'] ?? null) === 'custom'
                ? self::normalizeCustom($entry)
                : self::normalizeBuiltin($entry);

            if ($normalizedEntry === null || isset($seenIds[$normalizedEntry['id']])) {
                continue;
            }

            $seenIds[$normalizedEntry['id']] = true;
            $normalized[] = $normalizedEntry;
        }

        $this->appConfig->setValueString(
            AppConstants::APP_ID,
            AppConstants::FILE_TYPE_FILTERS_KEY,
            json_encode($normalized, JSON_UNESCAPED_SLASHES) ?: '[]',
        );

        return $normalized;
    }

    /** @return list<BuiltinEntry> */
    private static function defaults(): array
    {
        return array_map(
            static fn (string $id): array => ['type' => 'builtin', 'id' => $id],
            AppConstants::BUILTIN_FILE_TYPE_IDS,
        );
    }

    /** @return BuiltinEntry|null */
    private static function normalizeBuiltin(array $entry): ?array
    {
        $id = (string)($entry['id'] ?? '');

        return in_array($id, AppConstants::BUILTIN_FILE_TYPE_IDS, true)
            ? ['type' => 'builtin', 'id' => $id]
            : null;
    }

    /** @return CustomEntry|null */
    private static function normalizeCustom(array $entry): ?array
    {
        $label = trim((string)($entry['label'] ?? ''));
        if ($label === '' || mb_strlen($label) > 80) {
            return null;
        }

        $mimetypes = array_values(array_filter(array_map(
            static fn ($m) => FileQuery::validMimetype((string)$m),
            is_array($entry['mimetypes'] ?? null) ? $entry['mimetypes'] : [],
        )));
        if ($mimetypes === []) {
            return null;
        }

        return [
            'type'      => 'custom',
            // Admin-authored, so the id follows the label rather than being
            // chosen by hand — two categories saved with the same label
            // collapse into one instead of silently colliding later, the way a
            // hand-picked id could.
            'id'        => self::slug($label),
            'label'     => $label,
            'mimetypes' => $mimetypes,
        ];
    }

    private static function slug(string $label): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $label) ?? '', '-'));

        return 'custom-' . ($slug !== '' ? $slug : substr(md5($label), 0, 8));
    }
}
