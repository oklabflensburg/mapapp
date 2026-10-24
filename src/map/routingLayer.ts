import * as L from 'leaflet'
import type { RoutePoint, RouteResult } from '../stores/routing'

const ROUTE_LINE_OPTIONS: L.PolylineOptions = {
  color: '#0d9488',
  weight: 5,
  opacity: 0.85,
}

const START_MARKER_OPTIONS: L.CircleMarkerOptions = {
  radius: 7,
  color: '#166534',
  weight: 3,
  fillColor: '#22c55e',
  fillOpacity: 1,
}

const END_MARKER_OPTIONS: L.CircleMarkerOptions = {
  radius: 7,
  color: '#991b1b',
  weight: 3,
  fillColor: '#ef4444',
  fillOpacity: 1,
}

// Renders the routing mode state: the found route under the start/end
// markers. Rebuilt after every OSM layer render so the route stays on top
// of the shared canvas renderer.
export class RoutingLayer {
  private readonly map: L.Map
  private layer: L.LayerGroup | undefined

  constructor(map: L.Map) {
    this.map = map
  }

  render(start: RoutePoint | null, end: RoutePoint | null, route: RouteResult | null): void {
    this.layer?.remove()
    this.layer = L.layerGroup().addTo(this.map)
    if (route) {
      L.polyline(route.points, ROUTE_LINE_OPTIONS).addTo(this.layer)
    }
    if (start) {
      L.circleMarker([start.latitude, start.longitude], START_MARKER_OPTIONS).addTo(this.layer)
    }
    if (end) {
      L.circleMarker([end.latitude, end.longitude], END_MARKER_OPTIONS).addTo(this.layer)
    }
  }

  destroy(): void {
    this.layer?.remove()
    this.layer = undefined
  }
}
