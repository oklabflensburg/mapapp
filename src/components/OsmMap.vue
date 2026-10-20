<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
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
import { OsmFeatureLayer, type OsmPopupDeps } from '../map/osmFeatureLayer'
import { PopupTracker } from '../map/popupTracker'
import { UserNodeLayer } from '../map/userNodeLayer'
import { locateUser, type LocationLayers, type MapStatus } from '../map/geolocation'

const props = defineProps<{
  editMode: boolean
}>()

const emit = defineEmits<{
  (event: 'status', status: MapStatus): void
  (event: 'edit-node', node: NodeEditTarget): void
}>()

const { t } = useI18n()

const container = ref<HTMLDivElement>()

let map: L.Map | undefined
let locationLayers: LocationLayers = {}

// User nodes (saved edits) rendered on top of the OSM layer.
const userNodes = useUserNodesStore()

// OSM tags by feature id ("n26013095"), so a saved user node can show the
// original tags of the OSM node it references.
let osmTagsById: Map<string, Record<string, string>> = new Map()

// Shared between both layers: each rebuild removes layers (which closes
// their popups) and must not clear the other's tracked popup target.
const popupTracker = new PopupTracker()

let osmFeatureLayer: OsmFeatureLayer | undefined
let userNodeLayer: UserNodeLayer | undefined
let renderTimer: ReturnType<typeof setTimeout> | undefined

// Popup content depends on the saved user nodes and the active locale; the
// component wires those runtime sources into the pure content builders.
const popupDeps: OsmPopupDeps = {
  customTags: (providerNodeId) => {
    if (providerNodeId === null || providerNodeId === undefined) {
      return undefined
    }
    return userNodes.byProviderNodeId.get(String(providerNodeId))?.tags
  },
  customTagsTitle: () => t('nodeEditor.customTags'),
}

function onEditNode(target: NodeEditTarget): void {
  emit('edit-node', target)
}

// Rebuilds the OSM layer first; the user-node markers after it so they are
// drawn (and hit-tested) last on the shared canvas renderer. Finally the
// tracked OSM popup is re-opened on its new layer.
function renderLayers(): void {
  osmFeatureLayer?.render()
  userNodeLayer?.render(userNodes.nodes)
  osmFeatureLayer?.reopenPopup()
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
    isEditMode: () => props.editMode,
    onEditNode,
    popupDeps,
    popupTracker,
  })
  userNodeLayer = new UserNodeLayer({
    map,
    isEditMode: () => props.editMode,
    onEditNode,
    getOsmTags: (providerNodeId) => osmTagsById.get(providerNodeId) ?? {},
    popupTracker,
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
  popupTracker.clear()
})
</script>

<template>
  <div ref="container" class="map" :class="{ 'map--edit': editMode }" />
</template>

<style scoped>
.map {
  width: 100%;
  height: 100%;
  z-index: 0;
}

/* Crosshair signals that clicks open the node editor. */
.map--edit {
  cursor: crosshair !important;
}
</style>

<style>
/* Popup tag table lives outside the scoped subtree (Leaflet renders popups
   into the map container, not the component template). Styles use PrimeVue
   design tokens so popups follow the configured Aura theme. */
.leaflet-popup-content-wrapper {
  background: var(--p-overlay-popover-background, var(--p-content-background));
  color: var(--p-text-color);
  border-radius: var(--p-content-border-radius);
  box-shadow: var(--p-overlay-popover-shadow, 0 4px 6px -1px rgba(0, 0, 0, 0.1));
}

.leaflet-popup-tip {
  background: var(--p-overlay-popover-background, var(--p-content-background));
}

.osm-tags {
  border-collapse: collapse;
  max-height: 40vh;
  overflow-y: auto;
  display: block;
  color: var(--p-text-color);
}

.osm-tags th,
.osm-tags td {
  text-align: left;
  padding: 0.1rem 0.4rem 0.1rem 0;
  font-size: 0.8rem;
  vertical-align: top;
  border-bottom: 1px solid var(--p-content-border-color);
}

.osm-tags th {
  white-space: nowrap;
  font-weight: 600;
  color: var(--p-text-muted-color);
}

.osm-poi-title {
  margin: 0.75rem 0 0.25rem;
  font-size: 0.9rem;
  font-weight: 600;
  color: var(--p-text-color);
}
</style>
