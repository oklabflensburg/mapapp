import type { Feature } from 'geojson'

// Popup whose source layer is currently open. Every render rebuilds the
// layers, and Leaflet closes a popup when its source layer is removed, so
// the popup is re-opened on the new layer after each rebuild. OSM features
// are matched by feature identity, user nodes by provider id (saving an
// edit can replace the node object).
export type PopupTarget = { kind: 'osm'; feature: Feature } | { kind: 'userNode'; providerNodeId: string }

// Tracks which popup is open across layer rebuilds. The OSM layer and the
// user-node layer are rebuilt independently; each keeps the tracked target
// alive while removing its own layers and re-opens the popup on its freshly
// created layer when the tracked source is rendered again.
export class PopupTracker {
  private target: PopupTarget | undefined
  // True while layers are being rebuilt; popupclose events fired by the
  // rebuild itself must not clear the target.
  private rebuilding = false

  get current(): PopupTarget | undefined {
    return this.target
  }

  track(target: PopupTarget): void {
    this.target = target
  }

  // Clears the target only when the user closed the popup, not when a layer
  // rebuild removed its source layer.
  handleClose(source: PopupTarget): void {
    if (!this.rebuilding && this.matches(source)) {
      this.target = undefined
    }
  }

  // Removing a layer closes its popup; while a rebuild is in progress those
  // close events must not clear the tracked target.
  runWithoutClearing(rebuild: () => void): void {
    this.rebuilding = true
    try {
      rebuild()
    } finally {
      this.rebuilding = false
    }
  }

  clear(): void {
    this.target = undefined
  }

  private matches(candidate: PopupTarget): boolean {
    const current = this.target
    if (current?.kind === 'osm' && candidate.kind === 'osm') {
      return current.feature === candidate.feature
    }
    if (current?.kind === 'userNode' && candidate.kind === 'userNode') {
      return current.providerNodeId === candidate.providerNodeId
    }
    return false
  }
}
