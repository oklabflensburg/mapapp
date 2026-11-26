import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import type { RelatedNode, TagEntry } from './userNodes'
import type { TagTranslationProposal } from '../lib/tagDrafts'

// A group member summary carries the node's position so the map can draw the
// line through the ordered members.
export interface GroupMember extends RelatedNode {
  latitude: number
  longitude: number
}

// The street part's corridor around its member line, computed by the backend
// with a fixed width (see backend StreetPartArea); GeoJSON polygon geometry
// with [lon, lat] points. Null when the group has fewer than two members
// (a point observation).
export interface GroupArea {
  type: 'Polygon'
  coordinates: [number, number][][]
}

// A node relation is one observation (specs/map-editing/observation-relations/
// spec.md): it carries exactly one tag over its own member range, and its
// pending observation is that tag until an admin approves it.
export interface NodeGroup {
  id: number
  // Human-readable name, e.g. of the road part the relation covers.
  name: string | null
  members: GroupMember[]
  // Parents are returned independently of member visibility in /api/nodes.
  parents?: RelatedNode[]
  area: GroupArea | null
  tags: TagEntry[]
  // The relation's single tag pair while it is not yet approved.
  observations: TagEntry[]
  // True once the relation's tag was approved (see NodeGroupManager),
  // like node approval.
  approved: boolean
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
  // Exactly one observation tag per relation; omitting it makes a
  // naming-only relation.
  tags?: TagEntry[]
  translationLocale?: 'en' | 'de'
  translations?: TagTranslationProposal[]
}

export const useNodeGroupsStore = defineStore('nodeGroups', () => {
  const groups = ref<NodeGroup[]>([])
  const saving = ref(false)
  const loadFailed = ref(false)
  const saveFailed = ref(false)
  const saveConflict = ref(false)

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
    saveConflict.value = false
    try {
      const response = await fetch('/api/node-groups', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(input),
      })
      if (!response.ok) {
        saveConflict.value = response.status === 409
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

  // Removing an observation removes its relation (see the observation-
  // relations spec R1).
  async function remove(id: number): Promise<boolean> {
    saveFailed.value = false
    try {
      const response = await fetch(`/api/node-groups/${id}`, { method: 'DELETE' })
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`)
      }
      groups.value = groups.value.filter((group) => group.id !== id)
      return true
    } catch (error) {
      console.warn('Failed to remove node relation', error)
      saveFailed.value = true
      return false
    }
  }

  return { groups, byMember, saving, loadFailed, saveFailed, saveConflict, load, save, remove }
})
