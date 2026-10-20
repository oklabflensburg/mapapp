import * as L from 'leaflet'
import type { NodeEditTarget, UserNode } from '../stores/userNodes'

export interface UserNodeLayerDeps {
  map: L.Map
  onEditNode: (target: NodeEditTarget) => void
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

    for (const node of nodes) {
      const marker = L.circleMarker([node.latitude, node.longitude], USER_NODE_MARKER_OPTIONS)

      // Clicking a marker selects it (also when it is already selected);
      // only clicking somewhere else deselects.
      marker.on('click', () => {
        this.deps.onEditNode({
          providerNodeId: node.providerNodeId,
          latitude: node.latitude,
          longitude: node.longitude,
          osmTags: this.deps.getOsmTags(node.providerNodeId),
        })
      })

      marker.addTo(this.layer)
    }
  }

  destroy(): void {
    this.layer?.remove()
    this.layer = undefined
  }
}
