<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import * as L from 'leaflet'
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

const DEFAULT_CENTER: L.LatLngExpression = [51.1657, 10.4515]
const DEFAULT_ZOOM = 6
const LOCATION_ZOOM = 16

onMounted(() => {
  if (!container.value) {
    return
  }

  map = L.map(container.value).setView(DEFAULT_CENTER, DEFAULT_ZOOM)

  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution:
      '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
  }).addTo(map)

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
  accuracyCircle?.remove()
  marker?.remove()
  map?.remove()
  map = undefined
  marker = undefined
  accuracyCircle = undefined
})
</script>

<template>
  <div ref="container" class="map" />
</template>

<style scoped>
.map {
  width: 100%;
  height: 60vh;
  border-radius: 8px;
  z-index: 0;
}
</style>
