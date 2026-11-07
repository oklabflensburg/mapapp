import { ref } from 'vue'
import { defineStore } from 'pinia'

export interface AspectType {
  name: string
  attributes: { key: string; kind: 'enum' | 'range'; values: string[]; labels?: Record<string, string> }[]
}
export const useEditingCatalogStore = defineStore('editingCatalog', () => {
  const aspectTypes = ref<AspectType[]>([])
  const wayVocabularies = ref<Record<string, { key: string; values: string[] }[]>>({})
  const failed = ref(false)
  const loading = ref(false)
  let loaded = false
  let pending: Promise<void> | undefined
  function load(): Promise<void> {
    if (loaded) return Promise.resolve()
    if (pending) return pending
    loading.value = true
    failed.value = false
    pending = (async () => {
      try {
        const response = await fetch('/api/editing-catalog')
        if (!response.ok) throw new Error(`HTTP ${response.status}`)
        const data = await response.json()
        aspectTypes.value = data.aspectTypes
        wayVocabularies.value = data.wayVocabularies
        loaded = true
      } catch {
        failed.value = true
      } finally {
        loading.value = false
        pending = undefined
      }
    })()
    return pending
  }
  function reload(): Promise<void> {
    loaded = false
    return load()
  }
  return { aspectTypes, wayVocabularies, failed, loading, load, reload }
})
