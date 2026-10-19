<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import * as L from 'leaflet'
import type { Feature, FeatureCollection, Geometry } from 'geojson'
import 'leaflet/dist/leaflet.css'
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png'
import markerIcon from 'leaflet/dist/images/marker-icon.png'
import markerShadow from 'leaflet/dist/images/marker-shadow.png'

export type MapStatus = 'locating' | 'unsupported' | 'unavailable' | 'found'

const emit = defineEmits<{
  (event: 'status', status: MapStatus): void
}>()

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

// Currently selected feature and its rendered layer. The layer reference is
// rebuilt on every render, so the feature is the source of truth.
let highlightedFeature: Feature | undefined
let highlightedLayer: L.Path | undefined

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

function popupContent(feature: Feature): string {
  const props = (feature.properties ?? {}) as Record<string, string>
  const rows = Object.entries(props)
    .map(([key, value]) => `<tr><th>${escapeHtml(key)}</th><td>${escapeHtml(String(value))}</td></tr>`)
    .join('')
  return `<table class="osm-tags">${rows}</table>`
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

  osmLayer?.remove()
  highlightedLayer = undefined
  osmLayer = L.geoJSON(visible.map((f) => f.feature), {
    style: (feature) => styleFor(feature as Feature),
    pointToLayer: (feature, latlng) =>
      L.circleMarker(latlng, {
        radius: 4,
        color: '#b06fd4',
        weight: 1,
        fillColor: '#e3c9f0',
        fillOpacity: 0.8,
      }).bindPopup(popupContent(feature as Feature)),
    onEachFeature: (feature, layer) => {
      const path = layer as L.Path
      const f = feature as Feature

      if (f.geometry.type !== 'Point') {
        layer.bindPopup(popupContent(f))
      }

      if (highlightedFeature === f) {
        highlightedLayer = path
        path.setStyle(highlightStyleFor(path))
        path.bringToFront()
      }

      path.on('click', () => {
        setHighlighted(f, path)
      })
    },
  }).addTo(map)
}

function scheduleRenderVisibleFeatures(): void {
  if (renderTimer) {
    clearTimeout(renderTimer)
  }
  renderTimer = setTimeout(renderVisibleFeatures, MOVE_RENDER_DEBOUNCE_MS)
}

async function loadOsmFeatures(): Promise<void> {
  try {
    const response = await fetch(OSM_DATA_URL)
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`)
    }
    const collection = (await response.json()) as FeatureCollection
    osmFeatures = indexFeatures(collection)
    renderVisibleFeatures()
  } catch (error) {
    console.warn('Failed to load OSM features', error)
  }
}

onMounted(() => {
  if (!container.value) {
    return
  }

  map = L.map(container.value, { preferCanvas: true }).setView(DEFAULT_CENTER, DEFAULT_ZOOM)

  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution:
      '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
  }).addTo(map)

  map.on('moveend', scheduleRenderVisibleFeatures)
  void loadOsmFeatures()

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
  accuracyCircle?.remove()
  marker?.remove()
  map?.remove()
  map = undefined
  marker = undefined
  accuracyCircle = undefined
  osmLayer = undefined
  highlightedFeature = undefined
  highlightedLayer = undefined
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
</style>
