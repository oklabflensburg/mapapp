import { computed, ref } from 'vue'
import { defineStore } from 'pinia'

export interface RelatedNode {
  id: number
  provider: 'OSM' | 'local'
  providerNodeId: string
  name: string | null
  tags: Record<string, string>
}

export interface UserNode {
  id: number
  provider: 'OSM' | 'local'
  parentIds: number[]
  childIds: number[]
  parents: RelatedNode[]
  children: RelatedNode[]
  providerNodeId: string
  latitude: number
  longitude: number
  tags: Record<string, string>
  createdAt: string
  updatedAt: string
}

// A selected saved node or OSM feature, including original OSM tags when available.
export interface NodeEditTarget {
  id?: number
  provider: 'OSM' | 'local'
  providerNodeId: string
  latitude: number
  longitude: number
  osmTags: Record<string, string>
}

export interface SaveUserNodeInput {
  provider: 'OSM' | 'local'
  parent?: { id: number } | { providerNodeId: string; latitude: number; longitude: number }
  providerNodeId: string
  latitude: number
  longitude: number
  tags: Record<string, string>
}

// User nodes include local map points and custom tag edits to OSM features.
export const useUserNodesStore = defineStore('userNodes', () => {
  const nodes = ref<UserNode[]>([])
  const saving = ref(false)
  const loadFailed = ref(false)
  const saveFailed = ref(false)

  const byIdentity = computed(() => {
    const map = new Map<string, UserNode>()
    for (const node of nodes.value) {
      map.set(nodeKey(node), node)
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
      const index = nodes.value.findIndex((node) => nodeKey(node) === nodeKey(saved))
      nodes.value =
        index === -1
          ? [saved, ...nodes.value]
          : nodes.value.map((node, i) => (i === index ? saved : node))
      // Keep relation labels and tags current when a related node is edited.
      const summary: RelatedNode = {
        id: saved.id,
        provider: saved.provider,
        providerNodeId: saved.providerNodeId,
        name: saved.tags.name?.trim() ? saved.tags.name : null,
        tags: saved.tags,
      }
      nodes.value = nodes.value.map((node) => ({
        ...node,
        parents: node.parents.map((related) => related.id === saved.id ? summary : related),
        children: node.children.map((related) => related.id === saved.id ? summary : related),
      }))
      if (input.parent) {
        await load()
      }
      return saved
    } catch (error) {
      console.warn('Failed to save user node', error)
      saveFailed.value = true
      return null
    } finally {
      saving.value = false
    }
  }

  return { nodes, byIdentity, saving, loadFailed, saveFailed, load, save }
})

export function nodeKey(node: Pick<NodeEditTarget, 'provider' | 'providerNodeId'>): string {
  return `${node.provider}:${node.providerNodeId}`
}
