import { computed, ref } from 'vue'
import { defineStore } from 'pinia'

export interface UserNode {
  id: number
  providerNodeId: string
  latitude: number
  longitude: number
  tags: Record<string, string>
  createdAt: string
  updatedAt: string
}

export interface SaveUserNodeInput {
  providerNodeId: string
  latitude: number
  longitude: number
  tags: Record<string, string>
}

// User nodes are user-authored copies of provider (currently OSM) nodes with
// custom tags, persisted via the backend API.
export const useUserNodesStore = defineStore('userNodes', () => {
  const nodes = ref<UserNode[]>([])
  const saving = ref(false)
  const loadFailed = ref(false)
  const saveFailed = ref(false)

  const byProviderNodeId = computed(() => {
    const map = new Map<string, UserNode>()
    for (const node of nodes.value) {
      map.set(node.providerNodeId, node)
    }
    return map
  })

  async function load(): Promise<void> {
    loadFailed.value = false
    try {
      const response = await fetch('/api/nodes')
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`)
      }
      nodes.value = (await response.json()) as UserNode[]
    } catch (error) {
      console.warn('Failed to load user nodes', error)
      loadFailed.value = true
    }
  }

  async function save(input: SaveUserNodeInput): Promise<UserNode | null> {
    saving.value = true
    saveFailed.value = false
    try {
      const response = await fetch('/api/nodes', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(input),
      })
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`)
      }
      const saved = (await response.json()) as UserNode
      const index = nodes.value.findIndex((node) => node.providerNodeId === saved.providerNodeId)
      nodes.value =
        index === -1
          ? [saved, ...nodes.value]
          : nodes.value.map((node, i) => (i === index ? saved : node))
      return saved
    } catch (error) {
      console.warn('Failed to save user node', error)
      saveFailed.value = true
      return null
    } finally {
      saving.value = false
    }
  }

  return { nodes, byProviderNodeId, saving, loadFailed, saveFailed, load, save }
})
