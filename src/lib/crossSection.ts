import type { TagEntry } from '../stores/userNodes'
import type { AspectType } from '../stores/editingCatalog'

// Direction tokens double as the canonical row order: each side axis keeps
// `center` between its opposite sides (west … center … east,
// south … center … north), so a cross-section reads from one side across
// the way to the other.
export const DIRECTIONS = ['west', 'south', 'center', 'north', 'east'] as const
export type AspectDirection = (typeof DIRECTIONS)[number]

export interface AspectRow {
  type: string
  direction: AspectDirection
  index: string
  values: Record<string, string>
  approvedKeys: string[]
}

export function parseAspectKey(key: string): { type: string; direction: AspectDirection; index: string; aspect: string } | null {
  const match = /^way\.([^.\s]+)\.(north|south|east|west|center)\.([1-9][0-9]*)\.([^.\s]+)$/u.exec(key)
  return match && key.length <= 255
    ? { type: match[1]!, direction: match[2] as AspectDirection, index: match[3]!, aspect: match[4]! }
    : null
}

function compareIndex(a: string, b: string): number {
  return BigInt(a) < BigInt(b) ? -1 : BigInt(a) > BigInt(b) ? 1 : 0
}

// Rows are grouped per direction: the canonical direction order first,
// then the numeric index within each direction.
export function compareRows(a: Pick<AspectRow, 'direction' | 'index'>, b: Pick<AspectRow, 'direction' | 'index'>): number {
  return DIRECTIONS.indexOf(a.direction) - DIRECTIONS.indexOf(b.direction) || compareIndex(a.index, b.index)
}

export function crossSectionRows(tags: TagEntry[], observations: TagEntry[]): AspectRow[] {
  const pending = new Set(observations.map(tag => JSON.stringify([tag.key, tag.value])))
  const rows = new Map<string, AspectRow>()
  for (const tag of tags) {
    const parsed = parseAspectKey(tag.key)
    if (!parsed) continue
    const id = `${parsed.type}.${parsed.direction}.${parsed.index}`
    let row = rows.get(id)
    if (!row) {
      row = { type: parsed.type, direction: parsed.direction, index: parsed.index, values: {}, approvedKeys: [] }
      rows.set(id, row)
    }
    row.values[parsed.aspect] = tag.value
    if (!pending.has(JSON.stringify([tag.key, tag.value]))) row.approvedKeys.push(parsed.aspect)
  }
  return [...rows.values()].sort(compareRows)
}

export function crossSectionTags(rows: AspectRow[]): TagEntry[] {
  return rows.flatMap(row => Object.entries(row.values)
    .filter(([, value]) => typeof value === 'string' && value.length > 0)
    .map(([aspect, value]) => ({ key: `way.${row.type}.${row.direction}.${row.index}.${aspect}`, value })))
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
