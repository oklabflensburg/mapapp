<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import * as L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import { useUserNodesStore } from '../stores/userNodes'
import type { NodeEditTarget } from '../stores/userNodes'
import '../map/leafletIcons'
import {
  DEFAULT_CENTER,
  DEFAULT_ZOOM,
  MOVE_RENDER_DEBOUNCE_MS,
  OSM_TILE_URL,
  OSM_TILE_MAX_ZOOM,
  OSM_TILE_ATTRIBUTION,
} from '../map/mapConfig'
import { loadOsmData } from '../map/osmData'
import { OsmFeatureLayer } from '../map/osmFeatureLayer'
import { UserNodeLayer } from '../map/userNodeLayer'
import { locateUser, type LocationLayers, type MapStatus } from '../map/geolocation'

const emit = defineEmits<{
  (event: 'status', status: MapStatus): void
  (event: 'edit-node', node: NodeEditTarget): void
  (event: 'deselect-node'): void
}>()

const container = ref<HTMLDivElement>()

let map: L.Map | undefined
let locationLayers: LocationLayers = {}

// The selected node's provider id; null while nothing is selected. The node
// panel stays open as long as an element is selected.
let selectedProviderNodeId: string | undefined

// Set when a click hit a rendered feature; Leaflet fires the feature's click
// handlers before the map-level click, so the map handler can tell feature
// clicks and clicks on empty space apart.
let clickOnFeature = false

function clearSelection(): void {
  selectedProviderNodeId = undefined
  osmFeatureLayer?.clearSelection()
}

function onEditNode(target: NodeEditTarget): void {
  clickOnFeature = true
  // The selection moved to another element; drop the previous highlight.
  clearSelection()
  selectedProviderNodeId = target.providerNodeId
  emit('edit-node', target)
}

// Exposed so the view can drop the map highlight when the panel is closed.
defineExpose({ clearSelection })

// User nodes (saved edits) rendered on top of the OSM layer.
const userNodes = useUserNodesStore()

// OSM tags by feature id ("n26013095"), so a saved user node can show the
// original tags of the OSM node it references.
let osmTagsById: Map<string, Record<string, string>> = new Map()

let osmFeatureLayer: OsmFeatureLayer | undefined
let userNodeLayer: UserNodeLayer | undefined
let renderTimer: ReturnType<typeof setTimeout> | undefined

// Rebuilds the OSM layer first, then the user-node markers after it so they
// are drawn (and hit-tested) last on the shared canvas renderer.
function renderLayers(): void {
  osmFeatureLayer?.render()
  userNodeLayer?.render(userNodes.nodes)
}

function scheduleRenderLayers(): void {
  if (renderTimer) {
    clearTimeout(renderTimer)
  }
  renderTimer = setTimeout(renderLayers, MOVE_RENDER_DEBOUNCE_MS)
}

watch(
  () => userNodes.nodes,
  () => userNodeLayer?.render(userNodes.nodes),
)

async function loadOsmFeatures(): Promise<void> {
  const data = await loadOsmData()
  if (!data) {
    return
  }
  osmTagsById = data.tagsById
  osmFeatureLayer?.setFeatures(data.features)
  renderLayers()
}

onMounted(() => {
  if (!container.value) {
    return
  }

  // Canvas hit-testing is exact by default, so thin lines (e.g. 1.5px roads)
  // would need pixel-perfect clicks. tolerance widens the hit area around
  // every path without changing how it is drawn.
  map = L.map(container.value, { renderer: L.canvas({ tolerance: 10 }) }).setView(DEFAULT_CENTER, DEFAULT_ZOOM)

  L.tileLayer(OSM_TILE_URL, {
    maxZoom: OSM_TILE_MAX_ZOOM,
    attribution: OSM_TILE_ATTRIBUTION,
  }).addTo(map)

  osmFeatureLayer = new OsmFeatureLayer({
    map,
    onEditNode,
  })
  userNodeLayer = new UserNodeLayer({
    map,
    onEditNode,
    getOsmTags: (providerNodeId) => osmTagsById.get(providerNodeId) ?? {},
  })

  // Moving or zooming the map rebuilds the layers but never deselects; only
  // a click on empty space (no feature hit) drops the selection.
  map.on('click', () => {
    if (clickOnFeature) {
      clickOnFeature = false
      return
    }
    if (selectedProviderNodeId !== undefined) {
      clearSelection()
      emit('deselect-node')
    }
  })

  map.on('moveend', scheduleRenderLayers)
  void loadOsmFeatures()
  void userNodes.load()

  locationLayers = locateUser(() => map, (status) => emit('status', status))
})

onBeforeUnmount(() => {
  if (renderTimer) {
    clearTimeout(renderTimer)
  }
  osmFeatureLayer?.destroy()
  userNodeLayer?.destroy()
  locationLayers.accuracyCircle?.remove()
  locationLayers.marker?.remove()
  map?.remove()
  map = undefined
  osmFeatureLayer = undefined
  userNodeLayer = undefined
  locationLayers = {}
})
</script>

<template>
  <div ref="container" class="map" />
</template>

<style scoped>
.map {
  width: 100%;
  height: 100%;
  z-index: 0;
}
</style>
