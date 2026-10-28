import { ref } from 'vue'
import { defineStore } from 'pinia'
import type { AppLocale } from './settings'

// Last-resort display version of a raw tag key. Keep in sync with
// TagKeyLabeler::prettify (backend) — unknown keys never render with
// underscores.
export function prettifyTagKey(key: string): string {
  return key.replaceAll('_', ' ')
}

// Tag key display labels, fetched once per locale from
// /api/i18n/tag-keys/{locale} (the backend merges the OSM key catalogue
// with admin-managed overrides). Not part of the vue-i18n message tree:
// keys like "fire_hydrant:type" collide with vue-i18n's path syntax.
export const useTagKeyLabelsStore = defineStore('tagKeyLabels', () => {
  const labels = ref<Record<string, string>>({})
  let loadedFor: AppLocale | null = null
  let requestSeq = 0

  // Replaces the map with the backend labels for the locale; a failed
  // request keeps the current map, and every lookup falls back to the
  // prettified key anyway. A newer call (locale switch) supersedes an
  // in-flight one, so a slow old response never overwrites fresh labels.
  async function load(locale: AppLocale): Promise<void> {
    if (loadedFor === locale) {
      return
    }
    const seq = ++requestSeq
    try {
      const response = await fetch(`/api/i18n/tag-keys/${locale}`)
      if (!response.ok || seq !== requestSeq) {
        return
      }
      const data = (await response.json()) as { labels?: unknown }
      if (typeof data.labels === 'object' && data.labels !== null) {
        labels.value = Object.fromEntries(
          Object.entries(data.labels).filter(
            (entry): entry is [string, string] => typeof entry[1] === 'string',
          ),
        )
        loadedFor = locale
      }
    } catch (error) {
      console.warn('Failed to load tag key labels', error)
    }
  }

  function label(key: string): string {
    return labels.value[key] ?? prettifyTagKey(key)
  }

  return { labels, load, label }
})
