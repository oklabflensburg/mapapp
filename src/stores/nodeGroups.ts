import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import type { RelatedNode } from './userNodes'

// A group member summary carries the node's position so the map can draw the
// line through the ordered members.
export interface GroupMember extends RelatedNode {
  latitude: number
  longitude: number
}

// The street part's corridor around its member line, computed by the backend
// with a fixed width (see backend StreetPartArea); GeoJSON polygon geometry
// with [lon, lat] points. Null when the group has fewer than two members.
export interface GroupArea {
  type: 'Polygon'
  coordinates: [number, number][][]
}

// A node relation groups multiple related nodes (e.g. a part of a road) and
// carries its own tags.
export interface NodeGroup {
  id: number
  // Human-readable name, e.g. of the road part the relation covers.
  name: string | null
  members: GroupMember[]
  area: GroupArea | null
  tags: Record<string, string>
  // Tag key/value pairs not yet approved by an admin.
  observations: Record<string, string>
  createdAt: string
  updatedAt: string
}

export type GroupMemberReference = { id: number } | { providerNodeId: string; latitude: number; longitude: number }

export interface SaveNodeGroupInput {
  // When set, the relation is updated instead of created.
  id?: number
  // Names the relation; empty clears it, omitted keeps the current name.
  name?: string
  members?: GroupMemberReference[]
  tags?: Record<string, string>
}

export const useNodeGroupsStore = defineStore('nodeGroups', () => {
  const groups = ref<NodeGroup[]>([])
  const saving = ref(false)
  const loadFailed = ref(false)
  const saveFailed = ref(false)

  // Relations containing the node, indexed by its provider identity so a
  // selected node or OSM feature shows the relations it belongs to.
  const byMember = computed(() => {
    const map = new Map<string, NodeGroup[]>()
    for (const group of groups.value) {
      for (const member of group.members) {
        const key = `${member.provider}:${member.providerNodeId}`
        const list = map.get(key)
        if (list) {
          list.push(group)
        } else {
          map.set(key, [group])
        }
      }
    }
    return map
  })

  async function load(): Promise<void> {
    loadFailed.value = false
    try {
      const response = await fetch('/api/node-groups')
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`)
      }
      groups.value = (await response.json()) as NodeGroup[]
    } catch (error) {
      console.warn('Failed to load node relations', error)
      loadFailed.value = true
    }
  }

  async function save(input: SaveNodeGroupInput): Promise<NodeGroup | null> {
    saving.value = true
    saveFailed.value = false
    try {
      const response = await fetch('/api/node-groups', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(input),
      })
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`)
      }
      const saved = (await response.json()) as NodeGroup
      const index = groups.value.findIndex((group) => group.id === saved.id)
      groups.value =
        index === -1
          ? [saved, ...groups.value]
          : groups.value.map((group, i) => (i === index ? saved : group))
      return saved
    } catch (error) {
      console.warn('Failed to save node relation', error)
      saveFailed.value = true
      return null
    } finally {
      saving.value = false
    }
  }

  return { groups, byMember, saving, loadFailed, saveFailed, load, save }
})
