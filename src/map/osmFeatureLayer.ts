import type { Feature } from 'geojson'
import * as L from 'leaflet'
import type { NodeEditTarget } from '../stores/userNodes'
import { enableAreaHitTest } from './areaHitTest'
import { bboxArea, isMinorFeature, type IndexedFeature } from './featureIndex'
import { designToken, highlightStyleFor, POINT_MARKER_OPTIONS, styleFor } from './featureStyling'
import { drawDirectionArrows, type DirectionArrowStyle } from './directionArrows'
import { MAX_VISIBLE_FEATURES } from './mapConfig'

export interface OsmFeatureLayerDeps {
  map: L.Map
  onEditNode: (target: NodeEditTarget, click: L.LatLng) => boolean
  onSelect: () => void
}

export interface OsmFeatureRenderOptions {
  // Way-direction arrowheads stay drawn unless the visitor turned them off
  // in their settings.
  showArrows?: boolean
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

  // Every rendered way's direction arrowheads, keyed by their feature. The
  // arrowheads follow their line's style: base stroke normally, primary
  // highlight while the way is selected.
  private arrowLayer: L.LayerGroup | undefined
  private arrowsByFeature: Map<Feature, L.Polyline[]> = new Map()

  constructor(deps: OsmFeatureLayerDeps) {
    this.deps = deps
  }

  setFeatures(features: IndexedFeature[]): void {
    this.features = features
  }

  render(options: OsmFeatureRenderOptions = {}): void {
    this.layer?.remove()
    this.arrowLayer?.remove()
    this.arrowsByFeature = new Map()
    this.highlightedLayer = undefined
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

    // Direction arrowheads on every rendered way stroke, drawn as a layer of
    // their own above the features so a way's OSM node order stays readable.
    // Points and areas carry no direction and get none (see the spec).
    if (options.showArrows === false) {
      return
    }
    this.arrowLayer = L.layerGroup().addTo(this.deps.map)
    for (const indexed of visible) {
      const feature = indexed.feature
      if (feature.geometry?.type !== 'LineString') {
        continue
      }
      const style = baseArrowStyleFor(feature)
      const positions = (feature.geometry.coordinates as [number, number][])
        .map((coordinate): L.LatLngTuple => [coordinate[1], coordinate[0]])
      const arrows = drawDirectionArrows(this.arrowLayer, this.deps.map, positions, style)
      if (arrows.length > 0) {
        this.arrowsByFeature.set(feature, arrows)
      }
      if (feature === this.highlightedFeature) {
        this.restyleArrows(feature, { color: designToken('--p-primary-color'), opacity: 1 })
      }
    }
  }

  destroy(): void {
    this.layer?.remove()
    this.layer = undefined
    this.arrowLayer?.remove()
    this.arrowLayer = undefined
    this.arrowsByFeature = new Map()
    this.highlightedFeature = undefined
    this.highlightedLayer = undefined
  }

  // Drops the visual selection; called when the selection moves to another
  // layer's element or is cleared (e.g. the node panel is closed).
  clearSelection(): void {
    if (this.highlightedLayer) {
      this.highlightedLayer.setStyle(styleFor(this.highlightedFeature as Feature))
    }
    if (this.highlightedFeature) {
      this.restyleArrows(this.highlightedFeature, baseArrowStyleFor(this.highlightedFeature))
    }
    this.highlightedFeature = undefined
    this.highlightedLayer = undefined
  }

  private select(feature: Feature, layer: L.Path): void {
    if (this.highlightedLayer && this.highlightedLayer !== layer) {
      this.highlightedLayer.setStyle(styleFor(this.highlightedFeature as Feature))
    }
    if (this.highlightedFeature && this.highlightedFeature !== feature) {
      this.restyleArrows(this.highlightedFeature, baseArrowStyleFor(this.highlightedFeature))
    }
    this.highlightedFeature = feature
    this.highlightedLayer = layer
    layer.setStyle(highlightStyleFor(layer))
    layer.bringToFront()
    this.restyleArrows(feature, { color: designToken('--p-primary-color'), opacity: 1 })
    this.deps.onSelect()
  }

  // A way's arrowheads follow the line: the stroke's base style normally,
  // the primary highlight while the way is selected.
  private restyleArrows(feature: Feature, style: DirectionArrowStyle): void {
    for (const arrow of this.arrowsByFeature.get(feature) ?? []) {
      arrow.setStyle({ color: style.color, weight: style.weight, opacity: style.opacity })
    }
  }
}

function baseArrowStyleFor(feature: Feature): DirectionArrowStyle {
  const lineStyle = styleFor(feature)
  return {
    color: lineStyle.color ?? '#888888',
    weight: Math.max(1.5, lineStyle.weight ?? 1),
    opacity: lineStyle.opacity ?? 1,
  }
}
