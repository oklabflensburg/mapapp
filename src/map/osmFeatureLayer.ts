import type { Feature } from 'geojson'
import * as L from 'leaflet'
import type { NodeEditTarget } from '../stores/userNodes'
import { enableAreaHitTest } from './areaHitTest'
import { bboxArea, isMinorFeature, type IndexedFeature } from './featureIndex'
import { highlightStyleFor, POINT_MARKER_OPTIONS, styleFor } from './featureStyling'
import { MAX_VISIBLE_FEATURES } from './mapConfig'

export interface OsmFeatureLayerDeps {
  map: L.Map
  onEditNode: (target: NodeEditTarget, click: L.LatLng) => boolean
  onSelect: () => void
}

// Renders the indexed OSM features intersecting the current viewport:
// visibility filtering, draw order (large areas first), highlighting and
// click handling. The layer is rebuilt on every render, so the indexed
// feature objects are the stable identity across rebuilds.
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
      // The selected feature stays rendered when the visible set has to be
      // thinned out, so zooming never drops the selection.
      visible = visible.filter(
        (f) => !isMinorFeature(f.feature) || f.feature === this.highlightedFeature,
      )
    }

    // Draw big areas first and small features last. The canvas renderer picks
    // the topmost hit, so elements inside an area (a building in a park) stay
    // clickable instead of the surrounding area swallowing the click; it also
    // stacks small features visually above large areas.
    visible.sort((a, b) => bboxArea(b) - bboxArea(a))

    this.layer?.remove()
    this.highlightedLayer = undefined
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
          if (f.id === undefined || f.id === null) {
            return
          }
          // Clicking a feature selects it (also when it is already selected);
          // only clicking somewhere else deselects.
          const [lon, lat] =
            f.geometry.type === 'Point'
              ? (f.geometry.coordinates as [number, number])
              : [e.latlng.lng, e.latlng.lat]
          const select = this.deps.onEditNode({
            provider: 'OSM',
            providerNodeId: String(f.id),
            latitude: lat,
            longitude: lon,
            osmTags: featureProps,
          }, this.deps.map.mouseEventToLatLng(e.originalEvent))
          if (select) {
            this.select(f, path)
          }
        })
      },
    }).addTo(this.deps.map)
  }

  destroy(): void {
    this.layer?.remove()
    this.layer = undefined
    this.highlightedFeature = undefined
    this.highlightedLayer = undefined
  }

  // Drops the visual selection; called when the selection moves to another
  // layer's element or is cleared (e.g. the node panel is closed).
  clearSelection(): void {
    if (this.highlightedLayer) {
      this.highlightedLayer.setStyle(styleFor(this.highlightedFeature as Feature))
    }
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
    this.deps.onSelect()
  }
}
