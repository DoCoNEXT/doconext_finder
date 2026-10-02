import type { MetadataField, ScopeSelection } from '../types/Search'

/** How the API addresses a metadata field, e.g. `meta:dcn_core_rechtsgebied`. */
export const METADATA_PREFIX = 'meta:'

export function isMetadataField(field: string): boolean {
  return field.startsWith(METADATA_PREFIX)
}

export function metadataKeyOf(field: string): string {
  return field.slice(METADATA_PREFIX.length)
}

/**
 * The workspace a scope lies in: the scope itself when it is one, otherwise
 * the one the picker recorded for it. Null for a folder, or no scope at all.
 * @param scope the search's scope
 */
export function realmOfScope(scope: ScopeSelection | null | undefined): number | null {
  if (!scope) {
    return null
  }
  if (scope.level === 'realm') {
    return scope.id
  }
  return scope.realmId ?? null
}

/**
 * The fields, each called what the given workspace calls it. A field that
 * workspace does not have, or no workspace at all, keeps its general label.
 * @param fields the server's metadata registry
 * @param realmId the workspace the search is narrowed to, if any
 */
export function labelledFor(fields: MetadataField[], realmId: number | null): MetadataField[] {
  if (realmId === null) {
    return fields
  }
  return fields.map((field) => {
    const label = field.labels?.[realmId]
    return label ? { ...field, label } : field
  })
}
