<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Search;

use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IMetadataValueWrapper;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * The file metadata this server knows about.
 *
 * Read through Nextcloud's own metadata registry, never through DoCoNEXT Core:
 * Finder ships to the app store on its own and must not depend on Core being
 * installed. When Core *is* installed its `dcn_core_*` keys simply appear here,
 * along with anything any other app registered.
 *
 * Only **indexed** keys can be filtered or sorted on — an unindexed value is
 * stored but has no row in the index table to compare against. Unindexed keys
 * are still shown, so a value is never invisible just because it is unqueryable.
 */
class MetadataFields
{
    /** Marks a field as metadata in the API, e.g. `meta:dcn_core_ecli`. */
    public const PREFIX = 'meta:';

    /** Resolved principal ids, so a page of results asks about each one once. */
    private array $principals = [];

    public function __construct(
        private IFilesMetadataManager $metadataManager,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
    ) {
    }

    /**
     * @return list<array{key: string, field: string, label: string, type: string, filterable: bool}>
     */
    public function all(): array
    {
        $known = $this->metadataManager->getKnownMetadata();
        $indexed = $known->getIndexes();

        $fields = [];
        foreach ($known->getKeys() as $key) {
            $fields[] = [
                'key'        => $key,
                'field'      => self::PREFIX . $key,
                'label'      => self::label($key),
                'type'       => $known->getType($key),
                'filterable' => in_array($key, $indexed, true),
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
                $values[$key] = $this->readable($value);
            }
        }

        return $values;
    }

    /**
     * Turns an account or group reference into the name that account or group
     * actually goes by: `group:legal-staff` reads as "Legal staff".
     *
     * A shape, not an app: any app storing a `user:` or `group:` reference gets
     * the same treatment, and anything else — or a reference to something that
     * no longer exists — is left exactly as it was stored. A comma-separated
     * list is resolved item by item, which is how a multi-valued field arrives.
     *
     * DoCoNEXT Core reduces its principal fields to bare ids before storing
     * them, because the search index column is too narrow for the JSON they
     * arrive as; that is what makes them show up here as `group:legal-staff`
     * rather than as a person.
     */
    private function readable(string $value): string
    {
        if (!str_contains($value, 'user:') && !str_contains($value, 'group:')) {
            return $value;
        }

        $parts = array_map(
            fn (string $part) => $this->principalName(trim($part)),
            explode(',', $value),
        );

        return implode(', ', $parts);
    }

    private function principalName(string $reference): string
    {
        if (array_key_exists($reference, $this->principals)) {
            return $this->principals[$reference];
        }

        [$type, $id] = array_pad(explode(':', $reference, 2), 2, '');

        $name = match ($type) {
            'user'  => $this->userManager->get($id)?->getDisplayName(),
            'group' => $this->groupManager->get($id)?->getDisplayName(),
            default => null,
        };

        // An id nobody answers to is still the truest thing we can show.
        return $this->principals[$reference] = $name ?? $reference;
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
