<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import OsmMap from '../components/OsmMap.vue'
import UserBar from '../components/UserBar.vue'
import type { MapStatus } from '../map/geolocation'
import NodeTagPanel from '../components/NodeTagPanel.vue'
import RelationTagPanel from '../components/RelationTagPanel.vue'
import type { NodeGroup } from '../stores/nodeGroups'
import { nodeKey, type NodeEditTarget } from '../stores/userNodes'
const { t } = useI18n()
const route = useRoute()

// URL deep link (?lat=&lon=&zoom=) pins the start position; the admin node
// preview uses it to open the map on a specific node. Without both
// coordinates the app falls back to geolocation.
function queryNumber(name: 'lat' | 'lon' | 'zoom'): number {
  const value = route.query[name]
  return typeof value === 'string' ? Number(value) : Number.NaN
}
const linkLatitude = queryNumber('lat')
const linkLongitude = queryNumber('lon')
const previewCenter = Number.isFinite(linkLatitude) && Number.isFinite(linkLongitude)
  ? { latitude: linkLatitude, longitude: linkLongitude, zoom: queryNumber('zoom') }
  : undefined

// A pinned position skips geolocation, so its status flow never starts.
const mapStatus = ref<MapStatus>(previewCenter ? 'found' : 'locating')

const mapStatusMessage = computed(() => {
  switch (mapStatus.value) {
    case 'locating':
      return t('map.status.locating')
    case 'unsupported':
      return t('map.status.unsupported')
    case 'unavailable':
      return t('map.status.unavailable')
    default:
      return ''
  }
})

const mapRef = ref<InstanceType<typeof OsmMap> | null>(null)
const editTarget = ref<NodeEditTarget | null>(null)
// The relation being edited; opening it replaces the node selection.
const relationTarget = ref<NodeGroup | null>(null)

function selectNode(node: NodeEditTarget): void {
  // Re-clicking the selected element keeps it (and the panel's unsaved
  // edits); only a click elsewhere changes or clears the selection. Node
  // and relation panels are mutually exclusive: selecting a node closes the
  // relation panel.
  if (!editTarget.value || nodeKey(editTarget.value) !== nodeKey(node)) {
    editTarget.value = node
    relationTarget.value = null
  }
}

// Closing the panel deselects the element, so the map drops its highlight.
function deselectNode(): void {
  editTarget.value = null
  mapRef.value?.clearSelection()
}

function onPanelVisible(visible: boolean): void {
  if (!visible) {
    deselectNode()
  }
}

// Editing a relation closes the node panel and highlights its whole area on
// the map. Re-selecting the open relation keeps the panel's unsaved edits.
function onEditRelation(group: NodeGroup): void {
  if (!relationTarget.value || relationTarget.value.id !== group.id) {
    relationTarget.value = group
  }
  editTarget.value = null
  mapRef.value?.selectRelation(group)
}

function deselectRelation(): void {
  relationTarget.value = null
  mapRef.value?.clearSelection()
}

function onRelationPanelVisible(visible: boolean): void {
  if (!visible) {
    deselectRelation()
  }
}
</script>

<template>
  <main class="home">
    <p v-if="mapStatusMessage" class="map-status" role="status">{{ mapStatusMessage }}</p>
    <UserBar />
    <OsmMap
      ref="mapRef"
      :preview-center="previewCenter"
      @status="mapStatus = $event"
      @edit-node="selectNode"
      @deselect-node="deselectNode"
      @edit-relation="onEditRelation"
      @deselect-relation="deselectRelation"
    />
    <NodeTagPanel
      :visible="editTarget !== null"
      :node="editTarget"
      @update:visible="onPanelVisible"
      @edit-relation="onEditRelation"
    />
    <RelationTagPanel
      :visible="relationTarget !== null"
      :group="relationTarget"
      @update:visible="onRelationPanelVisible"
      @saved="relationTarget = $event"
    />
  </main>
</template>

<style scoped>
/* Mobile-first: the map is the app and fills the viewport. dvh tracks the
   dynamic viewport so mobile browser toolbars don't cut the map off. */
.home {
  position: relative;
  height: 100vh;
  height: 100dvh;
}

/* Floating status chip above the map; clear of notches and Leaflet's own
   top-left zoom controls. */
.map-status {
  position: absolute;
  z-index: 1100;
  top: calc(0.5rem + env(safe-area-inset-top));
  left: 50%;
  transform: translateX(-50%);
  margin: 0;
  padding: 0.375rem 0.75rem;
  border-radius: var(--p-content-border-radius);
  background: var(--p-content-background);
  color: var(--p-text-muted-color);
  box-shadow: var(--p-overlay-popover-shadow, 0 4px 6px -1px rgba(0, 0, 0, 0.1));
  font-size: 0.875rem;
  white-space: nowrap;
  max-width: calc(100vw - 2rem);
  overflow: hidden;
  text-overflow: ellipsis;
}
</style>
