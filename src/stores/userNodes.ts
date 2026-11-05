import { computed, ref } from 'vue'
import { defineStore } from 'pinia'

// A tag on a node or relation; a list instead of a record so one node can
// carry several tags with the same key but different values.
export interface TagEntry {
  key: string
  value: string
}

// Returns the first value stored for the key.
export function tagValue(tags: TagEntry[] | undefined, key: string): string | undefined {
  return tags?.find((tag) => tag.key === key)?.value
}

export interface RelatedNode {
  id: number
  provider: 'OSM' | 'local'
  providerNodeId: string
  name: string | null
  tags: TagEntry[]
}

export interface UserNode {
  id: number
  approved: boolean
  provider: 'OSM' | 'local'
  // Kept as arrays in the API for compatibility; each contains at most one parent.
  parentIds: number[]
  childIds: number[]
  parents: RelatedNode[]
  children: RelatedNode[]
  providerNodeId: string
  latitude: number
  longitude: number
  tags: TagEntry[]
  // Tag key/value pairs not yet approved by an admin.
  observations: TagEntry[]
  // Proposed translations for the observation pairs, matched by key and value.
  observationTranslations?: { key: string; value: string; translations: Partial<Record<'en' | 'de', { keyTranslation?: string; valueTranslation?: string }>> }[]
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
  translationLocale?: 'en' | 'de'
  translations?: {
    key: string
    value: string
    keyTranslation?: string
    valueTranslation?: string
    previousKeyTranslation?: string
    previousValueTranslation?: string
  }[]
  provider: 'OSM' | 'local'
  parent?: { id: number } | { providerNodeId: string; latitude: number; longitude: number }
  providerNodeId: string
  latitude: number
  longitude: number
  tags: TagEntry[]
}

// User nodes include local map points and custom tag edits to OSM features.
export const useUserNodesStore = defineStore('userNodes', () => {
  const nodes = ref<UserNode[]>([])
  const saving = ref(false)
  const loadFailed = ref(false)
  const saveFailed = ref(false)
  const saveConflict = ref(false)

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
    saveConflict.value = false
    try {
      const response = await fetch('/api/nodes', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(input),
      })
      if (!response.ok) {
        saveConflict.value = response.status === 409
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
        name: tagValue(saved.tags, 'name')?.trim() || null,
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

  return { nodes, byIdentity, saving, loadFailed, saveFailed, saveConflict, load, save }
})

export function nodeKey(node: Pick<NodeEditTarget, 'provider' | 'providerNodeId'>): string {
  return `${node.provider}:${node.providerNodeId}`
}
