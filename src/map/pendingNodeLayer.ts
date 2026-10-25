import * as L from 'leaflet'

interface PendingNodeMarker {
  latitude: number
  longitude: number
}

const PENDING_NODE_MARKER_OPTIONS: L.CircleMarkerOptions = {
  radius: 6,
  color: '#2e7d32',
  weight: 2,
  fillColor: '#66bb6a',
  fillOpacity: 0.4,
  // Dashes mark the node as placed but not yet saved.
  dashArray: '3 3',
}

// Renders nodes placed in edit mode that have not been saved yet. Rebuilt
// with the other layers so the markers stay on top of the shared canvas
// renderer.
export class PendingNodeLayer {
  private readonly map: L.Map
  private layer: L.LayerGroup | undefined

  constructor(map: L.Map) {
    this.map = map
  }

  render(nodes: PendingNodeMarker[]): void {
    this.layer?.remove()
    this.layer = L.layerGroup().addTo(this.map)
    for (const node of nodes) {
      L.circleMarker([node.latitude, node.longitude], PENDING_NODE_MARKER_OPTIONS).addTo(this.layer)
    }
  }

  destroy(): void {
    this.layer?.remove()
    this.layer = undefined
  }
}
