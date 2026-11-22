// Direction inheritance from a parent way: a node relation created on one
// OSM way runs in that way's direction (its coordinate order), regardless of
// the order its nodes were placed in. See
// specs/map-editing/relation-parent-direction/spec.md.

export interface DirectionalPoint {
  latitude: number
  longitude: number
}

// A planar (equirectangular) meter position around a reference latitude —
// ample for ordering points along a way.
interface Planar {
  x: number
  y: number
}

const EARTH_RADIUS = 6371008.8

function toPlanar(point: DirectionalPoint, referenceLat: number): Planar {
  return {
    x: (point.longitude * Math.PI / 180) * EARTH_RADIUS * Math.cos(referenceLat * Math.PI / 180),
    y: (point.latitude * Math.PI / 180) * EARTH_RADIUS,
  }
}

/**
 * The position of a point along a way, in meters of the way's coordinate
 * order: the distance from the way's first coordinate to the point's nearest
 * point on the polyline. Points beyond either end clamp to 0 or the total
 * length, so placements past the way's endpoints stay orderable; equal
 * offsets are broken by the caller's stable sort.
 */
export function offsetAlongWay(point: DirectionalPoint, way: [number, number][]): number {
  if (way.length < 2) {
    return 0
  }
  const referenceLat = way.reduce((sum, coordinate) => sum + coordinate[1], 0) / way.length
  const projected = toPlanar(point, referenceLat)
  let cumulative = 0
  let best: { offset: number; distance: number } | undefined
  let previous = toPlanar({ latitude: way[0]![1], longitude: way[0]![0] }, referenceLat)
  for (let i = 1; i < way.length; i++) {
    const current = toPlanar({ latitude: way[i]![1], longitude: way[i]![0] }, referenceLat)
    const dx = current.x - previous.x
    const dy = current.y - previous.y
    const lengthSquared = dx * dx + dy * dy
    if (lengthSquared > 0) {
      const along = Math.min(1, Math.max(0,
        ((projected.x - previous.x) * dx + (projected.y - previous.y) * dy) / lengthSquared))
      const nearestX = previous.x + dx * along
      const nearestY = previous.y + dy * along
      const distance = (projected.x - nearestX) ** 2 + (projected.y - nearestY) ** 2
      if (best === undefined || distance < best.distance) {
        best = { offset: cumulative + Math.sqrt(lengthSquared) * along, distance }
      }
    }
    cumulative += Math.sqrt(lengthSquared)
    previous = current
  }

  return best?.offset ?? 0
}

/**
 * The items ordered ascending along the way's coordinate order (a stable
 * sort; equal offsets keep the incoming order). One call covers the batch
 * both for the preview points and for the members of the create save.
 */
export function orderByWayDirection<T extends DirectionalPoint>(items: T[], way: [number, number][]): T[] {
  const offsets = items.map(item => offsetAlongWay(item, way))
  const order = items.map((_, index) => index)
  order.sort((a, b) => offsets[a]! - offsets[b]!)
  return order.map(index => items[index]!)
}
