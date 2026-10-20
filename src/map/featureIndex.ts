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

// Buildings and points are the most numerous features and the first to be
// dropped when the visible set exceeds MAX_VISIBLE_FEATURES.
export function isMinorFeature(feature: Feature): boolean {
  return feature.geometry.type === 'Point' || 'building' in (feature.properties ?? {})
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

// Even-odd test in lon/lat; testing all rings together handles holes.
function pointInRings(lon: number, lat: number, rings: number[][][]): boolean {
  let inside = false
  for (const ring of rings) {
    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
      const [xi, yi] = ring[i]
      const [xj, yj] = ring[j]
      if ((yi > lat) !== (yj > lat) && lon < ((xj - xi) * (lat - yi)) / (yj - yi) + xi) {
        inside = !inside
      }
    }
  }
  return inside
}

// Shops and other POIs are usually separate point nodes inside the building
// way, which itself often carries only building=yes. Collect them so the
// building popup can show what is inside.
export function pointsInside(features: IndexedFeature[], feature: Feature): Feature[] {
  if (!('building' in (feature.properties ?? {}))) {
    return []
  }

  const geometry = feature.geometry
  let rings: number[][][]
  if (geometry.type === 'Polygon') {
    rings = geometry.coordinates
  } else if (geometry.type === 'MultiPolygon') {
    rings = geometry.coordinates.flat()
  } else if (geometry.type === 'LineString' && isAreaOutline(feature)) {
    rings = [geometry.coordinates]
  } else {
    return []
  }

  let minLon = Infinity
  let minLat = Infinity
  let maxLon = -Infinity
  let maxLat = -Infinity
  for (const ring of rings) {
    for (const [lon, lat] of ring) {
      minLon = Math.min(minLon, lon)
      maxLon = Math.max(maxLon, lon)
      minLat = Math.min(minLat, lat)
      maxLat = Math.max(maxLat, lat)
    }
  }

  return features
    .filter(
      (indexed) =>
        indexed.feature.geometry.type === 'Point' &&
        indexed.minLon >= minLon &&
        indexed.maxLon <= maxLon &&
        indexed.minLat >= minLat &&
        indexed.maxLat <= maxLat &&
        pointInRings(indexed.minLon, indexed.minLat, rings),
    )
    .map((indexed) => indexed.feature)
}
