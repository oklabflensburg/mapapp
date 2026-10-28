import { computed, ref } from 'vue'
import { defineStore } from 'pinia'

export interface DefaultObservationTag {
  key: string
  // Values the user can pick for the key; empty leaves the value free-form.
  values: string[]
}

// Tag keys an admin exposed as default observation tags (OSM or custom
// keys) with their selectable values, fetched once from
// /api/default-observation-tags. The node and relation editors offer them
// in a searchable dropdown when users add observations.
export const useDefaultObservationTagsStore = defineStore('defaultObservationTags', () => {
  const tags = ref<DefaultObservationTag[]>([])
  const keys = computed(() => tags.value.map((entry) => entry.key))
  let loaded = false
  let requestSeq = 0

  // Replaces the list with the backend's; a failed request keeps the empty
  // list, and the editors simply offer no suggestions then. A newer call
  // supersedes an in-flight one so a slow response never overwrites a
  // fresh one.
  async function load(): Promise<void> {
    if (loaded) {
      return
    }
    const seq = ++requestSeq
    try {
      const response = await fetch('/api/default-observation-tags')
      if (!response.ok || seq !== requestSeq) {
        return
      }
      const data = (await response.json()) as { tags?: unknown }
      if (!Array.isArray(data.tags)) {
        return
      }
      const parsed: DefaultObservationTag[] = []
      for (const entry of data.tags) {
        if (typeof entry !== 'object' || entry === null) {
          continue
        }
        const { tag, values } = entry as { tag?: unknown; values?: unknown }
        if (typeof tag !== 'string') {
          continue
        }
        parsed.push({
          key: tag,
          values: Array.isArray(values)
            ? values.filter((value): value is string => typeof value === 'string')
            : [],
        })
      }
      tags.value = parsed
      loaded = true
    } catch (error) {
      console.warn('Failed to load default observation tags', error)
    }
  }

  // The selectable values for a key; keys without configured values (and
  // unknown keys) return an empty list, so the value stays free-form.
  function valuesFor(key: string): string[] {
    return tags.value.find((entry) => entry.key === key)?.values ?? []
  }

  return { tags, keys, load, valuesFor }
})
