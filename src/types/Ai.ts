/** The shapes DoCoNEXT Core's file distiller returns. */

/**
 * One metadata condition the distiller understood, already checked against
 * Core's own schema: a field that does not exist, or a value outside its option
 * list, was dropped before it left the server.
 */
export interface DistilledMetadata {
  /** Bare registry key, e.g. rechtsgebied — Core writes it as dcn_core_<key>. */
  fieldKey: string
  fieldLabel: string
  fieldType: string
  value: string
  valueLabel: string
}

/**
 * A question turned into filters. Every field is optional because a question
 * rarely mentions all of them — "pdf's from 2025" carries no metadata, and
 * "everything about the Jansen case" carries nothing but a topic.
 */
export interface DistilledFileScope {
  /** WebDAV-safe patterns: "application/pdf" or "image/%". */
  mimetypes: string[]
  /** Human label for the file type, for the chip. */
  mimeLabel: string | null
  /** 'created' | 'modified' | null — one of the file's own dates. */
  dateField: string | null
  /**
   * A searchable date metadata field, when the range is about a business date
   * (a judgment date, say) rather than the file's own. Mutually exclusive with
   * dateField.
   */
  dateFieldKey: string | null
  /** YYYY-MM-DD, in Core's wording — not unix seconds. */
  dateFrom: string | null
  dateTo: string | null
  dateLabel: string | null
  metadata: DistilledMetadata[]
  /**
   * What was left over once the filters were taken out: the subject itself.
   * Never a filter on its own — see the store for where it lands.
   */
  topic: string | null
  /** Ready-made labels, in the order Core would show them. */
  chips: string[]
}

/** What Core answers while a distillation is running, and when it is done. */
export type DistilStatus = 'running' | 'ready' | 'failed' | 'empty_scope'

export interface DistilPoll {
  status: DistilStatus
  understood?: DistilledFileScope
}

/** Whether Core's AI is configured, reachable, and has a RAG backend. */
export interface AiStatus {
  enabled: boolean
  available: boolean
  contextChat: boolean
}
