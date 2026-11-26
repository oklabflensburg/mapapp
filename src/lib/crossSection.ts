import type { TagEntry } from '../stores/userNodes'
import type { AspectType } from '../stores/editingCatalog'

// Side tokens double as the canonical row order: a cross-section reads
// from one side of the way to the other (left … center … right, relative
// to the relation's direction of travel, like OSM's :left/:right).
export const SIDES = ['left', 'center', 'right'] as const
export type AspectSide = (typeof SIDES)[number]

// One aspect observation of one element side. Under the observation-relations
// model each aspect value is its own observation (its own relation); a row
// groups the values drafted for the same element (type and side), while
// relationId ties an existing observation relation to its row for edits.
export interface AspectRow {
  type: string
  side: AspectSide
  values: Record<string, string>
  approvedKeys: string[]
  // The relation whose single observation this row edits, when the row was
  // loaded from an existing observation relation.
  relationId?: number
  // The aspect the relation's observation carries; edits replace its value.
  originAspect?: string
}

export function parseAspectKey(key: string): { type: string; side: AspectSide; aspect: string } | null {
  const match = /^([^.\s]+)\.(left|right|center)\.([^.\s]+)$/u.exec(key)
  return match && key.length <= 255
    ? { type: match[1]!, side: match[2] as AspectSide, aspect: match[3]! }
    : null
}

// Rows are grouped per side: the canonical side order first, then the type
// name keeps a stable order within a side.
export function compareRows(a: Pick<AspectRow, 'side' | 'type'>, b: Pick<AspectRow, 'side' | 'type'>): number {
  return SIDES.indexOf(a.side) - SIDES.indexOf(b.side) || a.type.localeCompare(b.type)
}

// Builds a row per relation from its approved or own pending structured tag:
// one observation relation carries exactly one aspect key.
export function crossSectionRows(tags: TagEntry[], observations: TagEntry[], relationId?: number): AspectRow[] {
  const pending = new Set(observations.map(tag => JSON.stringify([tag.key, tag.value])))
  const rows = new Map<string, AspectRow>()
  for (const tag of tags) {
    const parsed = parseAspectKey(tag.key)
    if (!parsed) continue
    const id = `${parsed.type}.${parsed.side}`
    let row = rows.get(id)
    if (!row) {
      row = { type: parsed.type, side: parsed.side, values: {}, approvedKeys: [], ...(relationId !== undefined ? { relationId, originAspect: parsed.aspect } : {}) }
      rows.set(id, row)
    }
    row.values[parsed.aspect] = tag.value
    if (!pending.has(JSON.stringify([tag.key, tag.value]))) row.approvedKeys.push(parsed.aspect)
  }
  return [...rows.values()].sort(compareRows)
}

// Turns the drafted rows into one aspect tag per value: every entry becomes
// its own observation relation on save (see specs/map-editing/observation-
// relations/spec.md R4).
export function crossSectionTags(rows: AspectRow[]): TagEntry[] {
  return rows.flatMap(row => Object.entries(row.values)
    .filter(([, value]) => typeof value === 'string' && value.length > 0)
    .map(([aspect, value]) => ({ key: `${row.type}.${row.side}.${aspect}`, value })))
}

// Whether the composer still shows content for the row: an editable (not
// yet approved) catalog attribute that is not the relation's own observation,
// or a pending value outside the catalog.
// Fully approved rows appear as tag info in the panel instead.
export function isAspectRowEditable(row: AspectRow, types: AspectType[]): boolean {
  if (row.relationId !== undefined) {
    // Editing an existing observation relation: editable while pending.
    return !row.approvedKeys.includes(row.originAspect ?? '')
  }
  const attributes = types.find(type => type.name === row.type)?.attributes ?? []
  return attributes.some(attribute => !row.approvedKeys.includes(attribute.key))
    || Object.keys(row.values).some(key => !row.approvedKeys.includes(key)
      && !attributes.some(attribute => attribute.key === key))
}
