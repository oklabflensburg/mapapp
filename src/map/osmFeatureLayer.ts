import type { Feature } from 'geojson'
import * as L from 'leaflet'
import type { NodeEditTarget } from '../stores/userNodes'
import { enableAreaHitTest } from './areaHitTest'
import { bboxArea, isMinorFeature, pointsInside, type IndexedFeature } from './featureIndex'
import { highlightStyleFor, POINT_MARKER_OPTIONS, styleFor } from './featureStyling'
import { MAX_VISIBLE_FEATURES } from './mapConfig'
import { osmPopupContent, type PopupDependencies } from './popupContent'
import type { PopupTracker } from './popupTracker'

// Popup content sources the layer cannot provide itself: the saved user
// nodes and the active locale. pointsInside is derived from the layer's own
// feature index.
export type OsmPopupDeps = Omit<PopupDependencies, 'pointsInside'>

export interface OsmFeatureLayerDeps {
  map: L.Map
  isEditMode: () => boolean
  onEditNode: (target: NodeEditTarget) => void
  popupDeps: OsmPopupDeps
  popupTracker: PopupTracker
}

// Renders the indexed OSM features intersecting the current viewport:
// visibility filtering, draw order (large areas first), highlighting, tag
// popups and edit-mode clicks. The layer is rebuilt on every render, so the
// indexed feature objects are the stable identity across rebuilds.
export class OsmFeatureLayer {
  private readonly deps: OsmFeatureLayerDeps
  private features: IndexedFeature[] = []
  private layer: L.GeoJSON | undefined

  // Currently selected feature and its rendered layer. The layer reference is
  // rebuilt on every render, so the feature is the source of truth.
  private highlightedFeature: Feature | undefined
  private highlightedLayer: L.Path | undefined

  constructor(deps: OsmFeatureLayerDeps) {
    this.deps = deps
  }

  setFeatures(features: IndexedFeature[]): void {
    this.features = features
  }

  render(): void {
    if (this.features.length === 0) {
      return
    }

    const bounds = this.deps.map.getBounds().pad(0.1)
    const minLon = bounds.getWest()
    const minLat = bounds.getSouth()
    const maxLon = bounds.getEast()
    const maxLat = bounds.getNorth()

    let visible = this.features.filter(
      (f) => f.maxLon >= minLon && f.minLon <= maxLon && f.maxLat >= minLat && f.minLat <= maxLat,
    )

    if (visible.length > MAX_VISIBLE_FEATURES) {
      visible = visible.filter((f) => !isMinorFeature(f.feature))
    }

    // Draw big areas first and small features last. The canvas renderer picks
    // the topmost hit, so elements inside an area (a building in a park) stay
    // clickable instead of the surrounding area swallowing the click; it also
    // stacks small features visually above large areas.
    visible.sort((a, b) => bboxArea(b) - bboxArea(a))

    // Removing a layer closes its popup; the tracker keeps the target alive
    // so the popup can be re-opened on the rebuilt layer below.
    this.deps.popupTracker.runWithoutClearing(() => {
      this.layer?.remove()
      this.highlightedLayer = undefined
    })
    this.layer = L.geoJSON(visible.map((f) => f.feature), {
      style: (feature) => styleFor(feature as Feature),
      pointToLayer: (_feature, latlng) => L.circleMarker(latlng, POINT_MARKER_OPTIONS),
      onEachFeature: (feature, layer) => {
        const path = layer as L.Path
        const f = feature as Feature
        const featureProps = (f.properties ?? {}) as Record<string, string>

        // Landuse areas (e.g. residential) cover whole neighbourhoods and would
        // swallow every click inside them; let those clicks pass through to the
        // features they contain.
        if ('landuse' in featureProps) {
          path.options.interactive = false
          return
        }

        enableAreaHitTest(path, f)

        if (this.highlightedFeature === f) {
          this.highlightedLayer = path
          path.setStyle(highlightStyleFor(path))
          path.bringToFront()
        }

        path.on('click', (e: L.LeafletMouseEvent) => {
          this.select(f, path)

          // In edit mode, clicking any feature opens the editor for its user
          // node; otherwise the click shows the read-only tag popup. Features
          // without an id (none in the generated data) always show the popup.
          if (this.deps.isEditMode() && f.id !== undefined && f.id !== null) {
            const [lon, lat] =
              f.geometry.type === 'Point'
                ? (f.geometry.coordinates as [number, number])
                : [e.latlng.lng, e.latlng.lat]
            this.deps.onEditNode({
              providerNodeId: String(f.id),
              latitude: lat,
              longitude: lon,
              osmTags: featureProps,
            })
            return
          }

          // Popup content is built lazily (Leaflet calls the function on open)
          // because pointsInside scans the feature index per click.
          if (!path.getPopup()) {
            this.bindPopup(path, f)
          }
          this.deps.popupTracker.track({ kind: 'osm', feature: f })
          path.openPopup()
        })
      },
    }).addTo(this.deps.map)
  }

  // Re-opens the tracked OSM popup after a render. Feature identity is stable
  // across renders because the layers reference the indexed feature objects.
  // The target is dropped when the feature is no longer rendered, so a popup
  // does not resurface when the user pans back to it later.
  reopenPopup(): void {
    const current = this.deps.popupTracker.current
    if (current?.kind !== 'osm') {
      return
    }
    let reopened = false
    this.layer?.eachLayer((layer) => {
      if ((layer as { feature?: Feature }).feature === current.feature) {
        const path = layer as L.Path
        if (!path.getPopup()) {
          this.bindPopup(path, current.feature)
        }
        path.openPopup()
        reopened = true
      }
    })
    if (!reopened) {
      this.deps.popupTracker.clear()
    }
  }

  destroy(): void {
    this.layer?.remove()
    this.layer = undefined
    this.highlightedFeature = undefined
    this.highlightedLayer = undefined
  }

  private select(feature: Feature, layer: L.Path): void {
    if (this.highlightedLayer && this.highlightedLayer !== layer) {
      this.highlightedLayer.setStyle(styleFor(this.highlightedFeature as Feature))
    }
    this.highlightedFeature = feature
    this.highlightedLayer = layer
    layer.setStyle(highlightStyleFor(layer))
    layer.bringToFront()
  }

  // Binds the tag popup and tracks it so a render can re-open it on the new
  // layer. The popupclose handler only clears the target when the user
  // closed this popup (not when the rebuild removed the layer).
  private bindPopup(path: L.Path, feature: Feature): void {
    path.bindPopup(() => osmPopupContent(feature, this.popupDeps()))
    path.on('popupclose', () => {
      this.deps.popupTracker.handleClose({ kind: 'osm', feature })
    })
  }

  private popupDeps(): PopupDependencies {
    return {
      pointsInside: (feature) => pointsInside(this.features, feature),
      ...this.deps.popupDeps,
    }
  }
}
