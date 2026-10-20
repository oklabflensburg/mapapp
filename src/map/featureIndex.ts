import type { Feature, FeatureCollection, Geometry } from 'geojson'

// Precomputed bounding boxes let the renderer skip features outside the
// viewport without walking their coordinates.
export interface IndexedFeature {
  feature: Feature
  minLon: number
  minLat: number
  maxLon: number
  maxLat: number
}

function indexGeometry(geometry: Geometry, bbox: { minLon: number; minLat: number; maxLon: number; maxLat: number }): void {
  switch (geometry.type) {
    case 'Point':
      bbox.minLon = Math.min(bbox.minLon, geometry.coordinates[0])
      bbox.maxLon = Math.max(bbox.maxLon, geometry.coordinates[0])
      bbox.minLat = Math.min(bbox.minLat, geometry.coordinates[1])
      bbox.maxLat = Math.max(bbox.maxLat, geometry.coordinates[1])
      break
    case 'MultiPoint':
    case 'LineString':
      for (const [lon, lat] of geometry.coordinates) {
        bbox.minLon = Math.min(bbox.minLon, lon)
        bbox.maxLon = Math.max(bbox.maxLon, lon)
        bbox.minLat = Math.min(bbox.minLat, lat)
        bbox.maxLat = Math.max(bbox.maxLat, lat)
      }
      break
    case 'MultiPolygon':
    case 'Polygon':
      for (const ring of geometry.coordinates as number[][][]) {
        for (const [lon, lat] of ring) {
          bbox.minLon = Math.min(bbox.minLon, lon)
          bbox.maxLon = Math.max(bbox.maxLon, lon)
          bbox.minLat = Math.min(bbox.minLat, lat)
          bbox.maxLat = Math.max(bbox.maxLat, lat)
        }
      }
      break
  }
}

export function indexFeatures(collection: FeatureCollection): IndexedFeature[] {
  return collection.features.map((feature) => {
    const bbox = { minLon: Infinity, minLat: Infinity, maxLon: -Infinity, maxLat: -Infinity }
    indexGeometry(feature.geometry, bbox)
    return { feature, ...bbox }
  })
}

// Buildings are the most numerous features and the first to be dropped when
// the visible set exceeds MAX_VISIBLE_FEATURES. Point nodes stay rendered at
// every zoom so nodes inside areas (e.g. a place=square) stay selectable.
export function isMinorFeature(feature: Feature): boolean {
  return 'building' in (feature.properties ?? {})
}

export function bboxArea(feature: IndexedFeature): number {
  return (feature.maxLon - feature.minLon) * (feature.maxLat - feature.minLat)
}

// Closed ways without a highway tag enclose an area (buildings, landuse,
// natural, ...) but render as outlines only, so Leaflet would hit-test them
// on the stroke. Highway loops are paths, not areas, and stay stroke-only.
export function isAreaOutline(feature: Feature): boolean {
  if (feature.geometry.type !== 'LineString') {
    return false
  }
  const coords = feature.geometry.coordinates
  const first = coords[0]
  const last = coords[coords.length - 1]
  return (
    coords.length > 3 &&
    first[0] === last[0] &&
    first[1] === last[1] &&
    !('highway' in (feature.properties ?? {}))
  )
}
