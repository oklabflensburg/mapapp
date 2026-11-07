import type { TagEntry } from '../stores/userNodes'
import type { AspectType } from '../stores/editingCatalog'

export interface AspectRow {
  type: string
  index: string
  values: Record<string, string>
  approvedKeys: string[]
}

export function parseAspectKey(key: string): { type: string; index: string; aspect: string } | null {
  const match = /^way\.([^.\s]+)\.([1-9][0-9]*)\.([^.\s]+)$/u.exec(key)
  return match && key.length <= 255 ? { type: match[1]!, index: match[2]!, aspect: match[3]! } : null
}

export function crossSectionRows(tags: TagEntry[], observations: TagEntry[]): AspectRow[] {
  const pending = new Set(observations.map(tag => JSON.stringify([tag.key, tag.value])))
  const rows = new Map<string, AspectRow>()
  for (const tag of tags) {
    const parsed = parseAspectKey(tag.key)
    if (!parsed) continue
    const id = `${parsed.type}.${parsed.index}`
    let row = rows.get(id)
    if (!row) {
      row = { type: parsed.type, index: parsed.index, values: {}, approvedKeys: [] }
      rows.set(id, row)
    }
    row.values[parsed.aspect] = tag.value
    if (!pending.has(JSON.stringify([tag.key, tag.value]))) row.approvedKeys.push(parsed.aspect)
  }
  return [...rows.values()].sort((a, b) => BigInt(a.index) < BigInt(b.index) ? -1 : BigInt(a.index) > BigInt(b.index) ? 1 : a.type.localeCompare(b.type))
}

export function crossSectionTags(rows: AspectRow[]): TagEntry[] {
  return rows.flatMap(row => Object.entries(row.values)
    .filter(([, value]) => typeof value === 'string' && value.length > 0)
    .map(([aspect, value]) => ({ key: `way.${row.type}.${row.index}.${aspect}`, value })))
}

// Whether the composer still shows content for the row: an editable (not
// yet approved) catalog attribute or a pending value outside the catalog.
// Fully approved rows appear as tag info in the panel instead.
export function isAspectRowEditable(row: AspectRow, types: AspectType[]): boolean {
  const attributes = types.find(type => type.name === row.type)?.attributes ?? []
  return attributes.some(attribute => !row.approvedKeys.includes(attribute.key))
    || Object.keys(row.values).some(key => !row.approvedKeys.includes(key)
      && !attributes.some(attribute => attribute.key === key))
}
