<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Search;

use OCA\DcnFinder\Service\CoreScope;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IMetadataValueWrapper;

/**
 * The file metadata this server knows about.
 *
 * Read through Nextcloud's own metadata registry, never through DoCoNEXT Core:
 * Finder ships to the app store on its own and must not depend on Core being
 * installed. When Core *is* installed its `dcn_core_*` keys simply appear here,
 * along with anything any other app registered.
 *
 * One thing the registry cannot say is what a value *means*, and one kind of
 * value is unreadable without that: a principal is stored as a bare id. Which
 * keys hold one, and who each id is, is asked through {@see CoreScope} — the
 * single seam that knows Core may be there. Without Core the ids show as
 * stored, which is what the registry alone can offer.
 *
 * Only **indexed** keys can be filtered or sorted on — an unindexed value is
 * stored but has no row in the index table to compare against. Unindexed keys
 * are still shown, so a value is never invisible just because it is unqueryable.
 */
class MetadataFields
{
    /** Marks a field as metadata in the API, e.g. `meta:dcn_core_ecli`. */
    public const PREFIX = 'meta:';

    /** Names Core gave back, keyed by what was asked. @var array<string, string> */
    private array $principals = [];

    /** What has already been asked, name or no name. @var list<string> */
    private array $askedAbout = [];

    /** The principal-holding keys and what they hold, asked once per request. @var array<string, array{type: ?string, multi: bool}>|null */
    private ?array $principalFields = null;

    public function __construct(
        private IFilesMetadataManager $metadataManager,
        private CoreScope $core,
    ) {
    }

    /**
     * A key that holds people carries what it holds, so the interface can offer
     * a picker there instead of a box for typing an id nobody knows by heart.
     * Null on every other key, and on all of them without Core.
     *
     * A label comes from the app that wrote the key when it says one: Core
     * calls `dcn_core_dossiernummer` "Matter Number" in an English workspace.
     * Core answers per workspace, so `labels` carries every one and `label`
     * the one they agree on — the screen picks the workspace's own when a
     * search is narrowed to one. Where they disagree, or nobody says, the key
     * names itself.
     *
     * @return list<array{key: string, field: string, label: string, labels: array<int, string>, type: string, filterable: bool, principal: array{type: ?string, multi: bool}|null}>
     */
    public function all(): array
    {
        $known = $this->metadataManager->getKnownMetadata();
        $indexed = $known->getIndexes();
        $principals = $this->principalFields();
        $named = $this->core->fieldLabels();

        $fields = [];
        foreach ($known->getKeys() as $key) {
            $labels = $named[$key] ?? [];
            $fields[] = [
                'key'        => $key,
                'field'      => self::PREFIX . $key,
                'label'      => self::agreedLabel($labels) ?? self::label($key),
                'labels'     => $labels,
                'type'       => $known->getType($key),
                'filterable' => in_array($key, $indexed, true),
                'principal'  => $principals[$key] ?? null,
            ];
        }

        usort($fields, static fn (array $a, array $b) => strcmp($a['label'], $b['label']));

        return $fields;
    }

    /** True when the field name addresses metadata rather than a file column. */
    public static function isMetadata(string $field): bool
    {
        return str_starts_with($field, self::PREFIX);
    }

    /** The bare registry key behind a prefixed field name. */
    public static function key(string $field): string
    {
        return substr($field, strlen(self::PREFIX));
    }

    public function exists(string $key): bool
    {
        return in_array($key, $this->metadataManager->getKnownMetadata()->getKeys(), true);
    }

    public function isFilterable(string $key): bool
    {
        return in_array($key, $this->metadataManager->getKnownMetadata()->getIndexes(), true);
    }

    /**
     * Values arrive typed; the API hands back strings so one column can hold
     * whatever a key turns out to be. Lists are joined rather than dropped.
     *
     * @return array<string,string> key => displayable value, empty ones omitted
     */
    public function values(int $fileId, array $metadataPerFile): array
    {
        $metadata = $metadataPerFile[$fileId] ?? null;
        if ($metadata === null) {
            return [];
        }

        $values = [];
        foreach ($metadata->getKeys() as $key) {
            $value = match ($metadata->getType($key)) {
                IMetadataValueWrapper::TYPE_INT => (string)$metadata->getInt($key),
                IMetadataValueWrapper::TYPE_FLOAT => (string)$metadata->getFloat($key),
                IMetadataValueWrapper::TYPE_BOOL => $metadata->getBool($key) ? '1' : '0',
                IMetadataValueWrapper::TYPE_STRING_LIST => implode(', ', $metadata->getStringList($key)),
                IMetadataValueWrapper::TYPE_INT_LIST => implode(', ', array_map('strval', $metadata->getIntList($key))),
                IMetadataValueWrapper::TYPE_ARRAY => json_encode($metadata->getArray($key)) ?: '',
                default => $metadata->getString($key),
            };

            if ($value !== '') {
                $values[$key] = $this->readable($key, $value);
            }
        }

        return $values;
    }

    /**
     * Turns the people a value names into the names they go by: `alice` reads
     * as "Alice Jansen".
     *
     * Which keys hold people is asked, not detected. A stored principal is a
     * bare id — the search index column is too narrow for the object it comes
     * from — and nothing about `alice` says it is an account rather than a
     * word, so there is no shape here to recognise. A comma-separated list is
     * resolved item by item, which is how a multi-valued field arrives, and an
     * id nobody answers to is shown as stored.
     */
    private function readable(string $key, string $value): string
    {
        $field = $this->principalFields()[$key] ?? null;
        if ($field === null) {
            return $value;
        }

        $ids = array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $id) => $id !== ''));

        // Where the field holds one kind of principal, say which: `admin` is an
        // account on most servers and a group on many, and only the field knows
        // which of the two its value meant.
        $kind  = $field['type'];
        $asked = array_map(static fn (string $id): string => $kind === null ? $id : $kind . ':' . $id, $ids);
        $names = $this->principalNames($asked);

        return implode(', ', array_map(
            static fn (string $ask, string $id): string => $names[$ask] ?? $id,
            $asked,
            $ids,
        ));
    }

    /**
     * What a key holds where it holds people, or null where it does not — the
     * answer every caller here starts from, asked of Core once per request.
     *
     * @return array{type: ?string, multi: bool}|null
     */
    public function principalField(string $key): ?array
    {
        return $this->principalFields()[$key] ?? null;
    }

    /** @return array<string, array{type: ?string, multi: bool}> */
    private function principalFields(): array
    {
        return $this->principalFields ??= $this->core->principalFields();
    }

    /**
     * Names for what a value asks about, going to Core only for the entries
     * this request has not seen. One Core cannot place is remembered as asked,
     * so a page of results does not ask about the same missing account per row.
     *
     * @param list<string> $asked ids, or `type:id`
     * @return array<string, string>
     */
    private function principalNames(array $asked): array
    {
        $missing = array_values(array_diff($asked, $this->askedAbout));
        if ($missing !== []) {
            // Remembered as asked rather than as answered, so an id nobody
            // answers to is not asked about again on the next row.
            $this->askedAbout = array_merge($this->askedAbout, $missing);
            $this->principals += $this->core->principalNames($missing);
        }

        return $this->principals;
    }

    /**
     * The label every workspace gives a key, or null when they differ or none
     * gives one.
     *
     * @param array<int, string> $labels realm id => label
     */
    public static function agreedLabel(array $labels): ?string
    {
        $distinct = array_unique(array_values($labels));

        return count($distinct) === 1 ? $distinct[0] : null;
    }

    /**
     * A readable name for a registry key: `dcn_core_behandelend_advocaat` reads
     * as "Behandelend advocaat". The app that owns the key knows a better label,
     * but asking it would mean depending on that app.
     */
    private static function label(string $key): string
    {
        $bare = preg_replace('/^dcn_core_/', '', $key) ?? $key;

        return ucfirst(str_replace('_', ' ', $bare));
    }
}
