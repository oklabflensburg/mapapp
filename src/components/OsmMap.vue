<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import * as L from 'leaflet'
import type { Feature, FeatureCollection, Geometry } from 'geojson'
import 'leaflet/dist/leaflet.css'
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png'
import markerIcon from 'leaflet/dist/images/marker-icon.png'
import markerShadow from 'leaflet/dist/images/marker-shadow.png'
import { useUserNodesStore } from '../stores/userNodes'
import type { UserNode } from '../stores/userNodes'
import type { NodeEditTarget } from './NodeEditorDialog.vue'

export type MapStatus = 'locating' | 'unsupported' | 'unavailable' | 'found'

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
let marker: L.Marker | undefined
let accuracyCircle: L.Circle | undefined

// Bundle-resolved marker icons (Leaflet's defaults reference image URLs that
// break with bundlers).
L.Icon.Default.mergeOptions({
  iconRetinaUrl: markerIcon2x,
  iconUrl: markerIcon,
  shadowUrl: markerShadow,
})

const DEFAULT_CENTER: L.LatLngExpression = [54.791, 9.437] // Flensburg
const DEFAULT_ZOOM = 14
const LOCATION_ZOOM = 16

const OSM_DATA_URL = '/data/flensburg.geojson'
// Above this many visible features, buildings and points are dropped until
// zooming in; streets, parks and landuse stay visible at every zoom.
const MAX_VISIBLE_FEATURES = 20_000
const MOVE_RENDER_DEBOUNCE_MS = 150

interface IndexedFeature {
  feature: Feature
  minLon: number
  minLat: number
  maxLon: number
  maxLat: number
}

let osmFeatures: IndexedFeature[] = []
let osmLayer: L.GeoJSON | undefined
let renderTimer: ReturnType<typeof setTimeout> | undefined

// User nodes (saved edits) rendered on top of the OSM layer.
const userNodes = useUserNodesStore()

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

function indexGeometry(geometry: Geometry, bbox: { minLon: number; minLat: number; maxLon: number; maxLat: number }): void {
  switch (geometry.type) {
    case 'Point':
      bbox.minLon = Math.min(bbox.minLon, geometry.coordinates[0])
      bbox.maxLon = Math.max(bbox.maxLon, geometry.coordinates[0])
      bbox.minLat = Math.min(bbox.minLat, geometry.coordinates[1])
      bbox.maxLat = Math.max(bbox.maxLat, geometry.coordinates[1])
      break
    case 'MultiPoint':
    case 'LineString':
      for (const [lon, lat] of geometry.coordinates) {
        bbox.minLon = Math.min(bbox.minLon, lon)
        bbox.maxLon = Math.max(bbox.maxLon, lon)
        bbox.minLat = Math.min(bbox.minLat, lat)
        bbox.maxLat = Math.max(bbox.maxLat, lat)
      }
      break
    case 'MultiPolygon':
    case 'Polygon':
      for (const ring of geometry.coordinates as number[][][]) {
        for (const [lon, lat] of ring) {
          bbox.minLon = Math.min(bbox.minLon, lon)
          bbox.maxLon = Math.max(bbox.maxLon, lon)
          bbox.minLat = Math.min(bbox.minLat, lat)
          bbox.maxLat = Math.max(bbox.maxLat, lat)
        }
      }
      break
  }
}

function indexFeatures(collection: FeatureCollection): IndexedFeature[] {
  return collection.features.map((feature) => {
    const bbox = { minLon: Infinity, minLat: Infinity, maxLon: -Infinity, maxLat: -Infinity }
    indexGeometry(feature.geometry, bbox)
    return { feature, ...bbox }
  })
}

function isMinorFeature(feature: Feature): boolean {
  return feature.geometry.type === 'Point' || 'building' in (feature.properties ?? {})
}

function bboxArea(feature: IndexedFeature): number {
  return (feature.maxLon - feature.minLon) * (feature.maxLat - feature.minLat)
}

// Leaflet internals used for hit-testing; not part of the public typings.
interface HitTestablePath {
  _containsPoint(point: L.Point): boolean
  _rings: L.Point[][]
}

// Closed ways without a highway tag enclose an area (buildings, landuse,
// natural, ...) but render as outlines only, so Leaflet would hit-test them
// on the stroke. Highway loops are paths, not areas, and stay stroke-only.
function isAreaOutline(feature: Feature): boolean {
  if (feature.geometry.type !== 'LineString') {
    return false
  }
  const coords = feature.geometry.coordinates
  const first = coords[0]
  const last = coords[coords.length - 1]
  return (
    coords.length > 3 &&
    first[0] === last[0] &&
    first[1] === last[1] &&
    !('highway' in (feature.properties ?? {}))
  )
}

// Even-odd ray casting against a projected ring, matching the test Leaflet
// applies to polygons.
function pointInRing(point: L.Point, ring: L.Point[]): boolean {
  let inside = false
  for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
    const a = ring[j]
    const b = ring[i]
    if ((b.y > point.y) !== (a.y > point.y) && point.x < ((a.x - b.x) * (point.y - b.y)) / (a.y - b.y) + b.x) {
      inside = !inside
    }
  }
  return inside
}

function styleFor(feature: Feature): L.PathOptions {
  const props = (feature.properties ?? {}) as Record<string, string>

  if (props.building) {
    return { color: '#9c8f7f', weight: 0.5, fillColor: '#d9d0c9', fillOpacity: 0.7 }
  }
  if (props.leisure === 'park' || props.leisure === 'garden' || props.leisure === 'playground' ||
      props.landuse === 'forest' || props.landuse === 'grass' || props.landuse === 'recreation_ground' ||
      props.natural === 'wood' || props.natural === 'scrub') {
    return { color: '#4a7a3a', weight: 0.5, fillColor: '#a8d5a2', fillOpacity: 0.6 }
  }
  if (props.natural === 'water' || props.waterway) {
    return { color: '#3a7ab8', weight: 1, fillColor: '#aadaff', fillOpacity: 0.7 }
  }
  if (props.highway) {
    return { color: '#888888', weight: 1.5, opacity: 0.8 }
  }
  return { color: '#b06fd4', weight: 1, fillColor: '#e3c9f0', fillOpacity: 0.5 }
}

function escapeHtml(value: string): string {
  return value
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
}

function tagTable(props: Record<string, string>): string {
  const rows = Object.entries(props)
    .map(([key, value]) => `<tr><th>${escapeHtml(key)}</th><td>${escapeHtml(String(value))}</td></tr>`)
    .join('')
  return `<table class="osm-tags">${rows}</table>`
}

// Even-odd test in lon/lat; testing all rings together handles holes.
function pointInRings(lon: number, lat: number, rings: number[][][]): boolean {
  let inside = false
  for (const ring of rings) {
    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
      const [xi, yi] = ring[i]
      const [xj, yj] = ring[j]
      if ((yi > lat) !== (yj > lat) && lon < ((xj - xi) * (lat - yi)) / (yj - yi) + xi) {
        inside = !inside
      }
    }
  }
  return inside
}

// Shops and other POIs are usually separate point nodes inside the building
// way, which itself often carries only building=yes. Collect them so the
// building popup can show what is inside.
function pointsInside(feature: Feature): Feature[] {
  if (!('building' in (feature.properties ?? {}))) {
    return []
  }

  const geometry = feature.geometry
  let rings: number[][][]
  if (geometry.type === 'Polygon') {
    rings = geometry.coordinates
  } else if (geometry.type === 'MultiPolygon') {
    rings = geometry.coordinates.flat()
  } else if (geometry.type === 'LineString' && isAreaOutline(feature)) {
    rings = [geometry.coordinates]
  } else {
    return []
  }

  let minLon = Infinity
  let minLat = Infinity
  let maxLon = -Infinity
  let maxLat = -Infinity
  for (const ring of rings) {
    for (const [lon, lat] of ring) {
      minLon = Math.min(minLon, lon)
      maxLon = Math.max(maxLon, lon)
      minLat = Math.min(minLat, lat)
      maxLat = Math.max(maxLat, lat)
    }
  }

  return osmFeatures
    .filter(
      (indexed) =>
        indexed.feature.geometry.type === 'Point' &&
        indexed.minLon >= minLon &&
        indexed.maxLon <= maxLon &&
        indexed.minLat >= minLat &&
        indexed.maxLat <= maxLat &&
        pointInRings(indexed.minLon, indexed.minLat, rings),
    )
    .map((indexed) => indexed.feature)
}

// Section listing the custom tags the user saved for the feature with this
// provider id; empty when the feature has no user node (or no tags).
function customTagsSection(providerNodeId: string | number | null | undefined): string {
  if (providerNodeId === null || providerNodeId === undefined) {
    return ''
  }
  const node = userNodes.byProviderNodeId.get(String(providerNodeId))
  if (!node || Object.keys(node.tags).length === 0) {
    return ''
  }
  return `<section><h3 class="osm-poi-title">${escapeHtml(t('nodeEditor.customTags'))}</h3>${tagTable(node.tags)}</section>`
}

function popupContent(feature: Feature): string {
  const props = (feature.properties ?? {}) as Record<string, string>
  const poiSections = pointsInside(feature)
    .map((poi) => {
      const poiProps = (poi.properties ?? {}) as Record<string, string>
      const title =
        poiProps.name || poiProps.shop || poiProps.amenity || poiProps.office || poiProps.tourism || poiProps.leisure || 'POI'
      return `<section><h3 class="osm-poi-title">${escapeHtml(title)}</h3>${tagTable(poiProps)}${customTagsSection(poi.id)}</section>`
    })
    .join('')
  return tagTable(props) + customTagsSection(feature.id) + poiSections
}

// Leaflet renders paths on a canvas (preferCanvas), which cannot resolve CSS
// custom properties, so PrimeVue design tokens are read from the computed
// root style instead.
function designToken(name: string): string {
  return getComputedStyle(document.documentElement).getPropertyValue(name).trim()
}

function highlightStyleFor(layer: L.Path): L.PathOptions {
  const primary = designToken('--p-primary-color')
  if (layer instanceof L.CircleMarker) {
    const circleStyle: L.CircleMarkerOptions = {
      color: primary,
      weight: 2,
      fillColor: primary,
      fillOpacity: 1,
      radius: 6,
    }
    return circleStyle
  }
  return { color: primary, weight: 5, opacity: 1 }
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
  path.bindPopup(() => popupContent(feature))
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
    pointToLayer: (_feature, latlng) =>
      L.circleMarker(latlng, {
        radius: 4,
        color: '#b06fd4',
        weight: 1,
        fillColor: '#e3c9f0',
        fillOpacity: 0.8,
      }),
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

      if (isAreaOutline(f)) {
        const hitPath = path as unknown as HitTestablePath
        const containsOnStroke = hitPath._containsPoint.bind(hitPath)
        hitPath._containsPoint = (point) =>
          containsOnStroke(point) || (hitPath._rings.length > 0 && pointInRing(point, hitPath._rings[0]))
      }

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
  try {
    const response = await fetch(OSM_DATA_URL)
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`)
    }
    const collection = (await response.json()) as FeatureCollection
    osmFeatures = indexFeatures(collection)
    osmTagsById = new Map(
      collection.features
        .filter((f) => f.id !== undefined && f.id !== null && f.geometry.type === 'Point')
        .map((f) => [String(f.id), (f.properties ?? {}) as Record<string, string>]),
    )
    renderVisibleFeatures()
  } catch (error) {
    console.warn('Failed to load OSM features', error)
  }
}

onMounted(() => {
  if (!container.value) {
    return
  }

  // Canvas hit-testing is exact by default, so thin lines (e.g. 1.5px roads)
  // would need pixel-perfect clicks. tolerance widens the hit area around
  // every path without changing how it is drawn.
  map = L.map(container.value, { renderer: L.canvas({ tolerance: 10 }) }).setView(DEFAULT_CENTER, DEFAULT_ZOOM)

  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution:
      '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
  }).addTo(map)

  map.on('moveend', scheduleRenderVisibleFeatures)
  void loadOsmFeatures()
  void userNodes.load()

  if (!('geolocation' in navigator)) {
    emit('status', 'unsupported')
    return
  }

  emit('status', 'locating')

  navigator.geolocation.getCurrentPosition(
    (position) => {
      const center: L.LatLngExpression = [position.coords.latitude, position.coords.longitude]

      map?.setView(center, LOCATION_ZOOM)
      marker = L.marker(center).addTo(map as L.Map)
      accuracyCircle = L.circle(center, { radius: position.coords.accuracy }).addTo(map as L.Map)

      emit('status', 'found')
    },
    () => {
      emit('status', 'unavailable')
    },
    {
      enableHighAccuracy: true,
      timeout: 10_000,
      maximumAge: 30_000,
    },
  )
})

onBeforeUnmount(() => {
  if (renderTimer) {
    clearTimeout(renderTimer)
  }
  osmLayer?.remove()
  userNodeLayer?.remove()
  accuracyCircle?.remove()
  marker?.remove()
  map?.remove()
  map = undefined
  marker = undefined
  accuracyCircle = undefined
  osmLayer = undefined
  userNodeLayer = undefined
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
