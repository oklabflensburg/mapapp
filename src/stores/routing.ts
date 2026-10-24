import { ref } from 'vue'
import { defineStore } from 'pinia'
import { useSettingsStore } from './settings'

export interface RoutePoint {
  latitude: number
  longitude: number
}

export interface RouteResult {
  // Meters.
  distance: number
  // Seconds.
  time: number
  // [lat, lng] pairs along the route.
  points: [number, number][]
}

// Walking routes through the backend's GraphHopper proxy.
export const useRoutingStore = defineStore('routing', () => {
  const mode = ref(false)
  const start = ref<RoutePoint | null>(null)
  const end = ref<RoutePoint | null>(null)
  const route = ref<RouteResult | null>(null)
  const loading = ref(false)
  const failed = ref(false)
  const noRoute = ref(false)

  function clear(): void {
    start.value = null
    end.value = null
    route.value = null
    failed.value = false
    noRoute.value = false
  }

  function enter(): void {
    mode.value = true
  }

  function exit(): void {
    mode.value = false
    clear()
  }

  function toggle(): void {
    if (mode.value) {
      exit()
    } else {
      enter()
    }
  }

  // In routing mode each map click places the start point, then the end
  // point; a further click begins a new route.
  function setPoint(point: RoutePoint): void {
    if (!mode.value || loading.value) return
    if (start.value && end.value) {
      clear()
    }
    if (start.value) {
      end.value = point
      void requestRoute()
    } else {
      start.value = point
    }
  }

  async function requestRoute(): Promise<void> {
    if (!start.value || !end.value) return
    loading.value = true
    failed.value = false
    noRoute.value = false
    route.value = null
    try {
      const query = new URLSearchParams({
        fromLat: String(start.value.latitude),
        fromLon: String(start.value.longitude),
        toLat: String(end.value.latitude),
        toLon: String(end.value.longitude),
        // The profile is a settings choice: shortest or wheelchair-friendly.
        profile: useSettingsStore().routingProfile,
      })
      const response = await fetch(`/api/route?${query}`)
      if (response.status === 422) {
        noRoute.value = true
        return
      }
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`)
      }
      route.value = (await response.json()) as RouteResult
    } catch (error) {
      console.warn('Failed to load route', error)
      failed.value = true
    } finally {
      loading.value = false
    }
  }

  return { mode, start, end, route, loading, failed, noRoute, enter, exit, toggle, setPoint }
})
