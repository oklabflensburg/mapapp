import type { Feature } from 'geojson'
import * as L from 'leaflet'

// Leaflet renders paths on a canvas (preferCanvas), which cannot resolve CSS
// custom properties, so PrimeVue design tokens are read from the computed
// root style instead.
export function designToken(name: string): string {
  return getComputedStyle(document.documentElement).getPropertyValue(name).trim()
}

export function styleFor(feature: Feature): L.PathOptions {
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

export const POINT_MARKER_OPTIONS: L.CircleMarkerOptions = {
  radius: 4,
  color: '#b06fd4',
  weight: 1,
  fillColor: '#e3c9f0',
  fillOpacity: 0.8,
}

export function highlightStyleFor(layer: L.Path): L.PathOptions {
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
