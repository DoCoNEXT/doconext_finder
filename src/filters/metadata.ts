/** How the API addresses a metadata field, e.g. `meta:dcn_core_rechtsgebied`. */
export const METADATA_PREFIX = 'meta:'

export function isMetadataField(field: string): boolean {
  return field.startsWith(METADATA_PREFIX)
}

export function metadataKeyOf(field: string): string {
  return field.slice(METADATA_PREFIX.length)
}
