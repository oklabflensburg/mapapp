<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import * as L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import { useUserNodesStore } from '../stores/userNodes'
import { nodeKey, type NodeEditTarget, type SaveUserNodeInput } from '../stores/userNodes'
import '../map/leafletIcons'
import {
  DEFAULT_CENTER,
  DEFAULT_ZOOM,
  MOVE_RENDER_DEBOUNCE_MS,
  OSM_TILE_URL,
  OSM_TILE_MAX_ZOOM,
  OSM_TILE_ATTRIBUTION,
} from '../map/mapConfig'
import { useOsmDataStore } from '../stores/osmData'
import { OsmFeatureLayer } from '../map/osmFeatureLayer'
import { UserNodeLayer } from '../map/userNodeLayer'
import { locateUser, type LocationLayers, type MapStatus } from '../map/geolocation'

const emit = defineEmits<{
  (event: 'status', status: MapStatus): void
  (event: 'edit-node', node: NodeEditTarget): void
  (event: 'deselect-node'): void
}>()

const container = ref<HTMLDivElement>()
const { t } = useI18n()
const createMode = ref(false)
const creating = ref(false)
const createFailed = ref(false)
let pendingCreation: SaveUserNodeInput | undefined

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

function onEditNode(target: NodeEditTarget, click: L.LatLng): boolean {
  clickOnFeature = true
  if (createMode.value || creating.value) {
    void createNode(click, target)
    return false
  }
  // The selection moved to another element; drop the previous highlight.
  clearSelection()
  selectedProviderNodeId = nodeKey(target)
  emit('edit-node', target)
  return true
}

function toggleCreateMode(): void {
  if (creating.value) return
  createMode.value = !createMode.value
  createFailed.value = false
  pendingCreation = undefined
  if (createMode.value) {
    clearSelection()
    emit('deselect-node')
  }
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape' && createMode.value && !creating.value) toggleCreateMode()
}

async function createNode(click: L.LatLng, parent?: NodeEditTarget): Promise<void> {
  if (creating.value) return
  // Retain the complete request after failure: retries cannot create a second
  // node if the server committed but its response was lost.
  pendingCreation ??= {
    provider: 'local',
    providerNodeId: crypto.randomUUID(),
    latitude: click.lat,
    longitude: click.lng,
    tags: {},
    ...(parent ? { parent: parent.id !== undefined
      ? { id: parent.id }
      : { providerNodeId: parent.providerNodeId, latitude: parent.latitude, longitude: parent.longitude } } : {}),
  }
  creating.value = true
  createFailed.value = false
  const saved = await userNodes.save(pendingCreation)
  creating.value = false
  if (!saved) {
    createFailed.value = true
    return
  }
  pendingCreation = undefined
  createMode.value = false
  clearSelection()
  selectedProviderNodeId = nodeKey(saved)
  emit('edit-node', { ...saved, osmTags: {} })
}

function retryCreation(): void {
  if (pendingCreation) {
    void createNode(L.latLng(pendingCreation.latitude, pendingCreation.longitude))
  }
}

// Exposed so the view can drop the map highlight when the panel is closed.
defineExpose({ clearSelection })

// User nodes (saved edits) rendered on top of the OSM layer.
const userNodes = useUserNodesStore()
const osmData = useOsmDataStore()

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
  const data = await osmData.load()
  if (!data) {
    return
  }
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
  window.addEventListener('keydown', onKeydown)

  L.tileLayer(OSM_TILE_URL, {
    maxZoom: OSM_TILE_MAX_ZOOM,
    attribution: OSM_TILE_ATTRIBUTION,
  }).addTo(map)

  osmFeatureLayer = new OsmFeatureLayer({
    map,
    onEditNode,
    onSelect: () => userNodeLayer?.bringToFront(),
  })
  userNodeLayer = new UserNodeLayer({
    map,
    onEditNode,
    getOsmTags: (providerNodeId) => osmData.tagsById?.get(providerNodeId) ?? {},
  })

  // Moving or zooming the map rebuilds the layers but never deselects; only
  // a click on empty space (no feature hit) drops the selection.
  map.on('click', (event: L.LeafletMouseEvent) => {
    if (clickOnFeature) {
      clickOnFeature = false
      return
    }
    if (createMode.value || creating.value) {
      void createNode(event.latlng)
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
  window.removeEventListener('keydown', onKeydown)
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
  <div ref="container" class="map" :class="{ 'create-mode': createMode }" />
  <div class="create-controls">
    <Button
      class="create-toggle"
      :class="{ active: createMode }"
      icon="pi pi-plus"
      :aria-label="t('map.create.add')"
      :title="t('map.create.add')"
      :aria-pressed="createMode"
      :severity="createMode ? 'primary' : 'secondary'"
      :disabled="creating"
      @click="toggleCreateMode"
    />
    <p v-if="createMode" role="status">{{ t('map.create.hint') }}</p>
    <div v-if="createFailed" role="alert">
      <p>{{ t('map.create.failed') }}</p>
      <Button :label="t('map.create.retry')" :disabled="creating" @click="retryCreation" />
    </div>
  </div>
</template>

<style scoped>
.map {
  width: 100%;
  height: 100%;
  z-index: 0;
}

.map.create-mode,
.map.create-mode :deep(.leaflet-interactive) {
  cursor: crosshair;
}

.create-controls {
  position: absolute;
  z-index: 1000;
  top: calc(5.5rem + env(safe-area-inset-top));
  left: 0.75rem;
  max-width: min(20rem, calc(100vw - 1.5rem));
}

.create-controls p {
  margin: 0.5rem 0;
  padding: 0.5rem 0.75rem;
  background: var(--p-content-background);
  border-radius: var(--p-content-border-radius);
  box-shadow: 0 2px 6px #0002;
  font-size: 0.875rem;
}

.create-toggle.active {
  background: var(--p-primary-color, #059669);
  border-color: var(--p-primary-color, #059669);
  color: var(--p-primary-contrast-color, #fff);
  outline: 2px solid var(--p-primary-color, #059669);
  outline-offset: 2px;
}
</style>
