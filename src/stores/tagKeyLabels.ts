import { ref } from 'vue'
import { defineStore } from 'pinia'
import type { AppLocale } from './settings'

// Last-resort display version of a raw tag key. Keep in sync with
// TagKeyLabeler::prettify (backend) — unknown keys never render with
// underscores.
export function prettifyTagKey(key: string): string {
  return key.replaceAll('_', ' ')
}

// File-backed key and contextual value labels, shared with EasyAdmin.
// Raw tag identifiers stay outside vue-i18n's message path syntax.
export const useTagKeyLabelsStore = defineStore('tagKeyLabels', () => {
  const labels = ref<Record<string, string>>({})
  const values = ref<Record<string, string>>({})
  let loadedFor: AppLocale | null = null
  let requestSeq = 0

  // Replaces the map with the backend labels for the locale; a failed
  // request leaves fallback labels, and every lookup falls back to the
  // prettified key anyway. A newer call (locale switch) supersedes an
  // in-flight one, so a slow old response never overwrites fresh labels.
  async function load(locale: AppLocale, force = false): Promise<void> {
    if (!force && loadedFor === locale) {
      return
    }
    const seq = ++requestSeq
    loadedFor = null
    labels.value = {}
    values.value = {}
    try {
      const response = await fetch(`/api/i18n/tag-keys/${locale}`)
      if (!response.ok || seq !== requestSeq) {
        return
      }
      const data = (await response.json()) as { labels?: unknown; values?: unknown }
      if (seq !== requestSeq) return
      if (typeof data.labels === 'object' && data.labels !== null) {
        labels.value = Object.fromEntries(
          Object.entries(data.labels).filter(
            (entry): entry is [string, string] => typeof entry[1] === 'string' && entry[1] !== '',
          ),
        )
        if (typeof data.values === 'object' && data.values !== null) {
          values.value = Object.fromEntries(
            Object.entries(data.values).filter(
              (entry): entry is [string, string] => typeof entry[1] === 'string' && entry[1] !== '',
            ),
          )
        }
        loadedFor = locale
      }
    } catch (error) {
      console.warn('Failed to load tag key labels', error)
    }
  }

  function label(key: string): string {
    return Object.hasOwn(labels.value, key) ? labels.value[key]! : prettifyTagKey(key)
  }

  function valueLabel(key: string, value: string): string {
    const id = JSON.stringify([key, value])
    return Object.hasOwn(values.value, id) ? values.value[id]! : value
  }

  return { labels, values, load, label, valueLabel }
})
