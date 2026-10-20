import type { Feature } from 'geojson'
import type * as L from 'leaflet'
import { isAreaOutline } from './featureIndex'

// Leaflet internals used for hit-testing; not part of the public typings.
interface HitTestablePath {
  _containsPoint(point: L.Point): boolean
  _rings: L.Point[][]
}

// Even-odd ray casting against a projected ring, matching the test Leaflet
// applies to polygons.
function pointInRing(point: L.Point, ring: L.Point[]): boolean {
  let inside = false
  for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
    const a = ring[j]
    const b = ring[i]
    if ((b.y > point.y) !== (a.y > point.y) && point.x < ((a.x - b.x) * (point.y - b.y)) / (a.y - b.y) + b.x) {
      inside = !inside
    }
  }
  return inside
}

// Area outlines (closed ways without a highway tag) render as strokes, so
// Leaflet would only hit-test them on the stroke. This widens hit-testing to
// the enclosed area without changing how the feature is drawn.
export function enableAreaHitTest(path: L.Path, feature: Feature): void {
  if (!isAreaOutline(feature)) {
    return
  }
  const hitPath = path as unknown as HitTestablePath
  const containsOnStroke = hitPath._containsPoint.bind(hitPath)
  hitPath._containsPoint = (point) =>
    containsOnStroke(point) || (hitPath._rings.length > 0 && pointInRing(point, hitPath._rings[0]))
}
