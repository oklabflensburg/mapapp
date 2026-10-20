import { computed, ref, shallowRef } from 'vue'
import { defineStore } from 'pinia'
import { loadOsmData, type OsmData } from '../map/osmData'

// Share the same raw feature/tag index between the map and node editors.
export const useOsmDataStore = defineStore('osmData', () => {
  const data = shallowRef<OsmData>()
  const loading = ref(false)
  const loadFailed = ref(false)
  const tagsById = computed(() => data.value?.tagsById)
  let request: Promise<OsmData | undefined> | undefined

  function load(): Promise<OsmData | undefined> {
    request ??= (async () => {
      loading.value = true
      try {
        data.value = await loadOsmData()
        loadFailed.value = data.value === undefined
        return data.value
      } finally {
        loading.value = false
      }
    })()
    return request
  }

  return { data, tagsById, loading, loadFailed, load }
})
