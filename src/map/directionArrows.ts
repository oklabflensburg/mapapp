import * as L from 'leaflet'

// Arrowheads keep a constant on-screen size: their geometry is computed in
// projected coordinates at the current zoom and converted back to positions.
// The layers (and with them these arrows) are rebuilt on every moveend, so
// the size stays correct across zoom changes.

// Aimed spacing between arrowheads along a line, and the chevron's size, in
// screen pixels at the zoom the arrows were computed for.
const ARROW_SPACING_PX = 120
const ARROW_SIZE_PX = 9

export interface DirectionArrowStyle {
  color: string
  weight?: number
  opacity?: number
}

// Draws chevron arrowheads along the ordered positions, oriented from the
// first coordinate toward the last: one at minimum, and roughly one per
// ARROW_SPACING_PX of on-screen length otherwise, evenly distributed. The
// chevrons are small open polylines with interaction disabled, so clicks keep
// reaching the line underneath. Repeated consecutive coordinates (e.g. a
// closed way's first and last point) carry no direction and are skipped.
export function drawDirectionArrows(
  into: L.LayerGroup,
  map: L.Map,
  positions: L.LatLngExpression[],
  style: DirectionArrowStyle,
): void {
  const zoom = map.getZoom()
  const points: L.Point[] = []
  for (const position of positions) {
    const point = map.project(position, zoom)
    if (points.length === 0 || !point.equals(points[points.length - 1])) {
      points.push(point)
    }
  }
  if (points.length < 2) {
    return
  }

  const segments: { start: L.Point; end: L.Point; length: number }[] = []
  let totalLength = 0
  for (let i = 1; i < points.length; i++) {
    const start = points[i - 1]
    const end = points[i]
    const length = start.distanceTo(end)
    if (length > 0) {
      segments.push({ start, end, length })
      totalLength += length
    }
  }
  if (totalLength === 0) {
    return
  }

  const arrowCount = Math.max(1, Math.floor(totalLength / ARROW_SPACING_PX))
  for (let i = 1; i <= arrowCount; i++) {
    chevronAt(segments, (totalLength * i) / (arrowCount + 1), map, zoom, style).addTo(into)
  }
}

// Builds the chevron at the given screen distance along the segments: a
// wing-tip-wing polyline straddling the line and pointing along its local
// direction of travel.
function chevronAt(
  segments: { start: L.Point; end: L.Point; length: number }[],
  distance: number,
  map: L.Map,
  zoom: number,
  style: DirectionArrowStyle,
): L.Polyline {
  let index = 0
  let remaining = distance
  while (index < segments.length - 1 && remaining > segments[index].length) {
    remaining -= segments[index].length
    index++
  }
  const segment = segments[index]
  const t = Math.min(remaining / segment.length, 1)
  const position = segment.start.add(segment.end.subtract(segment.start).multiplyBy(t))
  const direction = segment.end.subtract(segment.start).divideBy(segment.length)
  const perpendicular = new L.Point(-direction.y, direction.x)
  const half = ARROW_SIZE_PX / 2
  const tip = position.add(direction.multiplyBy(half))
  const back = position.subtract(direction.multiplyBy(half))
  return L.polyline(
    [
      map.unproject(back.add(perpendicular.multiplyBy(half)), zoom),
      map.unproject(tip, zoom),
      map.unproject(back.subtract(perpendicular.multiplyBy(half)), zoom),
    ],
    {
      color: style.color,
      weight: style.weight ?? 2,
      opacity: style.opacity ?? 1,
      // Purely visual: an arrowhead never intercepts clicks aimed at the
      // way or relation line it decorates.
      interactive: false,
    },
  )
}
