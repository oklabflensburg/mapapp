import * as L from 'leaflet'
import type { NodeEditTarget, UserNode } from '../stores/userNodes'
import { tagTable } from './popupContent'
import type { PopupTracker } from './popupTracker'

export interface UserNodeLayerDeps {
  map: L.Map
  isEditMode: () => boolean
  onEditNode: (target: NodeEditTarget) => void
  // Original OSM tags of the node a user node references; empty when the
  // user node has no matching OSM node.
  getOsmTags: (providerNodeId: string) => Record<string, string>
  popupTracker: PopupTracker
}

const USER_NODE_MARKER_OPTIONS: L.CircleMarkerOptions = {
  radius: 6,
  color: '#2e7d32',
  weight: 2,
  fillColor: '#66bb6a',
  fillOpacity: 0.9,
}

// Renders the saved user nodes as markers on top of the OSM layer, with tag
// popups and edit-mode click handling. Rebuilt after every OSM layer render
// (so the markers are drawn and hit-tested last on the shared canvas
// renderer) and whenever the store changes.
export class UserNodeLayer {
  private readonly deps: UserNodeLayerDeps
  private layer: L.LayerGroup | undefined

  constructor(deps: UserNodeLayerDeps) {
    this.deps = deps
  }

  render(nodes: UserNode[]): void {
    // Removing a layer closes its popup; the tracker keeps the target alive
    // so the popup can be re-opened on the rebuilt marker below.
    this.deps.popupTracker.runWithoutClearing(() => {
      this.layer?.remove()
    })
    this.layer = L.layerGroup().addTo(this.deps.map)

    let reopened = false
    for (const node of nodes) {
      const marker = L.circleMarker([node.latitude, node.longitude], USER_NODE_MARKER_OPTIONS)

      marker.on('click', () => {
        // In edit mode, clicking a marker opens the editor for the node;
        // otherwise the click shows the read-only tag popup.
        if (this.deps.isEditMode()) {
          this.deps.onEditNode({
            providerNodeId: node.providerNodeId,
            latitude: node.latitude,
            longitude: node.longitude,
            osmTags: this.deps.getOsmTags(node.providerNodeId),
          })
          return
        }
        if (!marker.getPopup()) {
          this.bindPopup(marker, node)
        }
        this.deps.popupTracker.track({ kind: 'userNode', providerNodeId: node.providerNodeId })
        marker.openPopup()
      })

      marker.addTo(this.layer)

      // The render above closed the popup; re-open it on the new marker.
      // Nodes are matched by provider id because saving an edit can replace
      // the node object.
      const current = this.deps.popupTracker.current
      if (current?.kind === 'userNode' && current.providerNodeId === node.providerNodeId) {
        if (!marker.getPopup()) {
          this.bindPopup(marker, node)
        }
        marker.openPopup()
        reopened = true
      }
    }

    // The node was deleted; drop the target so its popup does not resurface.
    if (!reopened && this.deps.popupTracker.current?.kind === 'userNode') {
      this.deps.popupTracker.clear()
    }
  }

  destroy(): void {
    this.layer?.remove()
    this.layer = undefined
  }

  private bindPopup(marker: L.CircleMarker, node: UserNode): void {
    marker.bindPopup(() => tagTable(node.tags))
    marker.on('popupclose', () => {
      this.deps.popupTracker.handleClose({ kind: 'userNode', providerNodeId: node.providerNodeId })
    })
  }
}
