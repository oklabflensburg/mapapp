<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import * as L from 'leaflet'
import type { Feature } from 'geojson'
import 'leaflet/dist/leaflet.css'
import { useUserNodesStore } from '../stores/userNodes'
import type { UserNode } from '../stores/userNodes'
import type { NodeEditTarget } from './NodeEditorDialog.vue'
import '../map/leafletIcons'
import {
  DEFAULT_CENTER,
  DEFAULT_ZOOM,
  MAX_VISIBLE_FEATURES,
  MOVE_RENDER_DEBOUNCE_MS,
  OSM_TILE_URL,
  OSM_TILE_MAX_ZOOM,
  OSM_TILE_ATTRIBUTION,
} from '../map/mapConfig'
import { bboxArea, isMinorFeature, pointsInside, type IndexedFeature } from '../map/featureIndex'
import { enableAreaHitTest } from '../map/areaHitTest'
import { highlightStyleFor, POINT_MARKER_OPTIONS, styleFor } from '../map/featureStyling'
import { osmPopupContent, tagTable, type PopupDependencies } from '../map/popupContent'
import { loadOsmData } from '../map/osmData'
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

let osmFeatures: IndexedFeature[] = []
let osmLayer: L.GeoJSON | undefined
let renderTimer: ReturnType<typeof setTimeout> | undefined

// OSM tags by feature id ("n26013095"), so a saved user node can show the
// original tags of the OSM node it references.
let osmTagsById: Map<string, Record<string, string>> = new Map()
let userNodeLayer: L.LayerGroup | undefined

// Currently selected feature and its rendered layer. The layer reference is
// rebuilt on every render, so the feature is the source of truth.
let highlightedFeature: Feature | undefined
let highlightedLayer: L.Path | undefined

// Popup whose source layer is currently open. Every render rebuilds the
// layers, and Leaflet closes a popup when its source layer is removed, so the
// popup is re-opened on the new layer after each rebuild.
type PopupTarget = { kind: 'osm'; feature: Feature } | { kind: 'userNode'; node: UserNode }
let openPopupTarget: PopupTarget | undefined
// True while layers are being rebuilt; popupclose events fired by the rebuild
// itself must not clear openPopupTarget.
let rebuildingLayers = false

// Popup content depends on the feature index, the saved user nodes and the
// active locale; the component wires those runtime sources into the pure
// content builders.
const popupDeps: PopupDependencies = {
  pointsInside: (feature) => pointsInside(osmFeatures, feature),
  customTags: (providerNodeId) => {
    if (providerNodeId === null || providerNodeId === undefined) {
      return undefined
    }
    return userNodes.byProviderNodeId.get(String(providerNodeId))?.tags
  },
  customTagsTitle: () => t('nodeEditor.customTags'),
}

function setHighlighted(feature: Feature, layer: L.Path): void {
  if (highlightedLayer && highlightedLayer !== layer) {
    highlightedLayer.setStyle(styleFor(highlightedFeature as Feature))
  }
  highlightedFeature = feature
  highlightedLayer = layer
  layer.setStyle(highlightStyleFor(layer))
  layer.bringToFront()
}

// Binds the tag popup and tracks it in openPopupTarget so a layer rebuild can
// re-open it on the new layer. The popupclose handler only clears the target
// when the user closed this popup (not when the rebuild removed the layer).
function bindFeaturePopup(path: L.Path, feature: Feature): void {
  path.bindPopup(() => osmPopupContent(feature, popupDeps))
  path.on('popupclose', () => {
    if (!rebuildingLayers && openPopupTarget?.kind === 'osm' && openPopupTarget.feature === feature) {
      openPopupTarget = undefined
    }
  })
}

function bindNodePopup(marker: L.CircleMarker, node: UserNode): void {
  marker.bindPopup(() => tagTable(node.tags))
  marker.on('popupclose', () => {
    if (!rebuildingLayers && openPopupTarget?.kind === 'userNode' && openPopupTarget.node.providerNodeId === node.providerNodeId) {
      openPopupTarget = undefined
    }
  })
}

// Re-opens the tracked OSM popup after a rebuild. Feature identity is stable
// across renders because the layers reference the indexed feature objects.
// The target is dropped when the feature is no longer rendered, so a popup
// does not resurface when the user pans back to it later.
function reopenOsmPopup(): void {
  if (openPopupTarget?.kind !== 'osm') {
    return
  }
  const target = openPopupTarget
  let reopened = false
  osmLayer?.eachLayer((layer) => {
    if ((layer as { feature?: Feature }).feature === target.feature) {
      const path = layer as L.Path
      if (!path.getPopup()) {
        bindFeaturePopup(path, target.feature)
      }
      path.openPopup()
      reopened = true
    }
  })
  if (!reopened) {
    openPopupTarget = undefined
  }
}

function renderVisibleFeatures(): void {
  if (!map || osmFeatures.length === 0) {
    return
  }

  const bounds = map.getBounds().pad(0.1)
  const minLon = bounds.getWest()
  const minLat = bounds.getSouth()
  const maxLon = bounds.getEast()
  const maxLat = bounds.getNorth()

  let visible = osmFeatures.filter(
    (f) => f.maxLon >= minLon && f.minLon <= maxLon && f.maxLat >= minLat && f.minLat <= maxLat,
  )

  if (visible.length > MAX_VISIBLE_FEATURES) {
    visible = visible.filter((f) => !isMinorFeature(f.feature))
  }

  // Draw big areas first and small features last. The canvas renderer picks
  // the topmost hit, so elements inside an area (a building in a park) stay
  // clickable instead of the surrounding area swallowing the click; it also
  // stacks small features visually above large areas.
  visible.sort((a, b) => bboxArea(b) - bboxArea(a))

  // Removing a layer closes its popup; the flag keeps openPopupTarget alive
  // so the popup can be re-opened on the rebuilt layer below.
  rebuildingLayers = true
  osmLayer?.remove()
  highlightedLayer = undefined
  rebuildingLayers = false
  osmLayer = L.geoJSON(visible.map((f) => f.feature), {
    style: (feature) => styleFor(feature as Feature),
    pointToLayer: (_feature, latlng) => L.circleMarker(latlng, POINT_MARKER_OPTIONS),
    onEachFeature: (feature, layer) => {
      const path = layer as L.Path
      const f = feature as Feature
      const featureProps = (f.properties ?? {}) as Record<string, string>

      // Landuse areas (e.g. residential) cover whole neighbourhoods and would
      // swallow every click inside them; let those clicks pass through to the
      // features they contain.
      if ('landuse' in featureProps) {
        path.options.interactive = false
        return
      }

      enableAreaHitTest(path, f)

      if (highlightedFeature === f) {
        highlightedLayer = path
        path.setStyle(highlightStyleFor(path))
        path.bringToFront()
      }

      path.on('click', (e: L.LeafletMouseEvent) => {
        setHighlighted(f, path)

        // In edit mode, clicking any feature opens the editor for its user
        // node; otherwise the click shows the read-only tag popup. Features
        // without an id (none in the generated data) always show the popup.
        if (props.editMode && f.id !== undefined && f.id !== null) {
          const [lon, lat] =
            f.geometry.type === 'Point'
              ? (f.geometry.coordinates as [number, number])
              : [e.latlng.lng, e.latlng.lat]
          emit('edit-node', {
            providerNodeId: String(f.id),
            latitude: lat,
            longitude: lon,
            osmTags: featureProps,
          })
          return
        }

        // Popup content is built lazily (Leaflet calls the function on open)
        // because pointsInside scans the feature index per click.
        if (!path.getPopup()) {
          bindFeaturePopup(path, f)
        }
        openPopupTarget = { kind: 'osm', feature: f }
        path.openPopup()
      })
    },
  }).addTo(map)

  // Rebuild the user-node markers after the OSM layer so they are drawn (and
  // hit-tested) last on the shared canvas renderer.
  renderUserNodes()

  // The rebuild above closed the open popup (Leaflet closes a popup when its
  // source layer is removed); re-open it on the new layer.
  reopenOsmPopup()
}

function scheduleRenderVisibleFeatures(): void {
  if (renderTimer) {
    clearTimeout(renderTimer)
  }
  renderTimer = setTimeout(renderVisibleFeatures, MOVE_RENDER_DEBOUNCE_MS)
}

function renderUserNodes(): void {
  if (!map) {
    return
  }

  // Removing a layer closes its popup; the flag keeps openPopupTarget alive so
  // the popup can be re-opened on the rebuilt marker below.
  rebuildingLayers = true
  userNodeLayer?.remove()
  rebuildingLayers = false
  userNodeLayer = L.layerGroup().addTo(map)

  let reopenedUserNode = false
  for (const node of userNodes.nodes) {
    const marker = L.circleMarker([node.latitude, node.longitude], {
      radius: 6,
      color: '#2e7d32',
      weight: 2,
      fillColor: '#66bb6a',
      fillOpacity: 0.9,
    })

    marker.on('click', () => {
      if (props.editMode) {
        emit('edit-node', {
          providerNodeId: node.providerNodeId,
          latitude: node.latitude,
          longitude: node.longitude,
          osmTags: osmTagsById.get(node.providerNodeId) ?? {},
        })
        return
      }
      if (!marker.getPopup()) {
        bindNodePopup(marker, node)
      }
      openPopupTarget = { kind: 'userNode', node }
      marker.openPopup()
    })

    marker.addTo(userNodeLayer)

    // The layer rebuild above closed the popup; re-open it on the new marker.
    // Nodes are matched by provider id because saving an edit can replace the
    // node object.
    if (openPopupTarget?.kind === 'userNode' && openPopupTarget.node.providerNodeId === node.providerNodeId) {
      if (!marker.getPopup()) {
        bindNodePopup(marker, node)
      }
      marker.openPopup()
      reopenedUserNode = true
    }
  }

  // The node was deleted; drop the target so its popup does not resurface.
  if (!reopenedUserNode && openPopupTarget?.kind === 'userNode') {
    openPopupTarget = undefined
  }
}

watch(
  () => userNodes.nodes,
  () => renderUserNodes(),
)

async function loadOsmFeatures(): Promise<void> {
  const data = await loadOsmData()
  if (!data) {
    return
  }
  osmFeatures = data.features
  osmTagsById = data.tagsById
  renderVisibleFeatures()
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

  map.on('moveend', scheduleRenderVisibleFeatures)
  void loadOsmFeatures()
  void userNodes.load()

  locationLayers = locateUser(() => map, (status) => emit('status', status))
})

onBeforeUnmount(() => {
  if (renderTimer) {
    clearTimeout(renderTimer)
  }
  osmLayer?.remove()
  userNodeLayer?.remove()
  locationLayers.accuracyCircle?.remove()
  locationLayers.marker?.remove()
  map?.remove()
  map = undefined
  osmLayer = undefined
  userNodeLayer = undefined
  locationLayers = {}
  highlightedFeature = undefined
  highlightedLayer = undefined
  openPopupTarget = undefined
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
