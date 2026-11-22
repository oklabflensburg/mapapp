import { useSettingsStore } from '../stores/settings'
import { useTagKeyLabelsStore } from '../stores/tagKeyLabels'
import { translationId as translationIdFor, type TagRow } from '../lib/tagDrafts'

// Owns the per-row translation drafts of the observation editor: the
// translated text the user types, the raw identifier beneath it, and the
// slug generated from one into the other. A fixed vocabulary (controlled
// way observations) never gets a generated identifier.
// fixedVocabulary decides per row and field whether the identifiers are
// controlled, so no draft is generated for them.
export function useTagTranslations(fixedVocabulary: (row: TagRow, kind: 'key' | 'value') => boolean) {
  const settings = useSettingsStore()
  const tagKeyLabels = useTagKeyLabelsStore()

  // Keep language drafts separate and use raw identifiers for catalog lookups.
  function translationId(row: TagRow, kind: 'key' | 'value'): string {
    return translationIdFor(settings.locale, row, kind)
  }

  function catalogTranslation(row: TagRow, kind: 'key' | 'value'): string {
    return kind === 'key'
      ? tagKeyLabels.labels[row.key.trim()] ?? ''
      : tagKeyLabels.values[JSON.stringify([row.key.trim(), row.value])] ?? ''
  }

  function translationText(row: TagRow, kind: 'key' | 'value'): string {
    return row.translations?.[translationId(row, kind)]?.text ?? catalogTranslation(row, kind)
  }

  function editTranslation(row: TagRow, kind: 'key' | 'value', text: string | undefined): void {
    const translation = text ?? ''
    if (!fixedVocabulary(row, kind) && (!row[kind].trim() || row.generatedRaw?.[kind] === row[kind])) {
      const generated = translation.normalize('NFKD').replace(/\p{M}/gu, '')
        .toLowerCase().replace(/ß/g, 'ss').replace(/[^\p{L}\p{N}]+/gu, '_').replace(/^_+|_+$/g, '')
      editRawTag(row, kind, generated, true)
      row.generatedRaw ??= {}
      row.generatedRaw[kind] = generated
    }
    const id = translationId(row, kind)
    row.translations ??= {}
    row.translations[id] = { text: translation, previous: row.translations[id]?.previous ?? catalogTranslation(row, kind) }
  }

  function editRawTag(row: TagRow, kind: 'key' | 'value', text: string | null | undefined, generated = false): void {
    if (!generated && row.generatedRaw) delete row.generatedRaw[kind]
    const keyDraft = row.translations?.[translationId(row, 'key')]
    const valueDraft = row.translations?.[translationId(row, 'value')]
    row[kind] = text ?? ''
    // Keep text the user entered even when they fill the smaller raw field last.
    // Conflict checking now compares against the newly selected identifiers.
    if (kind === 'key' && keyDraft) {
      row.translations![translationId(row, 'key')] = { text: keyDraft.text, previous: catalogTranslation(row, 'key') }
    }
    if (valueDraft) {
      row.translations![translationId(row, 'value')] = { text: valueDraft.text, previous: catalogTranslation(row, 'value') }
    }
  }

  return { translationId, catalogTranslation, translationText, editTranslation, editRawTag }
}
