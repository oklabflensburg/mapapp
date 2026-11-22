import type { NodeGroup } from '../stores/nodeGroups'
import type { TagEntry, UserNode } from '../stores/userNodes'
import { crossSectionRows, parseAspectKey, type AspectRow } from './crossSection'

// A tag row in an editor draft: plain approved tags carry only key and
// value; observation rows can also hold per-field translation drafts and
// the raw identifiers that were generated from them.
export interface TagRow {
  key: string
  value: string
  generatedRaw?: Partial<Record<'key' | 'value', string>>
  translations?: Record<string, { text: string; previous: string }>
}

// A translation proposal for one observation pair, as sent on save.
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

// Splits a saved node into read-only approved rows and the current user's
// own pending observation rows. The observations pairs hold exactly the
// current user's unapproved tags (the backend hides other users' pending
// tags), so membership of the pair — not a key comparison against the
// merged tags list, which can carry several values of one key — decides
// the section. The observation value comes from the observations pair
// because the merged tags list may carry an approved value for the same
// key.
export function splitNodeDraft(node: UserNode): { rows: TagRow[]; observationRows: TagRow[] } {
  const rows: TagRow[] = []
  const observationRows: TagRow[] = []
  const observationKeys = new Set(node.observations.map((observation) => `${observation.key}\0${observation.value}`))
  for (const { key, value } of node.tags) {
    if (observationKeys.has(`${key}\0${value}`)) {
      const row: TagRow = { key, value, translations: {} }
      const proposal = node.observationTranslations?.find(
        (translation) => translation.key === key && translation.value === value,
      )
      for (const [locale, translations] of Object.entries(proposal?.translations ?? {})) {
        for (const kind of ['key', 'value'] as const) {
          const text = translations[`${kind}Translation`]
          if (text !== undefined) {
            const id = JSON.stringify([locale, kind, key, ...(kind === 'value' ? [row.value] : [])])
            row.translations![id] = { text, previous: text }
          }
        }
      }
      observationRows.push(row)
    } else {
      rows.push({ key, value })
    }
  }
  return { rows, observationRows }
}

// Mirrors the node split: approved tags are read-only for editors, and the
// observations pairs decide which entries are the current user's own pending
// ones, matched by key and value so several values of a key can coexist.
// Approved structured cross-section keys show as tags too; pending ones are
// edited only through the composer, which owns the structured draft.
export function splitRelationDraft(group: NodeGroup): { rows: TagRow[]; observationRows: TagRow[]; aspectRows: AspectRow[] } {
  const rows: TagRow[] = []
  const observationRows: TagRow[] = []
  const observationKeys = new Set(group.observations.map((observation) => `${observation.key}\0${observation.value}`))
  for (const { key, value } of group.tags) {
    const observation = observationKeys.has(`${key}\0${value}`)
    if (parseAspectKey(key)) {
      if (!observation) {
        rows.push({ key, value })
      }
      continue
    }
    if (observation) {
      observationRows.push({ key, value })
    } else {
      rows.push({ key, value })
    }
  }
  return { rows, observationRows, aspectRows: crossSectionRows(group.tags, group.observations) }
}

// Approved aspects are read-only tag rows; the composer draft owns the
// structured way.* keys on save, so the rows never resubmit them.
export function collectSaveTags(rows: TagRow[], observationRows: TagRow[], skipApprovedAspects: boolean): TagEntry[] {
  const tags: TagEntry[] = []
  for (const row of rows) {
    const key = row.key.trim()
    if (key !== '' && !(skipApprovedAspects && parseAspectKey(key))) {
      tags.push({ key, value: row.value })
    }
  }
  for (const row of observationRows) {
    const key = row.key.trim()
    if (key !== '') {
      tags.push({ key, value: row.value })
    }
  }
  return tags
}

// Turns the observation translation drafts into save proposals, matched by
// the draft ids so drafts keep tracking the raw identifiers they belong to.
export function buildTranslationProposals(observationRows: TagRow[], locale: string): TagTranslationProposal[] {
  return observationRows.flatMap((row) => {
    const key = row.key.trim()
    if (!key) return []
    const keyDraft = row.translations?.[translationId(locale, row, 'key')]
    const valueDraft = row.translations?.[translationId(locale, row, 'value')]
    return keyDraft || valueDraft ? [{
      key,
      value: row.value,
      keyTranslation: keyDraft?.text,
      valueTranslation: valueDraft?.text,
      previousKeyTranslation: keyDraft?.previous,
      previousValueTranslation: valueDraft?.previous,
    }] : []
  })
}
