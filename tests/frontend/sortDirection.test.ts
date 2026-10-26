import { describe, expect, it } from 'vitest'
import { sortStartsDescending } from '../../src/filters/columns'
import type { MetadataField } from '../../src/types/Search'

function field(key: string, type: string): MetadataField {
  return { key, field: `meta:${key}`, label: key, labels: {}, type, filterable: true } as MetadataField
}

describe('sortStartsDescending', () => {
  it('starts words A→Z', () => {
    expect(sortStartsDescending('name')).toBe(false)
  })

  it('starts dates and sizes newest or largest first', () => {
    expect(sortStartsDescending('mtime')).toBe(true)
    expect(sortStartsDescending('creation_time')).toBe(true)
    expect(sortStartsDescending('size')).toBe(true)
  })

  it('reads a metadata field by its type', () => {
    const metadata = [field('amount', 'int'), field('rate', 'float'), field('client', 'string')]
    expect(sortStartsDescending('meta:amount', metadata)).toBe(true)
    expect(sortStartsDescending('meta:rate', metadata)).toBe(true)
    expect(sortStartsDescending('meta:client', metadata)).toBe(false)
  })

  it('starts a metadata field it does not know A→Z', () => {
    expect(sortStartsDescending('meta:unknown')).toBe(false)
  })
})
