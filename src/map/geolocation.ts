import * as L from 'leaflet'
import { LOCATION_ZOOM } from './mapConfig'

export type MapStatus = 'locating' | 'unsupported' | 'unavailable' | 'found'

// Layers created once the position arrives; the caller removes them on
// teardown. The map is accessed through a getter because the callback can
// fire after the component unmounted.
export interface LocationLayers {
  marker?: L.Marker
  accuracyCircle?: L.Circle
}

export function locateUser(getMap: () => L.Map | undefined, onStatus: (status: MapStatus) => void, layers: LocationLayers = {}): LocationLayers {
  // A repeated call replaces the previous fix instead of stacking markers.
  layers.marker?.remove()
  layers.accuracyCircle?.remove()
  layers.marker = undefined
  layers.accuracyCircle = undefined

  if (!('geolocation' in navigator)) {
    onStatus('unsupported')
    return layers
  }

  onStatus('locating')

  navigator.geolocation.getCurrentPosition(
    (position) => {
      const map = getMap()
      if (!map) {
        return
      }

      const center: L.LatLngExpression = [position.coords.latitude, position.coords.longitude]
      map.setView(center, LOCATION_ZOOM)
      layers.marker = L.marker(center).addTo(map)
      layers.accuracyCircle = L.circle(center, { radius: position.coords.accuracy }).addTo(map)

      onStatus('found')
    },
    () => {
      onStatus('unavailable')
    },
    {
      enableHighAccuracy: true,
      timeout: 10_000,
      maximumAge: 30_000,
    },
  )

  return layers
}
