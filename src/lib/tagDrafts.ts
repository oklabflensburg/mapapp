import type { NodeGroup } from '../stores/nodeGroups'
import type { UserNode } from '../stores/userNodes'
import { crossSectionRows, parseAspectKey, type AspectRow } from './crossSection'

// A tag row in an editor draft: plain approved tags carry only key and
// value; observation rows can also hold per-field translation drafts and
// the raw identifiers that were generated from them. relationId ties a row
// to the observation relation it edits; rows without one create a new
// relation on save (see specs/map-editing/observation-relations/spec.md).
// persistedOnNode marks node metadata rows (e.g. names) that still save on
// the node itself.
export interface TagRow {
  key: string
  value: string
  relationId?: number
  persistedOnNode?: boolean
  generatedRaw?: Partial<Record<'key' | 'value', string>>
  translations?: Record<string, { text: string; previous: string }>
}

// A translation proposal for one observation pair, as sent with the
// observation's relation save.
export interface TagTranslationProposal {
  key: string
  value: string
  keyTranslation?: string
  valueTranslation?: string
  previousKeyTranslation?: string
  previousValueTranslation?: string
}

// Keep language drafts separate and use raw identifiers for catalog lookups.
export function translationId(locale: string, row: Pick<TagRow, 'key' | 'value'>, kind: 'key' | 'value'): string {
  return JSON.stringify([locale, kind, row.key.trim(), ...(kind === 'value' ? [row.value] : [])])
}

// Splits the node's approved (read-only) tags from the current user's
// pending node rows (names are node metadata, not observation relations);
// pending observation relations load separately.
export function splitNodeDraft(node: UserNode): { rows: TagRow[]; observationRows: TagRow[] } {
  const rows: TagRow[] = []
  const observationRows: TagRow[] = []
  const observationKeys = new Set(node.observations.map((observation) => `${observation.key}\0${observation.value}`))
  for (const { key, value } of node.tags) {
    const pending = observationKeys.has(`${key}\0${value}`)
    const row: TagRow = { key, value, ...(pending ? { persistedOnNode: true } : {}) }
    const proposal = node.observationTranslations?.find(
      (translation) => translation.key === key && translation.value === value,
    )
    if (pending) {
      row.translations = {}
    }
    for (const [locale, translations] of Object.entries(proposal?.translations ?? {})) {
      for (const kind of ['key', 'value'] as const) {
        const text = translations[`${kind}Translation`]
        if (text !== undefined) {
          const id = JSON.stringify([locale, kind, key, ...(kind === 'value' ? [row.value] : [])])
          row.translations![id] = { text, previous: text }
        }
      }
    }
    (pending ? observationRows : rows).push(row)
  }
  return { rows, observationRows }
}

// Builds the editable draft for a selected relation's range.
// The panel edits the range, not one relation: every observation relation
// sharing the selected relation's member sequence loads side by side, so a
// user sees and edits all observations of the range (spec
// specs/map-editing/observation-relations/spec.md R4). Approved content stays
// a read-only row; pending observations keep their relation id so each row
// saves (or deletes) its own relation.
export function splitRelationRangeDraft(groups: NodeGroup[]): { rows: TagRow[]; observationRows: TagRow[]; aspectRows: AspectRow[] } {
  const rows: TagRow[] = []
  const observationRows: TagRow[] = []
  const aspectRows: AspectRow[] = []
  for (const group of groups) {
    const observationKeys = new Set(group.observations.map((observation) => `${observation.key}\0${observation.value}`))
    for (const { key, value } of group.tags) {
      if (!observationKeys.has(`${key}\0${value}`)) {
        rows.push({ key, value })
      } else if (parseAspectKey(key) === null) {
        observationRows.push({ key, value, relationId: group.id })
      }
    }
    aspectRows.push(...crossSectionRows(group.tags, group.observations, group.id))
  }
  return { rows, observationRows, aspectRows }
}

// The pending single-tag observation relations anchored on the selected
// node: one observation row (or composer aspect row) per relation.
export function splitNodeObservationRelations(relations: NodeGroup[]): { observationRows: TagRow[]; aspectRows: AspectRow[] } {
  const observationRows: TagRow[] = []
  const aspectRows: AspectRow[] = []
  for (const relation of relations) {
    const pending = relation.observations
    if (pending.length === 0) {
      continue
    }
    const { key, value } = pending[0]
    if (parseAspectKey(key) === null) {
      // Proposed translations travel with the row like a node draft's.
      const row: TagRow = { key, value, relationId: relation.id, translations: {} }
      observationRows.push(row)
    }
  }
  for (const relation of relations) {
    if (relation.observations.length === 0) {
      continue
    }
    const structured = crossSectionRows(relation.tags, relation.observations, relation.id)
    if (structured.length > 0 && parseAspectKey(relation.observations[0].key) !== null) {
      aspectRows.push(...structured)
    }
  }
  return { observationRows, aspectRows }
}

// Turns the observation translation drafts into save proposals, matched by
// the draft ids so drafts keep tracking the raw identifiers they belong to.
export function buildTranslationProposals(observationRow: TagRow, locale: string): TagTranslationProposal[] {
  const key = observationRow.key.trim()
  if (!key) return []
  const keyDraft = observationRow.translations?.[translationId(locale, observationRow, 'key')]
  const valueDraft = observationRow.translations?.[translationId(locale, observationRow, 'value')]
  return keyDraft || valueDraft ? [{
    key,
    value: observationRow.value,
    keyTranslation: keyDraft?.text,
    valueTranslation: valueDraft?.text,
    previousKeyTranslation: keyDraft?.previous,
    previousValueTranslation: valueDraft?.previous,
  }] : []
}
