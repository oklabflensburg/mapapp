import * as L from 'leaflet'
import type { NodeEditTarget, UserNode } from '../stores/userNodes'

export interface UserNodeLayerDeps {
  map: L.Map
  onEditNode: (target: NodeEditTarget, click: L.LatLng) => void
  // Original OSM tags of the node a user node references; empty when the
  // user node has no matching OSM node.
  getOsmTags: (providerNodeId: string) => Record<string, string>
}

const USER_NODE_MARKER_OPTIONS: L.CircleMarkerOptions = {
  radius: 6,
  color: '#2e7d32',
  weight: 2,
  fillColor: '#66bb6a',
  fillOpacity: 0.9,
}

// Renders the saved user nodes as markers on top of the OSM layer. Rebuilt
// after every OSM layer render (so the markers are drawn and hit-tested last
// on the shared canvas renderer) and whenever the store changes.
export class UserNodeLayer {
  private readonly deps: UserNodeLayerDeps
  private layer: L.LayerGroup | undefined

  constructor(deps: UserNodeLayerDeps) {
    this.deps = deps
  }

  render(nodes: UserNode[]): void {
    this.layer?.remove()
    this.layer = L.layerGroup().addTo(this.deps.map)

    // API results are ordered by last edit, which must not determine hit
    // priority. Draw newer nodes last so a child stays above its parent.
    const ordered = [...nodes].sort((a, b) =>
      Date.parse(a.createdAt) - Date.parse(b.createdAt) || a.id - b.id,
    )
    for (const node of ordered) {
      const marker = L.circleMarker([node.latitude, node.longitude], USER_NODE_MARKER_OPTIONS)

      // Clicking a marker selects it (also when it is already selected);
      // only clicking somewhere else deselects.
      marker.on('click', (event: L.LeafletMouseEvent) => {
        this.deps.onEditNode({
          id: node.id,
          provider: node.provider,
          providerNodeId: node.providerNodeId,
          latitude: node.latitude,
          longitude: node.longitude,
          osmTags: node.provider === 'OSM' ? this.deps.getOsmTags(node.providerNodeId) : {},
        }, this.deps.map.mouseEventToLatLng(event.originalEvent))
      })

      marker.addTo(this.layer)
    }
  }

  bringToFront(): void {
    this.layer?.eachLayer((layer) => {
      if (layer instanceof L.Path) layer.bringToFront()
    })
  }

  destroy(): void {
    this.layer?.remove()
    this.layer = undefined
  }
}
